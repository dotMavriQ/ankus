<?php
// @expect: NETWORK, OBFUSCATION
final class Beacon
{
    private const TARGET = 'aHR0cHM6Ly9ldmlsLmV4YW1wbGUv';

    public function send(): void
    {
        $this->post(base64_decode(self::TARGET));
    }

    private function post(string $url): void
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_exec($ch);
    }
}
