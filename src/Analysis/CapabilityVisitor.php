<?php

declare(strict_types=1);

namespace Ankus\Analysis;

use Ankus\Capability as C;
use Ankus\Finding;
use Ankus\PackageResult;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Second pass over one file: walks the code in source order, tracks
 * variable values per function, and records a Finding for every sink.
 */
final class CapabilityVisitor extends NodeVisitorAbstract
{
    /** @var list<Scope> */
    private array $scopes = [];

    /** @var list<?string> lowercase, for lookups */
    private array $classes = [];

    /** @var list<string> original case, for evidence */
    private array $classNames = [];

    private Evaluator $eval;

    /** @var array<string, array{Stmt\ClassMethod, string, string}> "class::method" => [node, class, display name] in this file */
    private array $methods = [];

    /** How deep calls may be followed with bound arguments. */
    private const MAX_REPLAY_DEPTH = 2;

    /**
     * @param list<int> $replayStack object ids of functions being replayed, to stop recursion
     * @param array<string, array{Stmt\ClassMethod, string, string}> $methods
     */
    public function __construct(
        private readonly PackageIndex $index,
        private readonly PackageResult $result,
        private readonly string $absFile,
        private readonly string $relFile,
        private readonly ?Scope $initialScope = null,
        private readonly ?string $initialClass = null,
        private readonly string $initialClassName = '',
        private readonly int $replayDepth = 0,
        private readonly array $replayStack = [],
        array $methods = [],
    ) {
        $this->eval = new Evaluator($index, $absFile);
        $this->methods = $methods;
    }

    public function beforeTraverse(array $nodes): null
    {
        $this->scopes = [$this->initialScope ?? new Scope(null, '<top-level>')];
        $this->classes = $this->initialClass !== null ? [$this->initialClass] : [];
        $this->classNames = $this->initialClass !== null ? [$this->initialClassName] : [];
        if ($this->replayDepth === 0) {
            $this->methods = [];
            foreach ((new NodeFinder())->findInstanceOf($nodes, Stmt\ClassLike::class) as $class) {
                $lower = PackageIndex::className($class);
                if ($lower === null) {
                    continue;
                }
                $display = $class->namespacedName?->toString() ?? (string) $class->name;
                foreach ($class->getMethods() as $method) {
                    $this->methods[$lower . '::' . strtolower($method->name->toString())] = [$method, $lower, $display];
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): null
    {
        $scope = $this->scope();

        if ($node instanceof Stmt\ClassLike) {
            $this->classes[] = PackageIndex::className($node);
            $this->classNames[] = $node->namespacedName?->toString() ?? $node->name?->toString() ?? 'class@anonymous';
        } elseif ($node instanceof Node\FunctionLike) {
            $this->enterFunction($node, $scope);
        } elseif (self::isLoop($node)) {
            $scope->loopDepth++;
            $scope->branchDepth++;
        } elseif (self::isBranch($node)) {
            $scope->branchDepth++;
        }

        // Re-read: entering a function pushes a new scope.
        $scope = $this->scope();

        match (true) {
            $node instanceof Stmt\Foreach_ => $this->foreachValue($node, $scope),
            $node instanceof Expr\FuncCall => $this->funcCall($node, $scope),
            $node instanceof Expr\New_ => $this->newObject($node, $scope),
            $node instanceof Expr\StaticCall => $this->staticCall($node, $scope),
            $node instanceof Expr\MethodCall, $node instanceof Expr\NullsafeMethodCall => $this->methodCall($node, $scope),
            $node instanceof Expr\Eval_ => $this->evalExpr($node, $scope),
            $node instanceof Expr\ShellExec => $this->add(C::Exec, '`backticks`', $node),
            $node instanceof Expr\Include_ => $this->include($node, $scope),
            $node instanceof Expr\Variable => $this->variable($node),
            $node instanceof Expr\ArrayDimFetch => $this->serverEnv($node),
            $node instanceof Node\Scalar\String_ => $this->sensitivePath(Value::of($node->value), $node, 'string literal'),
            default => null,
        };

        return null;
    }

    public function leaveNode(Node $node): null
    {
        // Assignments take effect after their right-hand side has been
        // visited, so `$r = $r($x)` checks the call against the old $r.
        if ($node instanceof Expr\Assign) {
            $this->assign($node, $this->scope());
        } elseif ($node instanceof Expr\AssignOp\Concat) {
            $this->concatAssign($node, $this->scope());
        }

        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classes);
            array_pop($this->classNames);
        } elseif ($node instanceof Node\FunctionLike) {
            array_pop($this->scopes);
        } elseif (self::isLoop($node)) {
            $this->scope()->loopDepth--;
            $this->scope()->branchDepth--;
        } elseif (self::isBranch($node)) {
            $this->scope()->branchDepth--;
        }

        return null;
    }

    // ---- scopes -------------------------------------------------------

    private function scope(): Scope
    {
        return $this->scopes[array_key_last($this->scopes)];
    }

    private function currentClass(): ?string
    {
        $c = end($this->classes);

        return $c === false ? null : $c;
    }

    private function enterFunction(Node\FunctionLike $fn, Scope $parent): void
    {
        $class = $this->currentClass();
        if ($fn instanceof Stmt\Function_) {
            $context = ($fn->namespacedName?->toString() ?? $fn->name->toString()) . '()';
            $scope = $parent->child($class, $context, false);
        } elseif ($fn instanceof Stmt\ClassMethod) {
            $context = (end($this->classNames) ?: 'class@anonymous') . '::' . $fn->name->toString() . '()';
            $scope = $parent->child($class, $context, false);
        } elseif ($fn instanceof Expr\ArrowFunction) {
            $scope = $parent->child($class, $parent->context . ' {fn}', true);
        } else {
            $captured = [];
            foreach ($fn instanceof Expr\Closure ? $fn->uses : [] as $use) {
                if (is_string($use->var->name)) {
                    $captured[] = $use->var->name;
                }
            }
            $scope = $parent->child($class, $parent->context . ' {closure}', false, $captured);
        }
        // Parameters are caller-provided; leaving them unset makes them external.
        $this->scopes[] = $scope;
    }

    private static function isLoop(Node $n): bool
    {
        return $n instanceof Stmt\For_ || $n instanceof Stmt\Foreach_ || $n instanceof Stmt\While_ || $n instanceof Stmt\Do_;
    }

    private static function isBranch(Node $n): bool
    {
        return $n instanceof Stmt\If_ || $n instanceof Stmt\ElseIf_ || $n instanceof Stmt\Else_
            || $n instanceof Stmt\Case_ || $n instanceof Stmt\Catch_ || $n instanceof Expr\Ternary
            || $n instanceof Expr\Match_;
    }

    // ---- value tracking -----------------------------------------------

    private function assign(Expr\Assign $node, Scope $scope): void
    {
        if (!$node->var instanceof Expr\Variable || !is_string($node->var->name)) {
            return;
        }
        $name = $node->var->name;
        $value = $this->eval->eval($node->expr, $scope);
        // $s = $s . $c inside a loop: accumulation we can't model statically.
        if ($scope->loopDepth > 0 && self::buildsStringFrom($node->expr, $name)) {
            $value = $value->withTaint();
        }
        $scope->assign($name, $value);
        if ($node->expr instanceof Expr\Closure || $node->expr instanceof Expr\ArrowFunction) {
            $scope->closures[$name] = [$node->expr, $scope];
        }
    }

    private function concatAssign(Expr\AssignOp\Concat $node, Scope $scope): void
    {
        if (!$node->var instanceof Expr\Variable || !is_string($node->var->name)) {
            return;
        }
        $name = $node->var->name;
        $value = $scope->get($name)->concat($this->eval->eval($node->expr, $scope));
        if ($scope->loopDepth > 0) {
            $value = $value->withTaint();
        }
        $scope->assign($name, $value);
    }

    private function foreachValue(Stmt\Foreach_ $node, Scope $scope): void
    {
        if ($node->valueVar instanceof Expr\Variable && is_string($node->valueVar->name)) {
            $scope->assign($node->valueVar->name, $this->eval->eval($node->expr, $scope));
        }
    }

    /** Does $expr join strings using the current value of $name? */
    private static function buildsStringFrom(Node $expr, string $name): bool
    {
        if ($expr instanceof Expr\BinaryOp\Concat || $expr instanceof Node\Scalar\InterpolatedString) {
            return self::mentionsVariable($expr, $name);
        }
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), Sinks::FOLDABLE, true)) {
            return self::mentionsVariable($expr, $name);
        }

        return false;
    }

    private static function mentionsVariable(Node $expr, string $name): bool
    {
        if ($expr instanceof Expr\Variable && $expr->name === $name) {
            return true;
        }
        foreach ($expr->getSubNodeNames() as $sub) {
            $child = $expr->$sub;
            foreach (is_array($child) ? $child : [$child] as $c) {
                if ($c instanceof Node && self::mentionsVariable($c, $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---- sinks --------------------------------------------------------

    private function funcCall(Expr\FuncCall $node, Scope $scope): void
    {
        if (!$node->name instanceof Node\Name) {
            if ($node->name instanceof Expr\Variable && is_string($node->name->name) && isset($scope->closures[$node->name->name])) {
                [$closure, $defining] = $scope->closures[$node->name->name];
                $this->replay($closure, $node, $scope, $this->absFile, $this->relFile, $this->currentClass(), (string) end($this->classNames), $defining);

                return;
            }
            $this->callable($this->eval->eval($node->name, $scope), $node, $scope, 'dynamic call');

            return;
        }
        $declared = $this->eval->declaredFunction($node->name);
        if ($declared !== null) {
            if (isset($this->index->functionNodes[$declared])) {
                [$fn, $abs, $rel] = $this->index->functionNodes[$declared];
                $this->replay($fn, $node, $scope, $abs, $rel, null, '', null);
            }

            return;
        }
        $name = $node->name->toString();
        if ($node->name->isFullyQualified() || $node->name->isQualified()) {
            if (str_contains(ltrim($name, '\\'), '\\')) {
                return; // a function in some other namespace, not a built-in
            }
        }
        $this->builtinCall(strtolower(ltrim($name, '\\')), $node, $scope, '');
    }

    /** A call to a global built-in, either direct or resolved from a dynamic name. */
    private function builtinCall(string $name, Expr\FuncCall|Expr\New_ $node, Scope $scope, string $via, bool $obfuscated = false): void
    {
        $args = $node instanceof Expr\FuncCall && !$node->isFirstClassCallable() ? $node->getArgs() : [];
        $note = $via !== '' ? "$via resolved to $name()" : '';

        foreach (Sinks::FUNCTIONS[$name] ?? [] as $cap) {
            $this->add($cap, $name . '()', $node, $note);
        }
        if ($obfuscated && (isset(Sinks::FUNCTIONS[$name]) || isset(Sinks::CALLABLE_ARGS[$name]))) {
            $this->add(C::Obfuscation, $name . '()', $node, 'call target was encoded or constructed');
        }

        // A literal deliberately encoded in source and fed to a sink: getenv(base64_decode('R0lUSFVC...')).
        if (isset(Sinks::FUNCTIONS[$name]) && isset($args[0])) {
            $first = $this->eval->eval($args[0]->value, $scope);
            if ($first->obfuscated && $first->isConcrete()) {
                $this->add(C::Obfuscation, $name . '()', $node, 'argument is an encoded literal: ' . self::shorten($first->strings[0]));
            }
        }

        if (isset(Sinks::URL_ARG_FUNCTIONS[$name])) {
            $arg = $args[Sinks::URL_ARG_FUNCTIONS[$name]] ?? null;
            if ($arg !== null) {
                $this->urlArgument($this->eval->eval($arg->value, $scope), $name, $node);
            }
        }
        if ($name === 'curl_init' && isset($args[0])) {
            $url = $this->eval->eval($args[0]->value, $scope);
            // Its first argument is already checked as an encoded literal above.
            $this->urlArgument($url->isConcrete() ? $url->withoutObfuscation() : $url, $name, $node, false);
        }
        if ($name === 'curl_setopt' && isset($args[1], $args[2]) && self::isConst($args[1]->value, 'CURLOPT_URL')) {
            $this->urlArgument($this->eval->eval($args[2]->value, $scope), $name, $node, false);
        }
        if ($name === 'curl_setopt_array' && isset($args[1]) && $args[1]->value instanceof Expr\Array_) {
            foreach ($args[1]->value->items as $item) {
                if ($item !== null && $item->key !== null && self::isConst($item->key, 'CURLOPT_URL')) {
                    $this->urlArgument($this->eval->eval($item->value, $scope), $name, $node, false);
                }
            }
        }
        if ($name === 'fopen') {
            $this->fileMode(isset($args[1]) ? $this->eval->eval($args[1]->value, $scope) : Value::of('r'), 'fopen()', $node);
        }
        if (in_array($name, ['preg_replace', 'preg_filter'], true) && isset($args[0])) {
            foreach ($this->eval->eval($args[0]->value, $scope)->strings as $pattern) {
                if (preg_match('/^(.).*\1([a-zA-Z]*)$/s', $pattern, $m) && str_contains($m[2], 'e')) {
                    $this->add(C::CodeEval, $name . '()', $node, 'regex with /e modifier');
                }
            }
        }
        foreach (Sinks::CALLABLE_ARGS[$name] ?? [] as $pos) {
            $arg = $pos === -1 ? ($args === [] ? null : $args[array_key_last($args)]) : ($args[$pos] ?? null);
            if ($arg !== null) {
                $this->callable($this->eval->eval($arg->value, $scope), $node, $scope, $name . '()');
            }
        }
    }

    /** Something is about to be called; figure out what. */
    private function callable(Value $v, Expr $node, Scope $scope, string $via): void
    {
        if ($v->pair) {
            if ($v->tainted) {
                $this->result->callbacks++;
            }
            return;
        }
        // ['FFI', 'cdef'] and 'FFI::cdef' are the one built-in static callable with a capability.
        foreach ($v->strings as $s) {
            $s = strtolower(ltrim(trim($s), '\\'));
            if ($s === 'ffi' || str_starts_with($s, 'ffi::')) {
                $this->add(C::Native, 'FFI', $node, "callable passed to $via");
                break;
            }
        }
        foreach ($v->strings as $s) {
            $s = strtolower(ltrim(trim($s), '\\'));
            if ($s === '' || str_contains($s, '::') || str_contains($s, '\\')) {
                continue; // method or namespaced function: not a built-in sink
            }
            if (isset($this->index->functions[$s])) {
                continue;
            }
            if (Sinks::isFunctionSink($s) || $node instanceof Expr\FuncCall) {
                if ($node instanceof Expr\FuncCall && !$node->name instanceof Node\Name) {
                    $this->builtinCall($s, $node, $scope, $via, $v->obfuscated);
                } else {
                    foreach (Sinks::FUNCTIONS[$s] ?? [] as $cap) {
                        $this->add($cap, $s . '()', $node, "callable passed to $via");
                    }
                    if ($v->obfuscated && Sinks::isFunctionSink($s)) {
                        $this->add(C::Obfuscation, $s . '()', $node, 'callable name was encoded or constructed');
                    }
                }
            }
        }
        if ($v->tainted || ($v->partial && self::couldNameFunction($v->fragments))) {
            $this->add(C::DynamicUnresolved, $via, $node, 'call target built from input we could not resolve');
            if ($v->obfuscated) {
                $this->add(C::Obfuscation, $via, $node, 'call target passes through a decoder');
            }
        } elseif ($v->external) {
            $this->result->callbacks++;
        }
    }

    /**
     * A partial string can only name a built-in function if every known
     * piece of it could be part of an identifier: 'sys' . $x can,
     * 'What is ' . $x and $class . '::__isset' cannot.
     *
     * @param list<string> $fragments
     */
    private static function couldNameFunction(array $fragments): bool
    {
        foreach ($fragments as $f) {
            if (!preg_match('/^[A-Za-z0-9_\x80-\xff\\\\]*$/', $f)) {
                return false;
            }
        }

        return true;
    }

    private static function isConst(Node $expr, string $name): bool
    {
        return $expr instanceof Expr\ConstFetch && strcasecmp(ltrim($expr->name->toString(), '\\'), $name) === 0;
    }

    /** @param bool $mayBeFile false for arguments that are always URLs (cURL), true for stream functions */
    private function urlArgument(Value $v, string $fn, Node $node, bool $mayBeFile = true): void
    {
        $isUrl = false;
        foreach ([...$v->strings, ...$v->prefixes] as $s) {
            foreach (Sinks::NETWORK_SCHEMES as $scheme) {
                if (stripos(ltrim($s), $scheme) === 0) {
                    $this->add(C::Network, $fn . '()', $node, 'URL argument: ' . self::shorten($s));
                    $isUrl = true;
                    break;
                }
            }
        }
        if ($v->tainted && !$isUrl && $mayBeFile) {
            $this->add(C::Network, $fn . '()', $node, 'path built from unresolvable input; may be a URL');
        }
        // A concrete first argument was already reported as an encoded literal.
        if ($v->obfuscated && !($v->isConcrete() && $mayBeFile && isset(Sinks::FUNCTIONS[$fn]) && (Sinks::URL_ARG_FUNCTIONS[$fn] ?? -1) === 0)) {
            $this->add(C::Obfuscation, $fn . '()', $node, 'path or URL was encoded or constructed');
        }
        $this->sensitivePath($v, $node, $fn . '()');
    }

    private function fileMode(Value $mode, string $sink, Node $node): void
    {
        if (!$mode->isConcrete()) {
            $this->add(C::FileRead, $sink, $node);
            $this->add(C::FileWrite, $sink, $node, 'mode not statically known');

            return;
        }
        $write = false;
        foreach ($mode->strings as $m) {
            $write = $write || strpbrk($m, 'waxc+') !== false;
        }
        $this->add($write ? C::FileWrite : C::FileRead, $sink, $node);
    }

    private function newObject(Expr\New_ $node, Scope $scope): void
    {
        $args = $node->getArgs();
        if ($node->class instanceof Node\Name) {
            $class = strtolower(ltrim($node->class->toString(), '\\'));
        } elseif ($node->class instanceof Stmt\Class_) {
            return;
        } else {
            $v = $this->eval->eval($node->class, $scope);
            // Instantiating a class can't reach a built-in function, so only
            // genuinely tainted names count here, not 'App\\Models\\' . $name.
            if ($v->tainted) {
                $this->add(C::DynamicUnresolved, 'new $class', $node, 'class name built from input we could not resolve');
            }
            foreach ($v->strings as $s) {
                $this->classSink(strtolower(ltrim($s, '\\')), $args, $node, $scope);
            }

            return;
        }
        $this->classSink($class, $args, $node, $scope);
    }

    /** @param list<Node\Arg> $args */
    private function classSink(string $class, array $args, Expr\New_ $node, Scope $scope): void
    {
        foreach (Sinks::CLASSES[$class] ?? [] as $cap) {
            $this->add($cap, "new $class", $node);
        }
        if ($class === 'splfileobject') {
            $this->fileMode(isset($args[1]) ? $this->eval->eval($args[1]->value, $scope) : Value::of('r'), 'new SplFileObject', $node);
        }
        if (in_array($class, ['reflectionfunction', 'closure'], true) && isset($args[0])) {
            $this->callable($this->eval->eval($args[0]->value, $scope), $node, $scope, "new $class");
        }
    }

    private function staticCall(Expr\StaticCall $node, Scope $scope): void
    {
        $class = $node->class instanceof Node\Name ? strtolower(ltrim($node->class->toString(), '\\')) : null;
        if ($class === null && $this->eval->eval($node->class, $scope)->tainted) {
            $this->add(C::DynamicUnresolved, '$class::method()', $node, 'class built from input we could not resolve');
        }
        if (!$node->name instanceof Node\Identifier) {
            if ($this->eval->eval($node->name, $scope)->tainted) {
                $this->add(C::DynamicUnresolved, 'static method', $node, 'method name built from input we could not resolve');
            }

            return;
        }
        $method = strtolower($node->name->toString());
        if (in_array($class, ['self', 'static'], true)) {
            $this->replayMethod($this->currentClass(), $method, $node, $scope);
        }
        foreach (Sinks::STATIC_METHODS["$class::$method"] ?? [] as $cap) {
            $this->add($cap, "$class::$method()", $node);
        }
        if ($class === 'closure' && $method === 'fromcallable' && isset($node->getArgs()[0])) {
            $this->callable($this->eval->eval($node->getArgs()[0]->value, $scope), $node, $scope, 'Closure::fromCallable()');
        }
    }

    /**
     * Dynamic method names are deliberately not flagged: every method in the
     * package is analyzed whether or not it is reachable, so calling one by
     * a computed name can't hide a capability. Built-in objects get their
     * capability where they are constructed.
     */
    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $node, Scope $scope): void
    {
        if ($node->var instanceof Expr\Variable && $node->var->name === 'this' && $node->name instanceof Node\Identifier) {
            $this->replayMethod($this->currentClass(), $node->name->toString(), $node, $scope);
        }
    }

    private function replayMethod(?string $class, string $method, Expr\CallLike $node, Scope $scope): void
    {
        $entry = $class !== null ? ($this->methods[$class . '::' . strtolower($method)] ?? null) : null;
        if ($entry !== null) {
            [$fn, $cls, $display] = $entry;
            $this->replay($fn, $node, $scope, $this->absFile, $this->relFile, $cls, $display, null);
        }
    }

    /**
     * Follow a call into a function body with the caller's argument values
     * bound to its parameters, so `$fetch('https://...')` is checked where
     * the URL actually reaches file_get_contents(). Only done when an
     * argument is worth following (a URL, a sink name, a credential path,
     * or anything encoded), which keeps it cheap on ordinary code.
     */
    private function replay(
        Node\FunctionLike $fn,
        Expr\CallLike $call,
        Scope $scope,
        string $abs,
        string $rel,
        ?string $class,
        string $className,
        ?Scope $defining,
    ): void {
        if ($this->replayDepth >= self::MAX_REPLAY_DEPTH || $call->isFirstClassCallable()
            || in_array(spl_object_id($fn), $this->replayStack, true)) {
            return;
        }
        $args = [];
        $worthIt = false;
        foreach ($call->getArgs() as $arg) {
            if ($arg->unpack || $arg->name !== null) {
                break;
            }
            $v = $this->eval->eval($arg->value, $scope);
            $args[] = $v;
            $worthIt = $worthIt || self::worthFollowing($v);
        }
        if (!$worthIt) {
            return;
        }

        $context = ($fn instanceof Stmt\Function_ ? ($fn->namespacedName?->toString() ?? $fn->name->toString()) . '()'
            : ($fn instanceof Stmt\ClassMethod ? $className . '::' . $fn->name->toString() . '()' : $scope->context . ' {closure}'))
            . ' called from line ' . $call->getStartLine();
        if ($fn instanceof Expr\ArrowFunction) {
            $inner = ($defining ?? $scope)->child($class, $context, true);
        } elseif ($fn instanceof Expr\Closure) {
            $uses = array_values(array_filter(array_map(static fn ($u) => is_string($u->var->name) ? $u->var->name : null, $fn->uses)));
            $inner = ($defining ?? $scope)->child($class, $context, false, $uses);
        } else {
            $inner = new Scope($class, $context);
        }
        foreach ($fn->getParams() as $i => $param) {
            if ($param->variadic || !isset($args[$i])) {
                break;
            }
            if ($param->var instanceof Expr\Variable && is_string($param->var->name)) {
                $inner->bind($param->var->name, $args[$i]);
            }
        }

        $visitor = new self(
            $this->index, $this->result, $abs, $rel, $inner, $class, $className,
            $this->replayDepth + 1, [...$this->replayStack, spl_object_id($fn)],
            $abs === $this->absFile ? $this->methods : [],
        );
        (new NodeTraverser($visitor))->traverse($fn->getStmts() ?? []);
    }

    private static function worthFollowing(Value $v): bool
    {
        if ($v->obfuscated) {
            return true;
        }
        foreach ([...$v->strings, ...$v->prefixes] as $s) {
            $lower = strtolower(ltrim($s));
            foreach (Sinks::NETWORK_SCHEMES as $scheme) {
                if (str_starts_with($lower, $scheme)) {
                    return true;
                }
            }
            if (Sinks::isFunctionSink($lower)) {
                return true;
            }
            foreach (Sinks::SENSITIVE_PATHS as $needle) {
                if (stripos($s, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function evalExpr(Expr\Eval_ $node, Scope $scope): void
    {
        $this->add(C::CodeEval, 'eval', $node);
        $v = $this->eval->eval($node->expr, $scope);
        if ($v->obfuscated) {
            $this->add(C::Obfuscation, 'eval', $node, 'evaluated code was encoded');
        }
    }

    private function include(Expr\Include_ $node, Scope $scope): void
    {
        $v = $this->eval->eval($node->expr, $scope);
        $kind = match ($node->type) {
            Expr\Include_::TYPE_INCLUDE => 'include',
            Expr\Include_::TYPE_INCLUDE_ONCE => 'include_once',
            Expr\Include_::TYPE_REQUIRE => 'require',
            default => 'require_once',
        };
        foreach ([...$v->strings, ...$v->prefixes] as $i => $path) {
            foreach (Sinks::CODE_WRAPPERS as $wrapper) {
                if (stripos($path, $wrapper) === 0) {
                    $this->add(C::CodeEval, $kind, $node, 'includes from stream wrapper ' . $wrapper);
                    if (str_contains($wrapper, '://') && $wrapper !== 'php://input' && $wrapper !== 'php://filter') {
                        $this->add(C::Network, $kind, $node, 'includes remote code');
                    }
                    continue 2;
                }
            }
            if ($i >= count($v->strings)) {
                continue; // only a prefix: the extension isn't known
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext !== '' && !in_array($ext, Sinks::PHP_EXTENSIONS, true)) {
                $this->add(C::Obfuscation, $kind, $node, "includes a .$ext file as PHP: " . self::shorten($path));
            }
        }
        if ($v->isUnknown()) {
            $this->add(C::DynamicInclude, $kind, $node);
        }
        if ($v->obfuscated) {
            $this->add(C::Obfuscation, $kind, $node, 'included path was encoded or constructed');
        }
    }

    private function variable(Expr\Variable $node): void
    {
        if ($node->name === '_ENV') {
            $this->add(C::Env, '$_ENV', $node);
        }
    }

    private function serverEnv(Expr\ArrayDimFetch $node): void
    {
        if ($node->var instanceof Expr\Variable && $node->var->name === '_SERVER'
            && $node->dim instanceof Node\Scalar\String_
            && preg_match('/TOKEN|SECRET|PASSWORD|PASSWD|API_?KEY|PRIVATE|CREDENTIAL|^AWS_|^GH_|^GITHUB_|^NPM_|^COMPOSER_AUTH/i', $node->dim->value)) {
            $this->add(C::Env, '$_SERVER', $node, 'reads secret-looking variable ' . $node->dim->value);
        }
    }

    private function sensitivePath(Value $v, Node $node, string $sink): void
    {
        foreach ($v->strings as $s) {
            if (strlen($s) > 512) {
                continue;
            }
            foreach (Sinks::SENSITIVE_PATHS as $needle) {
                if (stripos($s, $needle) !== false) {
                    $this->add(C::SensitivePath, $sink, $node, 'mentions ' . $needle);

                    return;
                }
            }
        }
    }

    private function add(C $cap, string $sink, Node $node, string $note = ''): void
    {
        $this->result->add(new Finding($cap, $sink, $this->relFile, $node->getStartLine(), $this->scope()->context, $note));
    }

    private static function shorten(string $s): string
    {
        $s = preg_replace('/[^\x20-\x7e]/', '?', $s) ?? '';

        return strlen($s) > 80 ? substr($s, 0, 77) . '...' : $s;
    }
}
