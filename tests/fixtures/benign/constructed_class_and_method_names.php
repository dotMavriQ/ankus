<?php
// @expect: none
final class Model
{
    private string $model = 'App\User';

    public function make(): object
    {
        $class = '\\' . ltrim($this->model, '\\');

        return new $class();
    }

    public function mutate(string $key, mixed $value): mixed
    {
        return $this->{'get' . ucfirst($key) . 'Attribute'}($value);
    }

    public function query(string $class): object
    {
        $queryClass = $class . 'Query';

        return $queryClass::create();
    }
}
