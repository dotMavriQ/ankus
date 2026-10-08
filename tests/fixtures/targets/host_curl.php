<?php
// @expect: NETWORK
// @targets: host:api.example.com
$ch = curl_init('https://api.example.com/v1/items');
curl_exec($ch);
