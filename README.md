# DKBSign PHP SDK

PHP client library for the [DKBSign](https://api.dkbsigns.com) electronic signature API: self-sign PDFs, send multi-signer envelopes, and integrate with the v4 signature gateway.

## Requirements

- PHP 8.1+ (enums and constructor property promotion)
- [Composer](https://getcomposer.org/)
- Guzzle HTTP client (installed automatically)

## Installation

```bash
composer require dkbs/dkbsign-sdk-php
```

## Quick start

You need a **base URL** (for example `https://api.dkbsigns.com`) and an **API bearer token** from your DKBSign account.

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

$client = new DKBSign('https://api.dkbsigns.com', 'your-api-token');

// Step 1: trigger OTP delivery (response includes recipient email, etc.)
$otpResponse = $client->sendOtp();

// Step 2: sign the document (use the OTP code you received)
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

// Example success fields: $response['signed_pdf_url'], $response['message']
```

Use a fresh `DKBSign` instance (or rebuild the fluent chain) before each sign request so page and file state from a previous call is not reused.

### Send an envelope (multi-signer)

```php
<?php

use DKBSign\DKBSign;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Enums\SignatureType;
use DKBSign\Support\Position;
use DKBSign\Support\Signer;

$response = (new DKBSign('https://api.dkbsigns.com', 'your-api-token'))
    ->setInitiatorName('Acme Corp')
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

// Example success fields: $response['batch_id'], $response['signers'][…]['signing_url']
```

## How it works

### Client entry point

`DKBSign` is constructed with the API base URL and token. All HTTP calls send `Authorization: Bearer {token}`.

| Method              | HTTP                                       | Purpose                                      |
| ------------------- | ------------------------------------------ | -------------------------------------------- |
| `sendOtp()`         | `POST {baseUrl}/api/v4/sign/otp`           | JSON; starts OTP for self-signing            |
| `selfSign($otp)`    | `POST {baseUrl}/api/v4/sign`               | Multipart: PDF, params, signature image      |
| `envelopes()`       | `POST {baseUrl}/api/v4/envelopes`          | Multipart: PDF(s) + JSON `payload`           |
| (after `envelopes`) | `POST {baseUrl}/api/notifications/email`   | Invitation email(s) for the current signing turn |

### Self-sign configuration

Methods return `$this` so you can chain options before `selfSign()`:

| Method                                      | Description                                           |
| ------------------------------------------- | ----------------------------------------------------- |
| `setFile(string $path)`                     | Local path to the PDF to sign                         |
| `setSignatureImage(string $path)`           | Local path to the image used for the visual signature |
| `setSignatureLevel(string $level)`            | Legal/technical level (see `SignatureLevel`)          |
| `addSignature(int $page, Signature[] $sig)` | Add one page of placements; call again for more pages |

Page numbers are **zero-based** (page `0` is the first page). Coordinates are in millimetres from the **bottom-left** of the page (PDF convention), matching the v4 API.

Each `Signature` wraps a `Position` (`x`, `y`, `width`, `height`, optional `page`, `signatureType`) and a field `type` (see `SignatureType`).

### Multipart payload (`selfSign`)

The SDK sends three parts to `/api/v4/sign`:

1. **`file`** — PDF stream
2. **`params`** — JSON: `pages`, `signature_level`, `otp_code`
3. **`signature_image`** — image stream

### Envelope configuration

Chain these before `envelopes()`:

| Method                               | Description                                                |
| ------------------------------------ | ---------------------------------------------------------- |
| `setDocuments(string[] $paths)`      | One or more local PDF paths                                |
| `setEnvelopeTitle(string $title)`    | Title shown to signers                                     |
| `setEnvelopeDescription(string $msg)`| Optional message to signers                                |
| `setSignatureOrder(string $order)`   | `ordered` or `any` (see `SignatureOrder`)                  |
| `setSignatureLevel(string $level)`   | Level imposed on all participants                          |
| `setInitiatorName(string $name)`     | Sender name in invitation emails (default: `DKBSIGN`)       |
| `addSigner(Signer[] $signers)`       | Append signers with contact info and field positions       |

Each `Signer` needs `firstName`, `lastName`, `email`, `phone` (E.164, e.g. `+225…`), `priority`, and a list of `Position` marks.

**Positions per document:** marks are grouped by `Position::$documentIndex` (default `0` = first file in `setDocuments()`). For multiple PDFs, set `documentIndex: 1` on positions that belong to the second document, and so on. The API expects JSON shaped like `{"0": [{page, x, y, …}], "1": […]}`.

### Multipart payload (`envelopes`)

1. **`documents[]`** — one part per PDF (with filename)
2. **`payload`** — JSON string:
   - `title`, `message`
   - `signing_order`, `signature_level`
   - `signers` — `name`, `first_name`, `email`, `phone`, `priority`, `positions`

### Invitations and signing order

Creating an envelope via the API **does not** email signers by itself. The SDK mirrors the web app:

1. **`POST /api/v4/envelopes`** — creates the batch.
2. **`POST /api/notifications/email`** — sends invitation(s) with the secure `signing_url` (and an access code when the API returns `dev_otp_code`).

This second step always runs after a successful `envelopes()` call. Failures are best-effort and do not roll back the envelope.

| `signing_order` | Who gets invited when you call `envelopes()` | Later notifications |
| --------------- | -------------------------------------------- | ------------------- |
| `ordered`       | Signers with the **lowest** `priority` only  | When a signer finishes, the **signature service** emails the next priority group (“your turn”). When everyone has signed, completion emails go out. |
| `any`           | **All** signers immediately                  | Completion emails when the envelope is finished. |

The SDK does not need extra calls for “signer 2” on an ordered envelope—that is handled server-side after each signature.

Signers who open their link without a code in the email can request an OTP on the public signing page (`POST /api/v4/public/envelopes/{signer_uuid}/otp`).

### HTTP layer

`DKBSign\Services\HttpClient` wraps Guzzle:

- `post()` — multipart requests
- `postJson()` — JSON POST (OTP, notification emails)

API errors are not wrapped in custom exceptions; failed HTTP status codes surface as Guzzle exceptions unless you handle them around the client.

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

Run tests (integration tests call the live API and require credentials via environment variables):

```bash
cp .env.example .env.local
# Edit .env.local, then:
export $(grep -v '^#' .env.local | xargs)
./vendor/bin/phpunit -c phpunit.xml.dist tests
```

Without `DKBSIGN_API_TOKEN` and related variables, integration tests are skipped.

## License

MIT — see [composer.json](composer.json).
