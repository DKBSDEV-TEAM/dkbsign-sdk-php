# DKBSign PHP SDK

PHP client library for the [DKBSign](https://api.dkbsigns.com) electronic signature API: self-sign PDFs, create multi-signer envelopes, verify signed documents, and send notification emails through the gateway.

## Requirements

- PHP 8.1+
- [Composer](https://getcomposer.org/)
- [Guzzle](https://github.com/guzzle/guzzle)

## Installation

```bash
composer require dkbs/dkbsign-sdk-php
```

### Contribution

```bash
git clone git@github.com:DKBSDEV-TEAM/dkbsign-sdk-php.git
cd dkbsign-sdk-php
composer install
```

## Quick start

You need a **base URL** (for example `https://api.dkbsigns.com`) and an **API bearer token** from your DKBSign account.

All API methods return an `HttpResponse` object with:

- `statusCode` — HTTP status (e.g. `200`, `201`, `202`)
- `body` — decoded JSON (`array`)

### Self-signing (two steps)

1. Request an OTP (`sendOtp()`).
2. Submit the PDF, signature image, field placement, and OTP (`selfSign()`).

```php
<?php

use DKBSign\DKBSign;
use DKBSign\Enums\FieldType;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Support\Field;
use DKBSign\Support\Position;

// Step 1: trigger OTP delivery
$otpResponse = new DKBSign('https://api.dkbsigns.com', 'your-api-token')->sendOtp();
// $otpResponse->body['email'], $otpResponse->body['expires_in_minutes']

// Step 2: sign (use the OTP code you received)
$response = (new DKBSign('https://api.dkbsigns.com', 'your-api-token'))
    ->setFile('/path/to/document.pdf')
    ->setSignatureImage('/path/to/signature.png')
    ->setSignatureLevel(SignatureLevel::SIMPLE->value)
    ->addField(page: 0, fields: [
        new Field(FieldType::SIGNATURE->value)
            ->usePosition(new Position(x: 120, y: 200, width: 200, height: 70)),
    ])
    ->selfSign(123456);

// $response->statusCode === 200
// $response->body['document_uuid'], $response->body['signed_pdf_url'], $response->body['verification_url']
```

Use a fresh `DKBSign` instance (or rebuild the fluent chain) before each sign request so page and file state from a previous call is not reused.

### Send an envelope (two steps)

1. Create the batch (`envelopes()`).
2. Queue invitation email(s) (`sendInvitation()`), using each signer's `signing_url` from step 1.

```php
<?php

use DKBSign\DKBSign;
use DKBSign\Enums\FieldType;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Support\EmailTemplate;
use DKBSign\Support\Position;
use DKBSign\Support\Signer;

$client = new DKBSign('https://api.dkbsigns.com', 'your-api-token');

// Step 1: create the envelope (does not send email)
$envelope = $client
    ->setDocuments(['/path/to/nda.pdf', '/path/to/annex.pdf'])
    ->setAttachments(0, ['/path/to/id-copy.pdf'])
    ->setEnvelopeTitle('NDA')
    ->setEnvelopeDescription('Please sign this NDA')
    ->setSignatureOrder(SignatureOrder::ORDERED->value)
    ->setSignatureLevel(SignatureLevel::ADVANCED->value)
    ->setSigners([
        new Signer(
            firstName: 'Jane',
            lastName: 'Doe',
            email: 'jane@example.com',
            phone: '+2250700000001',
            priority: 1,
            positions: [
                new Position(
                    x: 120,
                    y: 200,
                    width: 200,
                    height: 70,
                    page: 0,
                    fieldType: FieldType::SIGNATURE->value,
                    documentIndex: 0,
                ),
                new Position(
                    x: 120,
                    y: 200,
                    width: 200,
                    height: 70,
                    page: 0,
                    fieldType: FieldType::SIGNATURE->value,
                    documentIndex: 1,
                ),
            ],
        ),
    ])
    ->envelopes();

// $envelope->statusCode === 201
// $envelope->body['batch_id'], $envelope->body['signers'][0]['signing_url']

// Step 2: notify signers (202 = queued for delivery)
$signer = $envelope->body['signers'][0];

$queued = $client->sendInvitation(
    recipientEmail: $signer['email'],
    subject: 'Document awaiting signature',
    emailTemplate: new EmailTemplate($signer['signing_url']),
);

// $queued->statusCode === 202
// $queued->body['status'] === 'success'
```

`EmailTemplate` renders a small default HTML body with a sign link. Pass a custom HTML string as the second constructor argument to override it.

For **ordered** signing, run step 2 only for signers at the current priority; the platform invites the next group after each signature. For **`any`**, you can invite everyone right after step 1.

Attachments are stored with a document and are not signed. `setAttachments($documentIndex, $paths)` follows the order of `setDocuments()` (`0` is the first PDF).

## How it works

### Client entry point

`DKBSign` is constructed with the API base URL and token. All HTTP calls send `Authorization: Bearer {token}`.

| Method                                         | HTTP                                      | Purpose                                              |
| ---------------------------------------------- | ----------------------------------------- | ---------------------------------------------------- |
| `sendOtp()`                                    | `POST {baseUrl}/api/v4/sign/otp`          | Start OTP for self-signing                           |
| `selfSign(int $otpCode)`                       | `POST {baseUrl}/api/v4/sign`              | Multipart: PDF, params, signature image              |
| `qualified()`                                  | `POST {baseUrl}/api/v4/sign/qualified`    | One-shot qualified signature for an identified person |
| `envelopes()`                                  | `POST {baseUrl}/api/v4/envelopes`         | Multipart: PDF(s), attachments, JSON `payload`       |
| `listSentEnvelopes()`                          | `GET {baseUrl}/api/v4/envelopes`          | Envelopes you created                                |
| `listReceivedEnvelopes()`                      | `GET {baseUrl}/api/v4/envelopes/received` | Envelopes where you are a signer                     |
| `listSignedDocuments(?string $status, …)`      | `GET {baseUrl}/api/v4/documents`          | Your documents (`status`, `page`, `per_page`)        |
| `verifyDocument(string $uuid)`                 | `GET {baseUrl}/api/v4/verify/{uuid}`      | Public check that a document is signed               |
| `sendInvitation(…)`                            | `POST {baseUrl}/api/notifications/email`  | Queue an invitation email                            |

### Self-sign configuration

Methods return `$this` so you can chain options before `selfSign()`:

| Method                                 | Description                                  |
| -------------------------------------- | -------------------------------------------- |
| `setFile(string $path)`                | Local path to the PDF to sign                |
| `setSignatureImage(string $path)`      | Local path to the visual signature image     |
| `setSignatureLevel(string $level)`     | Legal/technical level (see `SignatureLevel`) |
| `addField(int $page, Field[] $fields)` | One page of marks; call again for more pages |

Page numbers are **zero-based** (page `0` is the first page). Coordinates are in millimetres from the **bottom-left** of the page (PDF convention), matching the v4 API.

Each `Field` has a `type` (see `FieldType`) and either a `Position` (`x`, `y`, `width`, `height`) or an `Anchor`. A `text` mark also calls `setText()` for the words drawn in the box.

An anchor is text already printed in the PDF, for example `{{signature}}`. The API finds that text and places the mark on it. The printed string must match exactly. `Anchor::$occurrence` (0-based) keeps a single match when the text appears more than once. The page passed to `addField()` limits the search to that page.

```php
use DKBSign\Enums\FieldType;
use DKBSign\Support\Anchor;
use DKBSign\Support\Field;

->addField(page: 0, fields: [
    new Field(FieldType::SIGNATURE->value)
        ->useAnchor(new Anchor(name: '{{signature}}', width: 200, height: 70)),
    new Field(FieldType::TEXT->value)
        ->setText('Lu et approuvé')
        ->usePosition(new Position(x: 20, y: 40, width: 180, height: 40)),
])
```

### Multipart payload (`selfSign`)

1. **`file`** — PDF stream
2. **`params`** — JSON: `pages`, `signature_level`, `otp_code`
3. **`signature_image`** — image stream

A successful response includes `document_uuid`, `signed_pdf_url`, and `verification_url`.

### Envelope configuration

Chain these before `envelopes()`:

| Method                                      | Description                                      |
| ------------------------------------------- | ------------------------------------------------ |
| `setDocuments(string[] $paths)`             | One or more local PDF paths                      |
| `setAttachments(int $index, string[] $paths)` | Advisory files for that document index         |
| `setEnvelopeTitle(string $title)`           | Title shown to signers                           |
| `setEnvelopeDescription(string $msg)`       | Message to signers                               |
| `setSignatureOrder(string $order)`          | `ordered` or `any` (see `SignatureOrder`)        |
| `setSignatureLevel(string $level)`          | Level imposed on all participants                |
| `setSigners(Signer[] $signers)`             | Signers with contact info and field positions    |

Each `Signer` needs `firstName`, `lastName`, `email`, `phone` (E.164, e.g. `+225…`), `priority`, and a list of `Position` marks. An optional `Anchor` is sent as an extra mark: the API looks up `Anchor::$name` in the PDF instead of using `x` and `y`. Group that anchor with `Anchor::$documentIndex` (default `0`).

**Positions per document:** marks are grouped by `Position::$documentIndex` (default `0` = first file in `setDocuments()`). The API expects JSON like `{"0": [{page, x, y, …}], "1": […]}`. An anchor mark in that map is `{"anchor": "{{signature}}", "width": …, "height": …}`. Set `Position::$fieldType` (see `FieldType`) for initials, a QR code, text, a date, an approval, or a seal.

A successful envelope response is HTTP `201` and includes `batch_id`, `title`, `documents` (each with `attachments`), and `signers` (each with `signing_url` and `status`).

### Multipart payload (`envelopes`)

1. **`documents[]`** — one part per PDF (with filename)
2. **`attachments_{index}[]`** — optional files for that document index
3. **`payload`** — JSON string: `title`, `message`, `signing_order`, `signature_level`, `signers`

### Qualified signature (one shot)

`qualified()` signs one PDF for a person your application has already identified. Call `setQualifiedSigner()` first. The API issues a short-lived certificate, signs, then discards the private key. There is no OTP and no signing link. The level is always `qualified`.

`phone` and `reason` are required, along with the name, email, and identity document. Marks on this endpoint are `signature`, `initials`, or `text`.

```php
use DKBSign\Enums\DocumentType;
use DKBSign\Enums\FieldType;
use DKBSign\Support\Anchor;
use DKBSign\Support\Field;
use DKBSign\Support\IdentityDocument;
use DKBSign\Support\QualifiedSigner;

$response = (new DKBSign('https://api.dkbsigns.com', 'your-api-token'))
    ->setFile('/path/to/document.pdf')
    ->setSignatureImage('/path/to/signature.png') // optional
    ->addField(page: 0, fields: [
        new Field(FieldType::SIGNATURE->value)
            ->useAnchor(new Anchor(name: '{{signature}}', width: 200, height: 70)),
    ])
    ->setQualifiedSigner(new QualifiedSigner(
        firstName: 'Awa',
        lastName: 'Koné',
        email: 'awa.kone@example.com',
        identityDocument: new IdentityDocument(
            type: DocumentType::CNI->value,
            number: 'CI123456789',
        ),
        phone: '+2250700000000',
        reason: 'Acceptation du contrat',
    ))
    ->qualified();
```

Multipart parts:

1. **`file`** — PDF stream
2. **`payload`** — JSON: `signer` (`first_name`, `last_name`, `email`, `phone`, `reason`, `id_card.document_type`, `id_card.document_number`) and `params.pages`
3. **`signature_image`** — optional PNG or JPEG; only marks of type `signature` use it

`id_card.document_type` is `cni` or `passport` (`DocumentType`). The response masks the identity number to its last four digits and returns `certificate.serial` and `certificate.expires_at`.

### Lists and verification

```php
$documents = $client->listSignedDocuments(status: 'signed');
// $documents->body['documents'], $documents->body['total']

$check = $client->verifyDocument($documents->body['documents'][0]['uuid']);
// $check->body['valid'], $check->body['document']['status']

$sent = $client->listSentEnvelopes();
$received = $client->listReceivedEnvelopes();
// $sent->body['envelopes'], $received->body['envelopes']
```

`verifyDocument()` asks for JSON (`format=json`). A signed document returns `valid: true`.

### HTTP layer

`DKBSign\Services\HttpClient` wraps Guzzle and returns `HttpResponse`:

- `post()` — multipart requests
- `postJson()` — JSON POST
- `get()` — JSON GET

Failed HTTP status codes surface as Guzzle exceptions unless you handle them around the client.

## Enums

### `SignatureLevel`

| Case        | Value       |
| ----------- | ----------- |
| `SIMPLE`    | `simple`    |
| `ADVANCED`  | `advanced`  |
| `QUALIFIED` | `qualified` |

### `DocumentType`

| Case       | Value      |
| ---------- | ---------- |
| `CNI`      | `cni`      |
| `PASSPORT` | `passport` |

### `SignatureOrder`

| Case      | Value     |
| --------- | --------- |
| `ORDERED` | `ordered` |
| `ANY`     | `any`     |

### `FieldType`

| Case        | Value       |
| ----------- | ----------- |
| `SIGNATURE` | `signature` |
| `INITIALS`  | `initials`  |
| `SEAL`      | `seal`      |
| `QRCODE`    | `qrcode`    |
| `DATE`      | `date`      |
| `TEXT`      | `text`      |
| `APPROVAL`  | `approval`  |

Qualified signing accepts `signature`, `initials`, and `text`. Envelopes and self-sign also accept the other types.

## Development

Clone the repository and install dependencies:

```bash
composer install
```

Integration tests in `tests/SignatureTest.php` call the live API. Configure credentials in `.env` or `.env.local` (see `.env.example`):

| Variable                       | Required for                     | Description                          |
| ------------------------------ | -------------------------------- | ------------------------------------ |
| `DKBSIGN_API_TOKEN`            | All tests                        | Bearer token                         |
| `DKBSIGN_TEST_EMAIL`           | All tests                        | Account email (OTP test assertion)   |
| `DKBSIGN_TEST_PDF`             | All tests                        | Path to a sample PDF                 |
| `DKBSIGN_TEST_PDF_WITH_ANCHOR` | Anchor tests                     | PDF whose text contains `{{signature}}` |
| `DKBSIGN_TEST_PDF_ATTACHMENT`  | Envelope attachment tests        | Extra file stored with a document    |
| `DKBSIGN_TEST_SIGNATURE_IMAGE` | All tests                        | Path to a signature image            |
| `DKBSIGN_BASE_URL`             | Optional                         | Default `https://api.dkbsigns.com`   |
| `DKBSIGN_TEST_SIGNER_EMAIL`    | Envelope / invitation tests      | External signer inbox                |
| `DKBSIGN_TEST_SIGNER_PHONE`    | Envelope / qualified tests       | E.164 phone                          |

Run tests:

```bash
cp .env.example .env
# Fill in values, then:
./vendor/bin/phpunit -c phpunit.xml.dist tests
```

`tests/bootstrap.php` loads `.env` then `.env.local` into the environment before PHPUnit runs. If required variables are missing, tests are **skipped** (not failed).

## License

[LICENSE](LICENSE).
