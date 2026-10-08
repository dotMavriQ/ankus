<?php

declare(strict_types=1);

namespace Ankus\Analysis;

/**
 * Abstract value of an expression: the set of strings it may hold, plus
 * flags describing where any unknown part comes from.
 *
 *  - tainted:    built from something suspicious we could not fold
 *                (superglobals, decoders on unknown input, file/network
 *                reads, partial concatenation). Calling it is flagged.
 *  - external:   comes from a parameter, property or other caller-provided
 *                place. Calling it is the caller's capability, not ours.
 *  - partial:    known pieces joined with unknown ones ('get' . $x).
 *                Fine for a file path, but a call target built this way
 *                is a name being assembled at runtime.
 *  - obfuscated: at least one possible string went through a decoder or
 *                string-reversal style function on its way here.
 */
final readonly class Value
{
    /** Cap on concatenation products; beyond this a name is being brute-assembled. */
    public const MAX_STRINGS = 32;

    /** Cap on plain unions of known values (large constant arrays are normal). */
    public const MAX_UNION = 512;

    /** @param list<string> $strings */
    private function __construct(
        public array $strings,
        public bool $tainted,
        public bool $external,
        public bool $obfuscated,
        public bool $partial = false,
        /** @var list<string> known leading text of a partial value, e.g. 'https://' */
        public array $prefixes = [],
        /** @var list<string> every known piece of a partial value */
        public array $fragments = [],
        /** a two-element [class-or-object, method] array: a method callable */
        public bool $pair = false,
    ) {
    }

    public static function of(string ...$strings): self
    {
        return new self(array_values(array_unique($strings)), false, false, false);
    }

    /** Known not to be a string we care about (closures, objects, numbers in arithmetic). */
    public static function none(): self
    {
        return new self([], false, false, false);
    }

    public static function tainted(bool $obfuscated = false): self
    {
        return new self([], true, false, $obfuscated);
    }

    public static function external(): self
    {
        return new self([], false, true, false);
    }

    public static function partial(bool $obfuscated = false): self
    {
        return new self([], false, false, $obfuscated, true);
    }

    public function asPair(bool $pair = true): self
    {
        return new self($this->strings, $this->tainted, $this->external, $this->obfuscated, $this->partial, $this->prefixes, $this->fragments, $pair);
    }

    public function isConcrete(): bool
    {
        return $this->strings !== [] && !$this->tainted && !$this->external && !$this->partial;
    }

    public function isUnknown(): bool
    {
        return $this->tainted || $this->external || $this->partial;
    }

    /** Calling this would invoke something we cannot name. */
    public function isSuspiciousCallTarget(): bool
    {
        return $this->tainted || $this->partial;
    }

    public function withObfuscation(bool $obfuscated = true): self
    {
        return new self($this->strings, $this->tainted, $this->external, $this->obfuscated || $obfuscated, $this->partial, $this->prefixes, $this->fragments, $this->pair);
    }

    public function withoutObfuscation(): self
    {
        return new self($this->strings, $this->tainted, $this->external, false, $this->partial, $this->prefixes, $this->fragments, $this->pair);
    }

    public function withTaint(): self
    {
        return new self($this->strings, true, $this->external, $this->obfuscated, $this->partial, $this->prefixes, $this->fragments, $this->pair);
    }

    public function union(self $other): self
    {
        $strings = array_values(array_unique([...$this->strings, ...$other->strings]));
        $tainted = $this->tainted || $other->tainted;
        if (count($strings) > self::MAX_UNION) {
            $strings = array_slice($strings, 0, self::MAX_UNION);
            $tainted = true;
        }

        return new self(
            $strings,
            $tainted,
            $this->external || $other->external,
            $this->obfuscated || $other->obfuscated,
            $this->partial || $other->partial,
            array_slice(array_values(array_unique([...$this->prefixes, ...$other->prefixes])), 0, self::MAX_STRINGS),
            array_slice(array_values(array_unique([...$this->fragments, ...$other->fragments])), 0, self::MAX_UNION),
            $this->pair && $other->pair,
        );
    }

    /**
     * String concatenation. When only one side is known the result is
     * partial: we know part of the string but not all of it.
     */
    public function concat(self $other): self
    {
        $obfuscated = $this->obfuscated || $other->obfuscated;
        $tainted = $this->tainted || $other->tainted;
        $external = $this->external || $other->external;
        $partial = $this->partial || $other->partial;

        if ($this->strings === [] || $other->strings === []) {
            $partial = $partial || $this->strings !== [] || $other->strings !== [];
            $prefixes = $this->strings !== [] ? $this->strings : $this->prefixes;
            $fragments = array_values(array_unique([...$this->strings, ...$this->fragments, ...$other->strings, ...$other->fragments]));

            return new self([], $tainted, $external && !$partial, $obfuscated, $partial, $prefixes, array_slice($fragments, 0, self::MAX_UNION));
        }

        $strings = [];
        foreach ($this->strings as $a) {
            foreach ($other->strings as $b) {
                $strings[] = $a . $b;
                if (count($strings) > self::MAX_STRINGS) {
                    return new self([], true, $external, $obfuscated, $partial);
                }
            }
        }

        return new self(array_values(array_unique($strings)), $tainted, $external, $obfuscated, $partial);
    }

}
