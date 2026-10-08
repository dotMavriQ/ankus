<?php
// @expect: none
final class Wrapper
{
    public function wrapAll(array $values): array
    {
        return array_map($this->wrap(...), $values);
    }

    private function wrap(string $v): string
    {
        return pack('N', strlen($v)) . $v;
    }
}
