<?php
declare(strict_types=1);
// @expect: none
namespace Money;

final class Amount
{
    public function __construct(private readonly int $cents)
    {
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function format(): string
    {
        return sprintf('%d.%02d', intdiv($this->cents, 100), $this->cents % 100);
    }
}
