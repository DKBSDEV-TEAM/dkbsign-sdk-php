<?php

declare(strict_types=1);

namespace DKBSign\Services;

use GuzzleHttp\Client;

abstract class HttpClient
{
    public static function post(string $url, array $payload, ?string $bearerToken = null): HttpResponse
    {
        $response = new Client()->post($url, [
            'multipart' => $payload,
            'headers' => [
                'Authorization' => 'Bearer '.$bearerToken,
            ],
        ]);

        return new HttpResponse(
            body: json_decode((string) $response->getBody(), true),
            statusCode: $response->getStatusCode()
        );
    }

    public static function postJson(string $url, array $payload = [], ?string $bearerToken = null): HttpResponse
    {
        $response = new Client()->post($url, [
            'json' => $payload,
            'headers' => [
                'Authorization' => 'Bearer '.$bearerToken,
            ], ]);

        return new HttpResponse(
            body: json_decode((string) $response->getBody(), true),
            statusCode: $response->getStatusCode()
        );
    }
}
