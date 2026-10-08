<?php
// URLs in exception messages and plain strings are documentation, not destinations.
// @expect: none
// @targets: none
function check(bool $ok): void
{
    $help = 'See https://docs.example.com/errors';
    if (!$ok) {
        throw new \RuntimeException('Failed, see https://docs.example.com/errors');
    }
    throw new \Vendor\Exception\ConfigException('Read https://docs.example.com/config');
}
