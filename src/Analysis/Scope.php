<?php

declare(strict_types=1);

namespace Ankus\Analysis;

/**
 * Variable values inside one function body (or a file's top level).
 * Tracking is in source order: assignments outside branches replace the
 * old value, assignments inside a branch or loop are unioned with it.
 */
final class Scope
{
    /** @var array<string, Value> */
    private array $vars = [];

    public int $branchDepth = 0;
    public int $loopDepth = 0;

    /** @var array<string, array{\PhpParser\Node\Expr\Closure|\PhpParser\Node\Expr\ArrowFunction, Scope}> closures assigned to variables, with their defining scope */
    public array $closures = [];

    /** @var array<string, list<string>> variable => classes it may hold (lowercase FQN), from type hints and `new` */
    public array $types = [];

    /** Call-graph node this code belongs to ("class::method", "fn:name", or '' for top-level code). */
    public string $node = '';

    public function __construct(
        public readonly ?string $class,
        public readonly string $context,
    ) {
    }

    public function get(string $name): Value
    {
        return $this->vars[$name] ?? Value::external();
    }

    public function bind(string $name, Value $value): void
    {
        $this->vars[$name] = $value;
    }

    public function has(string $name): bool
    {
        return isset($this->vars[$name]);
    }

    public function assign(string $name, Value $value, array $types = []): void
    {
        unset($this->closures[$name]);
        if ($types !== [] || $this->branchDepth === 0) {
            $this->types[$name] = $this->branchDepth > 0 ? array_values(array_unique([...($this->types[$name] ?? []), ...$types])) : $types;
        }
        if ($this->branchDepth > 0 && isset($this->vars[$name])) {
            $value = $this->vars[$name]->union($value);
        }
        $this->vars[$name] = $value;
    }

    public function child(?string $class, string $context, bool $inherit, array $captured = []): self
    {
        $child = new self($class, $context);
        $child->node = $this->node;
        if ($inherit) {
            $child->vars = $this->vars;
            $child->closures = $this->closures;
            $child->types = $this->types;
        }
        foreach ($captured as $name) {
            if (isset($this->vars[$name])) {
                $child->vars[$name] = $this->vars[$name];
            }
            if (isset($this->closures[$name])) {
                $child->closures[$name] = $this->closures[$name];
            }
            if (isset($this->types[$name])) {
                $child->types[$name] = $this->types[$name];
            }
        }

        return $child;
    }
}
