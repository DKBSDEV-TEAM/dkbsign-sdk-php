<?php

declare(strict_types=1);

namespace DKBSign;

use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Services\HttpClient;
use DKBSign\Services\HttpResponse;
use DKBSign\Support\Anchor;
use DKBSign\Support\EmailTemplate;
use DKBSign\Support\Field;
use DKBSign\Support\Position;
use DKBSign\Support\QualifiedSigner;
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
     * @var array<int, array<int, string>>
     */
    protected array $attachments = [];

    protected array $qualifiedSigner;

    /**
     * @var array<int, Signer>;
     */
    protected array $signers = [];

    protected string $initiatorName = 'DKBSIGN';

    protected HttpClient $httpClient;

    public function __construct(public string $baseUrl, public string $apiToken)
    {
        $this->httpClient = new HttpClient($apiToken);
    }

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
     * Advisory files stored with one document. They are not signed.
     *
     * @param  array<int, string>  $paths
     */
    public function setAttachments(int $documentIndex, array $paths): self
    {
        $this->attachments[$documentIndex] = $paths;

        return $this;
    }

    public function setQualifiedSigner(QualifiedSigner $signer): self
    {
        $this->qualifiedSigner = [
            'first_name' => $signer->firstName,
            'last_name' => $signer->lastName,
            'email' => $signer->email,
            'phone' => $signer->phone,
            'id_card' => [
                'document_type' => $signer->identityDocument->type,
                'document_number' => $signer->identityDocument->number,
            ],
            'reason' => $signer->reason,
        ];

        return $this;
    }

    /**
     * @param  array<int, Field>  $fields
     */
    public function addField(int $page, array $fields): self
    {
        $this->pages[] = [
            'page' => $page,
            'signatures' => array_map(
                fn (Field $field) => $this->buildFieldMark($field),
                $fields
            ),
        ];

        return $this;
    }

    /**
     * A mark is either coordinates (`x`/`y`) or printed text (`anchor`).
     *
     * @return array<string, float|int|string>
     */
    protected function buildFieldMark(Field $field): array
    {
        if ($field->anchor instanceof Anchor) {
            $mark = [
                'anchor' => $field->anchor->name,
                'width' => $field->anchor->width,
                'height' => $field->anchor->height,
                'type' => $field->type,
            ];

            if ($field->anchor->occurrence !== null) {
                $mark['occurrence'] = $field->anchor->occurrence;
            }
        } elseif ($field->position instanceof Position) {
            $mark = [
                'x' => $field->position->x,
                'y' => $field->position->y,
                'width' => $field->position->width,
                'height' => $field->position->height,
                'type' => $field->type,
            ];
        } else {
            throw new \InvalidArgumentException('A signature needs a position or an anchor.');
        }

        if ($field->text !== null) {
            $mark['text'] = $field->text;
        }

        return $mark;
    }

    /**
     * API expects marks grouped by document index: {"0": [{page, x, y, ...}], "1": [...]}.
     * Cast to object so json_encode keeps string keys (PHP would otherwise emit a bare array).
     *
     * @param  array<int, Position>  $positions
     */
    protected function buildSignerPositions(array $positions, ?Anchor $anchor = null): \stdClass
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
                'type' => $position->fieldType,
            ];
        }

        if ($anchor instanceof Anchor) {
            $documentIndex = (string) $anchor->documentIndex;
            $grouped[$documentIndex] ??= [];
            $mark = [
                'anchor' => $anchor->name,
                'width' => $anchor->width,
                'height' => $anchor->height,
            ];

            if ($anchor->page !== null) {
                $mark['page'] = $anchor->page;
            }

            if ($anchor->fieldType !== null) {
                $mark['type'] = $anchor->fieldType;
            }

            if ($anchor->occurrence !== null) {
                $mark['occurrence'] = $anchor->occurrence;
            }

            $grouped[$documentIndex][] = $mark;
        }

        return (object) $grouped;
    }

    /**
     * @param  array<int, Signer>  $signers
     */
    public function setSigners(array $signers): self
    {
        foreach ($signers as $signer) {
            $this->signers[] = [
                'name' => $signer->lastName,
                'first_name' => $signer->firstName,
                'email' => $signer->email,
                'phone' => $signer->phone,
                'priority' => $signer->priority,
                'positions' => $this->buildSignerPositions($signer->positions, $signer->anchor),
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

        return $this->httpClient->post($url, [
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
        ]);
    }

    public function sendOtp(): HttpResponse
    {
        return $this->httpClient->postJson(sprintf('%s/api/v4/sign/otp', $this->baseUrl));
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

        foreach ($this->attachments as $documentIndex => $paths) {
            foreach ($paths as $path) {
                $documents[] = [
                    'name' => 'attachments_'.$documentIndex.'[]',
                    'contents' => Utils::tryFopen($path, 'r'),
                    'filename' => basename($path),
                ];
            }
        }

        $response = $this->httpClient->post($url, [
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
        ]);

        return $response;
    }

    public function listSignedDocuments(?string $status = null, int $page = 1, int $perPage = 20): HttpResponse
    {
        $query = [
            'page' => $page,
            'per_page' => $perPage,
        ];

        if ($status !== null && $status !== '') {
            $query['status'] = $status;
        }

        return $this->httpClient->get(
            sprintf('%s/api/v4/documents', $this->baseUrl),
            $query,
        );
    }

    public function verifyDocument(string $uuid): HttpResponse
    {
        return $this->httpClient->get(
            sprintf('%s/api/v4/verify/%s', $this->baseUrl, rawurlencode($uuid)),
            ['format' => 'json']
        );
    }

    public function listSentEnvelopes(): HttpResponse
    {
        return $this->httpClient->get(sprintf('%s/api/v4/envelopes', $this->baseUrl));
    }

    public function listReceivedEnvelopes(): HttpResponse
    {
        return $this->httpClient->get(sprintf('%s/api/v4/envelopes/received', $this->baseUrl));
    }

    public function sendInvitation(string $recipientEmail, string $subject, EmailTemplate $emailTemplate): HttpResponse
    {
        return $this->httpClient->postJson(sprintf('%s/api/notifications/email', $this->baseUrl), [
            'email' => $recipientEmail,
            'subject' => $subject,
            'body' => $emailTemplate->toHtml(),
        ]);
    }

    public function qualified(): HttpResponse
    {
        $url = sprintf('%s/api/v4/sign/qualified', $this->baseUrl);

        $parts = [
            [
                'name' => 'file',
                'contents' => Utils::tryFopen($this->file, 'r'),
                'filename' => basename($this->file),
            ],
            [
                'name' => 'payload',
                'contents' => json_encode([
                    'signer' => $this->qualifiedSigner,
                    'params' => [
                        'pages' => $this->pages,
                    ],
                ]),
            ],
        ];

        if (isset($this->signatureImage) && $this->signatureImage !== '') {
            $parts[] = [
                'name' => 'signature_image',
                'contents' => Utils::tryFopen($this->signatureImage, 'r'),
                'filename' => basename($this->signatureImage),
            ];
        }

        return $this->httpClient->post($url, $parts);
    }
}
