<?php

declare(strict_types=1);

namespace DKBSign\Services;

use GuzzleHttp\Client;

class HttpClient
{
    public static function post(string $url, array $payload, ?string $bearerToken = null): array
    {
        $response = new Client()->post($url, [
            'multipart' => $payload,
            'headers' => [
                'Authorization' => 'Bearer '.$bearerToken,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    public static function postJson(string $url, array $payload = [], ?string $bearerToken = null): array
    {
        $response = new Client()->post($url, [
            'json' => $payload,
            'headers' => [
                'Authorization' => 'Bearer '.$bearerToken,
            ], ]);

        return json_decode((string) $response->getBody(), true);
    }
}
