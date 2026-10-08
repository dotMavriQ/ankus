<?php
// An unresolvable path is treated as a possible URL, hence NETWORK.
// @expect: ENV, FILE_READ, NETWORK, SENSITIVE_PATH
$creds = file_get_contents(getenv('HOME') . '/.aws/credentials');
