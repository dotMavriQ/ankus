<?php

declare(strict_types=1);

namespace Ankus\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

/**
 * Package-wide declarations, collected in a first pass so the second pass
 * can resolve values across files: constants, function and method return
 * expressions, property values, and which functions the package declares
 * itself (so a namespaced exec() is not mistaken for the built-in).
 */
final class PackageIndex extends NodeVisitorAbstract
{
    /** @var array<string, true> lowercase FQN of functions declared in the package */
    public array $functions = [];

    /** @var array<string, list<array{Expr, ?string}>> function FQN => return exprs with class context */
    public array $functionReturns = [];

    /** @var array<string, array<string, list<array{Expr, ?string}>>> class => method => returns */
    public array $methodReturns = [];

    /** @var array<string, array<string, array{Expr, ?string}>> class => const => expr */
    public array $classConstants = [];

    /** @var array<string, array{Expr, ?string}> global constant name => expr */
    public array $constants = [];

    /** @var array<string, array<string, list<array{Expr, ?string}>>> class => property => assigned exprs */
    public array $properties = [];

    /** @var array<string, string> class => parent class (lowercase FQN) */
    public array $parents = [];

    /** @var array<string, array{Stmt\Function_, string, string}> function FQN => [node, abs file, rel file] */
    public array $functionNodes = [];

    /** @var array<string, true> lowercase FQN of classes, interfaces, traits and enums declared here */
    public array $classes = [];

    /** @var array<string, true> "class::method" for every method declared here */
    public array $methods = [];

    /** @var array<string, array<string, list<string>>> class => property => declared classes (lowercase FQN) */
    public array $propertyTypes = [];

    /** Set by the analyzer before each file is traversed. */
    public string $absFile = '';
    public string $relFile = '';

    /** @var list<?string> */
    private array $classStack = [];

    /** @var list<array{string, string}|array{string}|null> [kind, name...] for the current function */
    private array $functionStack = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike) {
            $class = self::className($node);
            $this->classStack[] = $class;
            if ($class !== null) {
                $this->classes[$class] = true;
            }
            if ($class !== null && $node instanceof Stmt\Class_ && $node->extends !== null) {
                $this->parents[$class] = strtolower($node->extends->toString());
            }
        } elseif ($node instanceof Stmt\Function_) {
            $fqn = strtolower($node->namespacedName?->toString() ?? $node->name->toString());
            $this->functions[$fqn] = true;
            $this->functionNodes[$fqn] = [$node, $this->absFile, $this->relFile];
            $this->functionStack[] = ['function', $fqn];
        } elseif ($node instanceof Stmt\ClassMethod) {
            $this->functionStack[] = ['method', strtolower($node->name->toString())];
            $class = $this->currentClass();
            if ($class !== null) {
                $this->methods[$class . '::' . strtolower($node->name->toString())] = true;
                // Promoted constructor parameters are typed properties too.
                foreach ($node->params as $param) {
                    if ($param->flags !== 0 && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                        $this->propertyTypes[$class][$param->var->name] = self::typeClasses($param->type, $class);
                    }
                }
            }
        } elseif ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            $this->functionStack[] = null;
        } elseif ($node instanceof Stmt\Return_ && $node->expr !== null) {
            $this->recordReturn($node->expr);
        } elseif ($node instanceof Stmt\ClassConst) {
            $class = $this->currentClass();
            if ($class !== null) {
                foreach ($node->consts as $const) {
                    $this->classConstants[$class][$const->name->toString()] = [$const->value, $class];
                }
            }
        } elseif ($node instanceof Stmt\Const_) {
            foreach ($node->consts as $const) {
                $name = $const->namespacedName?->toString() ?? $const->name->toString();
                $this->constants[strtolower($name)] = [$const->value, null];
            }
        } elseif ($node instanceof Stmt\Property) {
            $class = $this->currentClass();
            if ($class !== null) {
                foreach ($node->props as $prop) {
                    $this->propertyTypes[$class][$prop->name->toString()] = self::typeClasses($node->type, $class);
                    if ($prop->default !== null) {
                        $this->properties[$class][$prop->name->toString()][] = [$prop->default, $class];
                    }
                }
            }
        } elseif ($node instanceof Expr\Assign) {
            $this->recordAssign($node);
        } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name
            && strtolower($node->name->toString()) === 'define'
            && isset($node->args[0], $node->args[1])
            && $node->args[0] instanceof Node\Arg && $node->args[1] instanceof Node\Arg
            && $node->args[0]->value instanceof Node\Scalar\String_) {
            $this->constants[strtolower($node->args[0]->value->value)] = [$node->args[1]->value, $this->currentClass()];
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\ClassLike) {
            array_pop($this->classStack);
        } elseif ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod
            || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
            array_pop($this->functionStack);
        }

        return null;
    }

    public function beforeTraverse(array $nodes): null
    {
        $this->classStack = [];
        $this->functionStack = [];

        return null;
    }

    private function recordReturn(Expr $expr): void
    {
        $fn = end($this->functionStack);
        if ($fn === false || $fn === null) {
            return;
        }
        $class = $this->currentClass();
        if ($fn[0] === 'function') {
            $this->functionReturns[$fn[1]][] = [$expr, $class];
        } elseif ($class !== null) {
            $this->methodReturns[$class][$fn[1]][] = [$expr, $class];
        }
    }

    private function recordAssign(Expr\Assign $node): void
    {
        $class = $this->currentClass();
        if ($class === null) {
            return;
        }
        $var = $node->var;
        $isThis = $var instanceof Expr\PropertyFetch && $var->var instanceof Expr\Variable && $var->var->name === 'this';
        $isSelf = $var instanceof Expr\StaticPropertyFetch && $var->class instanceof Node\Name
            && in_array(strtolower($var->class->toString()), ['self', 'static'], true);
        if (($isThis || $isSelf) && $var->name instanceof Node\Identifier || $isSelf && $var->name instanceof Node\VarLikeIdentifier) {
            $this->properties[$class][$var->name->toString()][] = [$node->expr, $class];
        }
    }

    private function currentClass(): ?string
    {
        $c = end($this->classStack);

        return $c === false ? null : $c;
    }

    /**
     * The classes a type declaration names, lowercase, without builtins.
     *
     * @return list<string>
     */
    public static function typeClasses(?Node $type, ?string $self): array
    {
        if ($type instanceof Node\NullableType) {
            return self::typeClasses($type->type, $self);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            return array_values(array_unique(array_merge(...array_map(static fn ($t) => self::typeClasses($t, $self), $type->types))));
        }
        if ($type instanceof Node\Name) {
            $name = strtolower(ltrim($type->toString(), '\\'));

            return in_array($name, ['self', 'static'], true) ? ($self !== null ? [$self] : []) : [$name];
        }

        return [];
    }

    public static function className(Stmt\ClassLike $node): ?string
    {
        if ($node->name === null) {
            return null;
        }

        return strtolower($node->namespacedName?->toString() ?? $node->name->toString());
    }
}
