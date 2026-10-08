<?php
namespace Acme\Http;

final class Client
{
    public function get(string $url): string
    {
        return strtoupper($url);
    }
}
