<?php
// @expect: none
function decode(string $payload): array
{
    return json_decode(base64_decode($payload), true);
}
