<?php
// Ordinary configuration variables are not recorded; secret-looking ones are.
// @expect: ENV
// @targets: env:AWS_SECRET_ACCESS_KEY, env:GITHUB_TOKEN
$debug = getenv('APP_DEBUG');
$token = getenv('GITHUB_TOKEN');
$key = $_ENV['AWS_SECRET_ACCESS_KEY'] ?? null;
