<?php

declare(strict_types=1);

namespace DKBSign;

use DKBSign\Enums\SignatureLevel;
use DKBSign\Services\HttpClient;
use GuzzleHttp\Psr7\Utils;

class DKBSign
{
    protected string $file;

    protected array $params;

    protected string $signatureImage;

    protected array $pages;

    protected string $signatureLevel = SignatureLevel::SIMPLE->value;

    public function __construct(public string $baseUrl, public string $apiToken) {}

    public function signatureImage(string $signatureImage): self
    {
        $this->signatureImage = $signatureImage;

        return $this;
    }

    public function signatureLevel(string $signatureLevel): self
    {
        $this->signatureLevel = $signatureLevel;

        return $this;
    }

    /**
     * @param  array<int, Signature>  $signatures
     */
    public function signature(int $page, array $signatures): self
    {
        $this->pages[] = [
            'page' => $page,
            'signatures' => array_map(fn (Signature $signature) => [
                'x' => $signature->positionX,
                'y' => $signature->positionY,
                'width' => $signature->width,
                'height' => $signature->height,
                'type' => $signature->type,
            ], $signatures),
        ];

        return $this;
    }

    public function file(string $file): self
    {
        $this->file = $file;

        return $this;
    }

    public function selfSign(int $otpCode): array
    {
        $url = sprintf('%s/api/v4/sign', $this->baseUrl);

        return HttpClient::post($url, [
            [
                'name' => 'file',
                'contents' => Utils::tryFopen($this->file, 'r'),
            ],

            [
                'name' => 'params',
                'contents' => json_encode([
                    'pages' => $this->pages,
                    'signature_level' => $this->signatureLevel,
                    'otp_code' => $otpCode,
                ]),
            ],

            [
                'name' => 'signature_image',
                'contents' => Utils::tryFopen($this->signatureImage, 'r'),
            ],
        ], $this->apiToken);
    }

    public function sendOtp(): array
    {
        return HttpClient::postJson(sprintf('%s/api/v4/sign/otp', $this->baseUrl), bearerToken: $this->apiToken);
    }
}
