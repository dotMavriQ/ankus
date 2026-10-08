<?php
// @expect: CODE_EVAL, OBFUSCATION, FILE_READ
$blob = file_get_contents(__DIR__ . '/data.bin');
eval(gzinflate(base64_decode($blob)));
