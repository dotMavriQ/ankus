<?php
// @expect: none
function sortBy(array $comparisons, mixed $a, mixed $b): int
{
    foreach ($comparisons as $comparison) {
        $comparison = (array) $comparison;
        $prop = $comparison[0];
        if (!is_string($prop) && is_callable($prop)) {
            return $prop($a, $b);
        }
    }
    foreach ($comparisons as $mutator) {
        $mutator = new $mutator();
        $a = $mutator($a);
    }

    return 0;
}
