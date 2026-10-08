<?php
// @expect: none
final class Handler
{
    private string $parent = 'Base';

    public function __call(string $method, array $args): mixed
    {
        preg_match('/(.*)(Debug|Info)(.*)/', $method, $m);
        $generic = $m[1] . 'Record' . $m[3];
        $callback = [$this, $generic];

        call_user_func($this->parent . '::__isset', 'x');

        return call_user_func_array($callback, $args);
    }

    public function options(array $options): void
    {
        foreach (self::OPTIONS as $option) {
            $method = 'set' . ucfirst($option);
            $this->$method($options[$option]);
        }
    }

    private const OPTIONS = [
        'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10', 'a11', 'a12', 'a13', 'a14', 'a15', 'a16',
        'a17', 'a18', 'a19', 'a20', 'a21', 'a22', 'a23', 'a24', 'a25', 'a26', 'a27', 'a28', 'a29', 'a30', 'a31', 'a32', 'a33',
    ];
}
