<?php
namespace Pkg;
// Unqualified exec() falls back to the global function when the namespace doesn't define one.
// @expect: EXEC
exec('id');
