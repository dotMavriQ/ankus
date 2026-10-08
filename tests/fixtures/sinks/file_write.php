<?php
// @expect: FILE_WRITE
function save(string $path, string $data): void
{
    file_put_contents($path, $data);
    $h = fopen($path . '.bak', 'wb');
}
