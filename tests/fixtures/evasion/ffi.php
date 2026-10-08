<?php
// @expect: NATIVE
$ffi = FFI::cdef('int system(const char *command);', 'libc.so.6');
