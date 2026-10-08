<?php
// The call on line 5 uses the old $f ('system'), not the call's result.
// @expect: EXEC
$f = 'system';
$f = $f('id');
