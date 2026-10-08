<?php
// @expect: NETWORK
// @targets: host:c2.example, host:lookup.example
$s = fsockopen('ssl://c2.example', 443);
$ip = gethostbyname('lookup.example');
