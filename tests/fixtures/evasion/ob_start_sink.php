<?php
// @expect: EXEC
ob_start('system');
echo 'id';
ob_end_flush();
