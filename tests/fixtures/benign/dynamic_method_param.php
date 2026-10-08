<?php
// @expect: none
final class Router
{
    public function dispatch(string $action): mixed
    {
        return $this->$action();
    }

    public function make(string $class): object
    {
        return new $class();
    }
}
