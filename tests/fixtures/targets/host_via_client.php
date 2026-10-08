<?php
// No network call here, but a URL handed to another package's client.
// @expect: none
// @targets: host:collector.example
function report(\Vendor\Http\Client $client, array $data): void
{
    $client->post('https://collector.example/ingest', ['json' => $data]);
}
