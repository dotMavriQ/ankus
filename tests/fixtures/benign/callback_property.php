<?php
// @expect: none
final class Listener
{
    private $handler;

    public function __construct(callable $handler)
    {
        $this->handler = $handler;
    }

    public function fire(): mixed
    {
        return ($this->handler)();
    }
}
