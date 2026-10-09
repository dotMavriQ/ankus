<?php
// ...but hashing a URL is.
// @expect: FILE_READ, NETWORK
$hash = sha1_file('https://example.com/release.tar.gz');
