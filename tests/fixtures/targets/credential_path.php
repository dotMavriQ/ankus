<?php
// @expect: ENV, FILE_READ, NETWORK, SENSITIVE_PATH
// @targets: path:.aws/credentials
$creds = file_get_contents(getenv('HOME') . '/.aws/credentials');
