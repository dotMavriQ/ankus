<?php
// @expect: NATIVE
$ffi = call_user_func(['FFI', 'cdef'], 'int system(const char *);');
