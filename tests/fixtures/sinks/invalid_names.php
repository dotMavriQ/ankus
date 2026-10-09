<?php
// Parses, but is not valid PHP. Reported, never silently skipped.
// @expect: UNANALYZABLE
namespace Foo;

use Bar\BarClass as Bar;
use Baz\BazClass as Bar;
