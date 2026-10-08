<?php

declare(strict_types=1);

namespace Ankus\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Turns an expression into a Value: the strings it can hold and where any
 * unknown part comes from. This is what lets us see through
 * `$f = strrev('metsys'); $f($cmd);`.
 */
final class Evaluator
{
    private const MAX_DEPTH = 64;

    /** @var array<string, true> */
    private array $inProgress = [];

    private int $depth = 0;

    public function __construct(
        private readonly PackageIndex $index,
        private readonly string $file,
    ) {
    }

    public function eval(?Node $expr, Scope $scope): Value
    {
        if ($expr === null) {
            return Value::none();
        }
        if ($this->depth >= self::MAX_DEPTH) {
            return Value::tainted();
        }
        $this->depth++;
        try {
            return $this->doEval($expr, $scope);
        } finally {
            $this->depth--;
        }
    }

    private function doEval(Node $e, Scope $scope): Value
    {
        return match (true) {
            $e instanceof Scalar\String_ => Value::of($e->value),
            $e instanceof Scalar\Int_, $e instanceof Scalar\Float_ => Value::of((string) $e->value),
            $e instanceof Scalar\InterpolatedString => $this->interpolated($e, $scope),
            $e instanceof Node\InterpolatedStringPart => Value::of($e->value),
            $e instanceof Scalar\MagicConst\Dir => Value::of(dirname($this->file)),
            $e instanceof Scalar\MagicConst\File => Value::of($this->file),
            $e instanceof Scalar\MagicConst => Value::external(),
            $e instanceof Expr\BinaryOp\Concat => $this->eval($e->left, $scope)->concat($this->eval($e->right, $scope)),
            $e instanceof Expr\Variable => $this->variable($e, $scope),
            $e instanceof Expr\ArrayDimFetch => $this->dimFetch($e, $scope),
            $e instanceof Expr\Array_ => $this->arrayElements($e, $scope),
            $e instanceof Expr\Assign, $e instanceof Expr\AssignRef => $this->eval($e->expr, $scope),
            $e instanceof Expr\Cast\String_ => $this->eval($e->expr, $scope),
            $e instanceof Expr\Ternary => ($e->if !== null ? $this->eval($e->if, $scope) : $this->eval($e->cond, $scope))
                ->union($this->eval($e->else, $scope)),
            $e instanceof Expr\BinaryOp\Coalesce => $this->eval($e->left, $scope)->union($this->eval($e->right, $scope)),
            $e instanceof Expr\Match_ => $this->matchArms($e, $scope),
            $e instanceof Expr\FuncCall => $this->funcCall($e, $scope),
            $e instanceof Expr\MethodCall, $e instanceof Expr\NullsafeMethodCall => $this->methodCall($e, $scope),
            $e instanceof Expr\StaticCall => $this->staticCall($e, $scope),
            $e instanceof Expr\PropertyFetch, $e instanceof Expr\NullsafePropertyFetch => $this->propertyFetch($e, $scope),
            $e instanceof Expr\StaticPropertyFetch => $this->staticPropertyFetch($e, $scope),
            $e instanceof Expr\ClassConstFetch => $this->classConst($e, $scope),
            $e instanceof Expr\ConstFetch => $this->constFetch($e),
            $e instanceof Expr\Closure, $e instanceof Expr\ArrowFunction, $e instanceof Expr\New_,
            $e instanceof Expr\BinaryOp, $e instanceof Expr\BooleanNot, $e instanceof Expr\Isset_,
            $e instanceof Expr\Empty_, $e instanceof Expr\Instanceof_ => Value::none(),
            $e instanceof Expr\ShellExec, $e instanceof Expr\Include_, $e instanceof Expr\Eval_ => Value::tainted(),
            default => Value::external(),
        };
    }

    private function interpolated(Scalar\InterpolatedString $e, Scope $scope): Value
    {
        $v = Value::of('');
        foreach ($e->parts as $part) {
            $v = $v->concat($this->eval($part, $scope));
        }

        return $v;
    }

    private function variable(Expr\Variable $e, Scope $scope): Value
    {
        if (!is_string($e->name)) {
            // $$name: variable variables are a classic way to hide a call target
            return Value::tainted();
        }
        if (in_array($e->name, Sinks::SUPERGLOBALS, true)) {
            return Value::tainted();
        }
        if ($e->name === 'this') {
            return Value::none();
        }

        return $scope->get($e->name);
    }

    private function dimFetch(Expr\ArrayDimFetch $e, Scope $scope): Value
    {
        $base = $this->eval($e->var, $scope)->asPair(false);
        if ($e->var instanceof Expr\Variable && $e->var->name === 'argv') {
            return Value::tainted();
        }

        return $base;
    }

    private function arrayElements(Expr\Array_ $e, Scope $scope): Value
    {
        $v = Value::none();
        foreach ($e->items as $item) {
            $v = $v->union($this->eval($item->value, $scope));
        }
        // [$obj, 'method'] / [Foo::class, 'method']: a method callable, which
        // can never name a built-in function when called.
        $items = $e->items;
        if (count($items) === 2 && $items[0]->key === null && $items[1]->key === null
            && !$items[0]->value instanceof Node\Scalar\String_) {
            $v = $v->asPair();
        }

        return $v;
    }

    private function matchArms(Expr\Match_ $e, Scope $scope): Value
    {
        $v = Value::none();
        foreach ($e->arms as $arm) {
            $v = $v->union($this->eval($arm->body, $scope));
        }

        return $v;
    }

    private function funcCall(Expr\FuncCall $e, Scope $scope): Value
    {
        if (!$e->name instanceof Node\Name) {
            // $f(...) is a closure over whatever $f is; $f() returns data, and
            // the call itself is checked where it happens.
            return $e->isFirstClassCallable() ? $this->eval($e->name, $scope) : Value::external();
        }
        $declared = $this->declaredFunction($e->name);
        if ($declared !== null && $e->isFirstClassCallable()) {
            return Value::none();
        }
        if ($declared !== null) {
            return $this->resolveReturns('f:' . $declared, $this->index->functionReturns[$declared] ?? []);
        }
        $name = strtolower($e->name->toString());
        if ($e->isFirstClassCallable()) {
            return Value::of($name);
        }

        if (in_array($name, ['implode', 'join'], true)) {
            return $this->implode($e, $scope);
        }
        if ($name === 'array_map') {
            $fn = isset($e->args[0]) && $e->args[0] instanceof Node\Arg ? $this->eval($e->args[0]->value, $scope) : Value::none();
            foreach ($fn->strings as $f) {
                if (in_array(strtolower($f), Sinks::DECODERS, true)) {
                    // Mapping a decoder over data: whatever comes out was disguised.
                    return Value::tainted(true);
                }
            }
        }

        $args = [];
        foreach ($e->args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack) {
                return $this->unknownCall($name, [Value::tainted()]);
            }
            $args[] = $this->eval($arg->value, $scope);
        }

        if (in_array($name, Sinks::FOLDABLE, true)) {
            return $this->fold($name, $args);
        }

        return $this->unknownCall($name, $args);
    }

    /** @param list<Value> $args */
    private function unknownCall(string $name, array $args): Value
    {
        if (in_array($name, Sinks::SOURCES, true)) {
            return Value::tainted();
        }
        $v = Value::external();
        foreach ($args as $a) {
            if ($a->tainted) {
                $v = $v->withTaint()->withObfuscation($a->obfuscated);
            }
        }

        return $v;
    }

    /** @param list<Value> $args */
    private function fold(string $name, array $args): Value
    {
        $decoder = in_array($name, Sinks::DECODERS, true);
        $combos = [[]];
        foreach ($args as $arg) {
            if (!$arg->isConcrete()) {
                // Decoding something we can't see is suspicious no matter where it came from.
                if ($arg->tainted || $decoder) {
                    return Value::tainted($decoder || $arg->obfuscated);
                }

                return $arg->partial ? Value::partial($arg->obfuscated) : Value::external();
            }
            $next = [];
            foreach ($combos as $combo) {
                foreach ($arg->strings as $s) {
                    $next[] = [...$combo, $s];
                }
            }
            $combos = $next;
            if (count($combos) > Value::MAX_STRINGS) {
                return Value::tainted($decoder);
            }
        }

        $obfuscated = $decoder;
        foreach ($args as $arg) {
            $obfuscated = $obfuscated || $arg->obfuscated;
        }

        $out = [];
        foreach ($combos as $combo) {
            $r = self::callPure($name, $combo);
            if ($r === null) {
                return Value::tainted($obfuscated);
            }
            $out[] = $r;
        }

        return Value::of(...$out)->withObfuscation($obfuscated);
    }

    /**
     * Runs a pure built-in on literal arguments. Every function here is
     * side-effect free, so executing it is safe.
     *
     * @param list<string> $args
     */
    private static function callPure(string $name, array $args): ?string
    {
        set_error_handler(static fn () => true);
        try {
            $intArgs = ['chr' => [0], 'substr' => [1, 2], 'str_repeat' => [1], 'str_pad' => [1], 'base_convert' => [1, 2], 'dirname' => [1]];
            foreach ($intArgs[$name] ?? [] as $i) {
                if (isset($args[$i])) {
                    if (!is_numeric($args[$i])) {
                        return null;
                    }
                    $args[$i] = (int) $args[$i];
                }
            }
            // Keep folding cheap: no huge outputs, no decompression bombs.
            if (in_array($name, ['str_repeat', 'str_pad'], true) && ($args[1] ?? 0) > 4096) {
                return null;
            }
            if (in_array($name, ['sprintf'], true) && preg_match('/%[^a-z%]*\d{4,}/i', (string) ($args[0] ?? ''))) {
                return null;
            }
            if (in_array($name, ['gzinflate', 'gzuncompress', 'gzdecode'], true)) {
                $args = [$args[0] ?? '', 1 << 20];
            }
            // Only ever the vetted pure functions, whatever the caller passes.
            // @phpstan-ignore function.alreadyNarrowedType (gz* need ext-zlib, which may be missing)
            if (!in_array($name, Sinks::FOLDABLE, true) || !is_callable($name)) {
                return null;
            }
            $r = $name(...$args);

            // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType (several of these return false on bad input)
            return is_string($r) || is_int($r) ? (string) $r : null;
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    private function implode(Expr\FuncCall $e, Scope $scope): Value
    {
        $args = array_values(array_filter($e->args, static fn ($a) => $a instanceof Node\Arg));
        $glue = Value::of('');
        $pieces = null;
        if (count($args) === 1) {
            $pieces = $args[0]->value;
        } elseif (count($args) === 2) {
            [$g, $p] = $args[0]->value instanceof Expr\Array_ ? [$args[1]->value, $args[0]->value] : [$args[0]->value, $args[1]->value];
            $glue = $this->eval($g, $scope);
            $pieces = $p;
        }
        $pieces = $this->literalList($pieces, $scope) ?? $pieces;
        if (!$pieces instanceof Expr\Array_ || !$glue->isConcrete() || count($glue->strings) !== 1) {
            if ($pieces === null) {
                return Value::tainted();
            }
            $v = $this->eval($pieces, $scope);
            // Known pieces joined in an order we can't see: a name being assembled.
            if ($v->strings !== [] || $v->tainted) {
                return Value::tainted($v->obfuscated);
            }

            return $v->partial ? Value::partial($v->obfuscated) : Value::external();
        }
        $result = Value::of('');
        $first = true;
        foreach ($pieces->items as $item) {
            if (!$first) {
                $result = $result->concat($glue);
            }
            $result = $result->concat($this->eval($item->value, $scope));
            $first = false;
        }

        return $result;
    }

    /**
     * Rewrite array_map('chr', [104, 116, ...]) and array_reverse([...]) on
     * literal arrays into a literal array of results, so implode() can join
     * them in order.
     */
    private function literalList(?Node $e, Scope $scope): ?Expr\Array_
    {
        if (!$e instanceof Expr\FuncCall || !$e->name instanceof Node\Name) {
            return null;
        }
        $name = strtolower($e->name->toString());
        $args = $e->getRawArgs();
        if ($name === 'array_reverse' && isset($args[0]) && $args[0] instanceof Node\Arg) {
            $inner = $args[0]->value instanceof Expr\Array_ ? $args[0]->value : $this->literalList($args[0]->value, $scope);

            return $inner === null ? null : new Expr\Array_(array_reverse($inner->items));
        }
        if ($name !== 'array_map' || count($args) !== 2 || !$args[0] instanceof Node\Arg || !$args[1] instanceof Node\Arg) {
            return null;
        }
        $fn = $this->eval($args[0]->value, $scope);
        $list = $args[1]->value instanceof Expr\Array_ ? $args[1]->value : $this->literalList($args[1]->value, $scope);
        if (!$fn->isConcrete() || count($fn->strings) !== 1 || $list === null
            || !in_array(strtolower($fn->strings[0]), Sinks::FOLDABLE, true)) {
            return null;
        }
        $items = [];
        foreach ($list->items as $item) {
            $items[] = new Node\ArrayItem(new Expr\FuncCall(new Node\Name($fn->strings[0]), [new Node\Arg($item->value)]));
        }

        return new Expr\Array_($items);
    }

    private function methodCall(Expr\MethodCall|Expr\NullsafeMethodCall $e, Scope $scope): Value
    {
        if ($e->isFirstClassCallable()) {
            return Value::none(); // a closure over a method, not its return value
        }
        if ($e->var instanceof Expr\Variable && $e->var->name === 'this' && $e->name instanceof Node\Identifier && $scope->class !== null) {
            return $this->methodReturns($scope->class, strtolower($e->name->toString()));
        }

        return Value::external();
    }

    private function staticCall(Expr\StaticCall $e, Scope $scope): Value
    {
        if ($e->isFirstClassCallable()) {
            return Value::none();
        }
        $class = $this->classRef($e->class, $scope);
        if ($class !== null && $e->name instanceof Node\Identifier) {
            return $this->methodReturns($class, strtolower($e->name->toString()));
        }

        return Value::external();
    }

    private function methodReturns(string $class, string $method): Value
    {
        for ($c = $class, $i = 0; $c !== null && $i < 8; $c = $this->index->parents[$c] ?? null, $i++) {
            if (isset($this->index->methodReturns[$c][$method])) {
                return $this->resolveReturns("m:$c::$method", $this->index->methodReturns[$c][$method]);
            }
        }

        return Value::external();
    }

    private function propertyFetch(Expr\PropertyFetch|Expr\NullsafePropertyFetch $e, Scope $scope): Value
    {
        if ($e->var instanceof Expr\Variable && $e->var->name === 'this' && $e->name instanceof Node\Identifier && $scope->class !== null) {
            return $this->propertyValues($scope->class, $e->name->toString());
        }
        if (!$e->name instanceof Node\Identifier) {
            return $this->eval($e->name, $scope)->tainted ? Value::tainted() : Value::external();
        }

        return Value::external();
    }

    private function staticPropertyFetch(Expr\StaticPropertyFetch $e, Scope $scope): Value
    {
        $class = $this->classRef($e->class, $scope);
        if ($class !== null && $e->name instanceof Node\VarLikeIdentifier) {
            return $this->propertyValues($class, $e->name->toString());
        }

        return Value::external();
    }

    private function propertyValues(string $class, string $prop): Value
    {
        for ($c = $class, $i = 0; $c !== null && $i < 8; $c = $this->index->parents[$c] ?? null, $i++) {
            if (isset($this->index->properties[$c][$prop])) {
                $v = $this->resolveReturns("p:$c::$prop", $this->index->properties[$c][$prop]);

                // A property that is also set from outside the class is external in part.
                return $v->strings === [] && !$v->tainted ? Value::external() : $v;
            }
        }

        return Value::external();
    }

    private function classConst(Expr\ClassConstFetch $e, Scope $scope): Value
    {
        if (!$e->name instanceof Node\Identifier) {
            return Value::tainted();
        }
        $class = $this->classRef($e->class, $scope);
        if (strtolower($e->name->toString()) === 'class') {
            return $class !== null ? Value::of($class) : Value::external();
        }
        for ($c = $class, $i = 0; $c !== null && $i < 8; $c = $this->index->parents[$c] ?? null, $i++) {
            $entry = $this->index->classConstants[$c][$e->name->toString()] ?? null;
            if ($entry !== null) {
                return $this->resolveReturns("c:$c::" . $e->name->toString(), [$entry]);
            }
        }

        return Value::external();
    }

    private function constFetch(Expr\ConstFetch $e): Value
    {
        $name = strtolower($e->name->toString());
        if (in_array($name, ['true', 'false', 'null'], true)) {
            return Value::none();
        }
        $ns = $e->name->getAttribute('namespacedName');
        foreach (array_filter([$ns instanceof Node\Name ? strtolower($ns->toString()) : null, $name]) as $candidate) {
            if (isset($this->index->constants[$candidate])) {
                return $this->resolveReturns('k:' . $candidate, [$this->index->constants[$candidate]]);
            }
        }
        if (in_array($name, ['php_eol', 'directory_separator'], true)) {
            return Value::of(constant(strtoupper($name)));
        }

        return Value::external();
    }

    /** @param list<array{Expr, ?string}> $entries */
    private function resolveReturns(string $key, array $entries): Value
    {
        if ($entries === []) {
            return Value::none();
        }
        if (isset($this->inProgress[$key])) {
            return Value::external();
        }
        $this->inProgress[$key] = true;
        try {
            $v = Value::none();
            foreach ($entries as [$expr, $class]) {
                $v = $v->union($this->eval($expr, new Scope($class, $key)));
            }

            return $v;
        } finally {
            unset($this->inProgress[$key]);
        }
    }

    public function classRef(Node $class, Scope $scope): ?string
    {
        if (!$class instanceof Node\Name) {
            return null;
        }
        $name = strtolower($class->toString());
        if (in_array($name, ['self', 'static'], true)) {
            return $scope->class;
        }
        if ($name === 'parent') {
            return $scope->class !== null ? ($this->index->parents[$scope->class] ?? null) : null;
        }

        return ltrim($name, '\\');
    }

    /** FQN of a package-declared function this call refers to, if any. */
    public function declaredFunction(Node\Name $name): ?string
    {
        $ns = $name->getAttribute('namespacedName');
        if ($ns instanceof Node\Name && isset($this->index->functions[strtolower($ns->toString())])) {
            return strtolower($ns->toString());
        }
        $plain = strtolower(ltrim($name->toString(), '\\'));

        return isset($this->index->functions[$plain]) ? $plain : null;
    }
}
