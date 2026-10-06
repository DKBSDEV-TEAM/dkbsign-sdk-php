# DKBSign PHP SDK

PHP client library for the [DKBSign](https://api.dkbsigns.com) electronic signature API. Upload a PDF, define where signatures appear on each page, verify identity with a one-time password (OTP), and receive a signed document URL.

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

Self-signing is a two-step flow:

1. Request an OTP (`sendOtp()`).
2. Submit the PDF, signature image, placement metadata, and OTP (`selfSign()`).

```php
<?php

use DKBSign\DKBSign;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureType;
use DKBSign\Signature;

$client = new DKBSign('https://api.dkbsigns.com', 'your-api-token');

// Step 1: trigger OTP delivery (response includes recipient email, etc.)
$otpResponse = $client->sendOtp();

// Step 2: sign the document (use the OTP code you received)
$response = (new DKBSign('https://api.dkbsigns.com', 'your-api-token'))
    ->file('/path/to/document.pdf')
    ->signatureImage('/path/to/signature.png')
    ->signatureLevel(SignatureLevel::SIMPLE->value)
    ->signature(0, [
        new Signature(
            positionX: 120,
            positionY: 200,
            width: 200,
            height: 70,
            type: SignatureType::SIGNATURE->value,
        ),
    ])
    ->selfSign(123456);

// Example success fields
// $response['signed_pdf_url']
// $response['message']
```

Use a fresh `DKBSign` instance (or rebuild the fluent chain) before each sign request so page and file state from a previous call is not reused.

## How it works

### Client entry point

`DKBSign` is constructed with the API base URL and token. All HTTP calls send `Authorization: Bearer {token}`.

| Method                   | HTTP                             | Purpose                                              |
| ------------------------ | -------------------------------- | ---------------------------------------------------- |
| `sendOtp()`              | `POST {baseUrl}/api/v4/sign/otp` | JSON body; starts OTP for self-signing               |
| `selfSign(int $otpCode)` | `POST {baseUrl}/api/v4/sign`     | Multipart upload of PDF, params, and signature image |

### Fluent configuration

Methods return `$this` so you can chain options before `selfSign()`:

| Method                                    | Description                                                        |
| ----------------------------------------- | ------------------------------------------------------------------ |
| `file(string $path)`                      | Local path to the PDF to sign                                      |
| `signatureImage(string $path)`            | Local path to the image used for the visual signature              |
| `signatureLevel(string $level)`           | Legal/technical level (see `SignatureLevel`)                       |
| `signature(int $page, array $signatures)` | Add one page of placements; call multiple times for multiple pages |

Page numbers are **zero-based** (page `0` is the first page).

### Signature placement

Each `Signature` value object describes a rectangle on the page in PDF coordinates:

- `positionX`, `positionY` — top-left corner
- `width`, `height` — box size
- `signatureType` — visual type (see `SignatureType`)

Pass an array of `Signature` instances to `signature()` for multiple boxes on the same page.

### Multipart payload (`selfSign`)

The SDK sends three parts to `/api/v4/sign`:

1. **`file`** — PDF stream from `file()`
2. **`params`** — JSON string containing:
   - `pages` — list of `{ page, signatures }` built from `signature()` calls
   - `signature_level` — from `signatureLevel()`
   - `otp_code` — argument to `selfSign()`
3. **`signature_image`** — image stream from `signatureImage()`

Responses are decoded from JSON and returned as `array`.

### HTTP layer

`DKBSign\Services\HttpClient` wraps Guzzle:

- `post()` — multipart requests (sign)
- `postJson()` — JSON POST (OTP)

Errors from the API are not wrapped in custom exceptions; failed HTTP status codes will surface as Guzzle exceptions unless you add your own handling around the client.

## Enums

### `SignatureLevel`

| Case        | Value       |
| ----------- | ----------- |
| `SIMPLE`    | `simple`    |
| `ADVANCED`  | `advanced`  |
| `QUALIFIED` | `qualified` |

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

Run tests (integration tests call the live API and require valid credentials and local file paths):

```bash
./vendor/bin/phpunit tests
```

## License

MIT — see [composer.json](composer.json).
