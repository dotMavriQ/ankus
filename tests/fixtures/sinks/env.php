<?php
// @expect: ENV
$debug = getenv('APP_DEBUG');
$x = $_ENV['APP_KEY'] ?? null;
