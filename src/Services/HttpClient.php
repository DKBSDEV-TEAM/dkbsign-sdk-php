<?php

declare(strict_types=1);

namespace DKBSign\Services;

use GuzzleHttp\Client;

final class HttpClient
{
    public function __construct(public string $bearerToken) {}

    public function post(string $url, array $payload): HttpResponse
    {
        $response = new Client()->post($url, [
            'multipart' => $payload,
            'headers' => ['Authorization' => 'Bearer '.$this->bearerToken],
        ]);

        return new HttpResponse(
            body: json_decode((string) $response->getBody(), true),
            statusCode: $response->getStatusCode()
        );
    }

    public function get(string $url, array $query = []): HttpResponse
    {
        $response = new Client()->get($url, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->bearerToken,

            ],
            'query' => $query,
        ]);

        return new HttpResponse(
            body: json_decode((string) $response->getBody(), true),
            statusCode: $response->getStatusCode()
        );
    }

    public function postJson(string $url, array $payload = []): HttpResponse
    {
        $response = new Client()->post($url, [
            'json' => $payload,
            'headers' => ['Authorization' => 'Bearer '.$this->bearerToken]]);

        return new HttpResponse(
            body: json_decode((string) $response->getBody(), true),
            statusCode: $response->getStatusCode()
        );
    }
}
