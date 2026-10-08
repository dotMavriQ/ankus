<?php
// Building the name in a loop can't be folded, so it must be flagged, not dropped.
// @expect: DYNAMIC_UNRESOLVED
$f = '';
foreach (['s', 'y', 's', 't', 'e', 'm'] as $c) {
    $f .= $c;
}
$f('id');
