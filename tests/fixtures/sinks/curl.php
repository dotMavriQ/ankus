<?php
// @expect: NETWORK
function fetch(string $url): string|bool
{
    $ch = curl_init($url);

    return curl_exec($ch);
}
