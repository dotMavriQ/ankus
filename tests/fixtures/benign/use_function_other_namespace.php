<?php
namespace App;
// @expect: none
use function Other\Lib\system;
system('not the built-in');
