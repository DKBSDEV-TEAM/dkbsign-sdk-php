# DKBSign PHP SDK

PHP client library for the [DKBSign](https://api.dkbsigns.com) electronic signature API: self-sign PDFs, create multi-signer envelopes, and send notification emails through the gateway.

## Requirements

- PHP 8.1+
- [Composer](https://getcomposer.org/)
- [Guzzle](https://github.com/guzzle/guzzle)
- [FakerPHP](https://github.com/FakerPHP/Faker)

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
2. Submit the PDF, signature image, placement metadata, and OTP (`selfSign()`).

```php
<?php

use DKBSign\DKBSign;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureType;
use DKBSign\Support\Position;
use DKBSign\Support\Signature;

// Step 1: trigger OTP delivery
$otpResponse = new DKBSign('https://api.dkbsigns.com', 'your-api-token')->sendOtp();
// $otpResponse->body['email'], etc.

// Step 2: sign (use the OTP code you received)
$response = (new DKBSign('https://api.dkbsigns.com', 'your-api-token'))
    ->setFile('/path/to/document.pdf')
    ->setSignatureImage('/path/to/signature.png')
    ->setSignatureLevel(SignatureLevel::SIMPLE->value)
    ->addSignature(0, [
        new Signature(
            position: new Position(x: 120, y: 200, width: 200, height: 70),
            type: SignatureType::SIGNATURE->value,
        ),
    ])
    ->selfSign(123456);

// $response->statusCode === 200
// $response->body['signed_pdf_url'], $response->body['message'], …
```

Use a fresh `DKBSign` instance (or rebuild the fluent chain) before each sign request so page and file state from a previous call is not reused.

### Send an envelope (two steps)

1. Create the batch (`envelopes()`).
2. Queue invitation email(s) (`sendInvitation()`), using each signer's `signing_url` from step 1.

```php
<?php

use DKBSign\DKBSign;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Enums\SignatureType;
use DKBSign\Support\EmailTemplate;
use DKBSign\Support\Position;
use DKBSign\Support\Signer;

$client = new DKBSign('https://api.dkbsigns.com', 'your-api-token');

// Step 1: create the envelope (does not send email)
$envelope = $client
    ->setDocuments(['/path/to/nda.pdf'])
    ->setEnvelopeTitle('NDA')
    ->setEnvelopeDescription('Please sign this NDA')
    ->setSignatureOrder(SignatureOrder::ORDERED->value)
    ->setSignatureLevel(SignatureLevel::ADVANCED->value)
    ->addSigner([
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
                    signatureType: SignatureType::SIGNATURE->value,
                ),
            ],
        ),
    ])
    ->envelopes();

// $envelope->statusCode === 201
// $envelope->body['batch_id'], $envelope->body['signers'][…]['signing_url'], …

// Step 2: notify signers (202 = queued for delivery)
$signer = $envelope->body['signers'][0];

$queued = $client->sendInvitation(
    recipientEmail: $signer['email'],
    subject: 'Document awaiting signature',
    emailTemplate: new EmailTemplate($signer['signing_url']),
);

// $queued->statusCode === 202
```

`EmailTemplate` renders a small default HTML body with a sign link. Pass a custom HTML string as the second constructor argument to override it.

For **ordered** signing, run step 2 only for signers at the current priority; the platform invites the next group after each signature. For **`any`**, you can invite everyone right after step 1.

## How it works

### Client entry point

`DKBSign` is constructed with the API base URL and token. All HTTP calls send `Authorization: Bearer {token}`.

| Method                   | HTTP                                     | Purpose                                 |
| ------------------------ | ---------------------------------------- | --------------------------------------- |
| `sendOtp()`              | `POST {baseUrl}/api/v4/sign/otp`         | Start OTP for self-signing              |
| `selfSign(int $otpCode)` | `POST {baseUrl}/api/v4/sign`             | Multipart: PDF, params, signature image |
| `envelopes()`            | `POST {baseUrl}/api/v4/envelopes`        | Multipart: PDF(s) + JSON `payload`      |
| `sendInvitation(…)`      | `POST {baseUrl}/api/notifications/email` | Queue an invitation email               |

### Self-sign configuration

Methods return `$this` so you can chain options before `selfSign()`:

| Method                                      | Description                                       |
| ------------------------------------------- | ------------------------------------------------- |
| `setFile(string $path)`                     | Local path to the PDF to sign                     |
| `setSignatureImage(string $path)`           | Local path to the visual signature image          |
| `setSignatureLevel(string $level)`          | Legal/technical level (see `SignatureLevel`)      |
| `addSignature(int $page, Signature[] $sig)` | One page of placements; call again for more pages |

Page numbers are **zero-based** (page `0` is the first page). Coordinates are in millimetres from the **bottom-left** of the page (PDF convention), matching the v4 API.

Each `Signature` wraps a `Position` (`x`, `y`, `width`, `height`, optional `page`, `signatureType`) and a field `type` (see `SignatureType`).

### Multipart payload (`selfSign`)

1. **`file`** — PDF stream
2. **`params`** — JSON: `pages`, `signature_level`, `otp_code`
3. **`signature_image`** — image stream

### Envelope configuration

Chain these before `envelopes()`:

| Method                                | Description                                   |
| ------------------------------------- | --------------------------------------------- |
| `setDocuments(string[] $paths)`       | One or more local PDF paths                   |
| `setEnvelopeTitle(string $title)`     | Title shown to signers                        |
| `setEnvelopeDescription(string $msg)` | Optional message to signers                   |
| `setSignatureOrder(string $order)`    | `ordered` or `any` (see `SignatureOrder`)     |
| `setSignatureLevel(string $level)`    | Level imposed on all participants             |
| `addSigner(Signer[] $signers)`        | Signers with contact info and field positions |

Each `Signer` needs `firstName`, `lastName`, `email`, `phone` (E.164, e.g. `+225…`), `priority`, and a list of `Position` marks.

**Positions per document:** marks are grouped by `Position::$documentIndex` (default `0` = first file in `setDocuments()`). The API expects JSON like `{"0": [{page, x, y, …}], "1": […]}`.

### Multipart payload (`envelopes`)

1. **`documents[]`** — one part per PDF (with filename)
2. **`payload`** — JSON string: `title`, `message`, `signing_order`, `signature_level`, `signers`

### HTTP layer

`DKBSign\Services\HttpClient` wraps Guzzle and returns `HttpResponse`:

- `post()` — multipart requests
- `postJson()` — JSON POST

Failed HTTP status codes surface as Guzzle exceptions unless you handle them around the client.

## Enums

### `SignatureLevel`

| Case        | Value       |
| ----------- | ----------- |
| `SIMPLE`    | `simple`    |
| `ADVANCED`  | `advanced`  |
| `QUALIFIED` | `qualified` |

### `SignatureOrder`

| Case      | Value     |
| --------- | --------- |
| `ORDERED` | `ordered` |
| `ANY`     | `any`     |

### `SignatureType`

| Case        | Value       |
| ----------- | ----------- |
| `SIGNATURE` | `signature` |
| `INITIALS`  | `initials`  |
| `SEAL`      | `seal`      |
| `QRCODE`    | `qrcode`    |
| `DATE`      | `date`      |
| `TEXT`      | `text`      |
| `APPROVAL`  | `approval`  |

## Development

Clone the repository and install dependencies:

```bash
composer install
```

Integration tests in `tests/SignatureTest.php` call the live API. Configure credentials in `.env` or `.env.local` (see `.env.example`):

| Variable                       | Required for                | Description                        |
| ------------------------------ | --------------------------- | ---------------------------------- |
| `DKBSIGN_API_TOKEN`            | All tests                   | Bearer token                       |
| `DKBSIGN_TEST_EMAIL`           | All tests                   | Account email (OTP test assertion) |
| `DKBSIGN_TEST_PDF`             | All tests                   | Path to a sample PDF               |
| `DKBSIGN_TEST_SIGNATURE_IMAGE` | All tests                   | Path to a signature image          |
| `DKBSIGN_BASE_URL`             | Optional                    | Default `https://api.dkbsigns.com` |
| `DKBSIGN_TEST_OTP`             | Self-sign test              | Current OTP code after `sendOtp()` |
| `DKBSIGN_TEST_SIGNER_EMAIL`    | Envelope / invitation tests | External signer inbox              |
| `DKBSIGN_TEST_SIGNER_PHONE`    | Envelope test               | E.164 phone                        |

Run tests:

```bash
cp .env.example .env
# Fill in values, then:
./vendor/bin/phpunit -c phpunit.xml.dist tests
```

`tests/bootstrap.php` loads `.env` then `.env.local` into the environment before PHPUnit runs. If required variables are missing, tests are **skipped** (not failed).

## License

[LICENSE](LICENSE).
