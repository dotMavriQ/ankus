<?php
// Decoding runtime data before a sink is normal; only encoded literals are suspicious.
// @expect: UNSERIALIZE
function restore(string $cookie): mixed
{
    return unserialize(base64_decode($cookie), ['allowed_classes' => false]);
}
