<?php
// @expect: EXEC, OBFUSCATION
$run = base64_decode('c2hlbGxfZXhlYw==');
echo $run('whoami');
