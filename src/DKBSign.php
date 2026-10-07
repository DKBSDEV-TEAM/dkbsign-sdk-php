<?php

declare(strict_types=1);

namespace DKBSign;

use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Services\HttpClient;
use DKBSign\Services\HttpResponse;
use DKBSign\Support\EmailTemplate;
use DKBSign\Support\Position;
use DKBSign\Support\Signature;
use DKBSign\Support\Signer;
use GuzzleHttp\Psr7\Utils;

final class DKBSign
{
    protected string $file;

    protected array $params;

    protected string $signatureImage;

    protected array $pages;

    protected string $signatureLevel = SignatureLevel::SIMPLE->value;

    protected string $envelopeTitle;

    protected string $envelopeDescription;

    protected string $signatureOrder = SignatureOrder::ORDERED->value;

    protected array $documents;

    /**
     * @var array<int, Signer>;
     */
    protected array $signers = [];

    protected string $initiatorName = 'DKBSIGN';

    public function __construct(public string $baseUrl, public string $apiToken) {}

    public function setInitiatorName(string $initiatorName): self
    {
        $this->initiatorName = $initiatorName;

        return $this;
    }

    public function setSignatureImage(string $signatureImage): self
    {
        $this->signatureImage = $signatureImage;

        return $this;
    }

    public function setSignatureLevel(string $signatureLevel): self
    {
        $this->signatureLevel = $signatureLevel;

        return $this;
    }

    public function setSignatureOrder(string $signatureOrder): self
    {
        $this->signatureOrder = $signatureOrder;

        return $this;
    }

    public function setEnvelopeTitle(string $envelopeTitle): self
    {
        $this->envelopeTitle = $envelopeTitle;

        return $this;
    }

    public function setEnvelopeDescription(string $envelopeDescription): self
    {
        $this->envelopeDescription = $envelopeDescription;

        return $this;
    }

    public function setDocuments(array $documents): self
    {
        $this->documents = $documents;

        return $this;
    }

    /**
     * @param  array<int, Signature>  $signatures
     */
    public function addSignature(int $page, array $signatures): self
    {
        $this->pages[] = [
            'page' => $page,
            'signatures' => array_map(fn (Signature $signature) => [
                'x' => $signature->position->x,
                'y' => $signature->position->y,
                'width' => $signature->position->width,
                'height' => $signature->position->height,
                'type' => $signature->type,
            ], $signatures),
        ];

        return $this;
    }

    /**
     * API expects marks grouped by document index: {"0": [{page, x, y, ...}], "1": [...]}.
     * Cast to object so json_encode keeps string keys (PHP would otherwise emit a bare array).
     *
     * @param  array<int, Position>  $positions
     */
    protected function buildSignerPositions(array $positions): \stdClass
    {
        $grouped = [];

        foreach ($positions as $position) {
            $documentIndex = (string) $position->documentIndex;
            $grouped[$documentIndex] ??= [];
            $grouped[$documentIndex][] = [
                'page' => $position->page,
                'x' => $position->x,
                'y' => $position->y,
                'width' => $position->width,
                'height' => $position->height,
                'type' => $position->signatureType,
            ];
        }

        return (object) $grouped;
    }

    /**
     * @param  array<int, Signer>  $signers
     */
    public function addSigner(array $signers): self
    {
        foreach ($signers as $signer) {
            $this->signers[] = [
                'name' => $signer->lastName,
                'first_name' => $signer->firstName,
                'email' => $signer->email,
                'phone' => $signer->phone,
                'priority' => $signer->priority,
                'positions' => $this->buildSignerPositions($signer->positions),
            ];
        }

        return $this;
    }

    public function setFile(string $file): self
    {
        $this->file = $file;

        return $this;
    }

    public function selfSign(int $otpCode): HttpResponse
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

    public function sendOtp(): HttpResponse
    {
        return HttpClient::postJson(sprintf('%s/api/v4/sign/otp', $this->baseUrl), bearerToken: $this->apiToken);
    }

    public function envelopes(): HttpResponse
    {
        $url = sprintf('%s/api/v4/envelopes', $this->baseUrl);

        $documents = [];

        foreach ($this->documents as $document) {
            $documents[] = [
                'name' => 'documents[]',
                'contents' => Utils::tryFopen($document, 'r'),
                'filename' => basename($document),
            ];
        }

        $response = HttpClient::post($url, [
            ...$documents,

            [
                'name' => 'payload',
                'contents' => json_encode([
                    'title' => $this->envelopeTitle,
                    'message' => $this->envelopeDescription,
                    'signing_order' => $this->signatureOrder,
                    'signature_level' => $this->signatureLevel,
                    'signers' => $this->signers,
                ]),
            ],
        ], $this->apiToken);

        return $response;
    }

    public function sendInvitation(string $recipientEmail, string $subject, EmailTemplate $emailTemplate): HttpResponse
    {
        return HttpClient::postJson(sprintf('%s/api/notifications/email', $this->baseUrl), [
            'email' => $recipientEmail,
            'subject' => $subject,
            'body' => $emailTemplate->toHtml(),
        ], $this->apiToken);
    }
}
