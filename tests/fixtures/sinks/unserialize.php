<?php
// @expect: UNSERIALIZE
function restore(string $s): mixed
{
    return unserialize($s);
}
