<?php

declare(strict_types=1);

use DKBSign\DKBSign;
use DKBSign\Enums\DocumentType;
use DKBSign\Services\HttpResponse;
use DKBSign\Enums\FieldType;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Support\Anchor;
use DKBSign\Support\EmailAttachment;
use DKBSign\Support\EmailTemplate;
use DKBSign\Support\Field;
use DKBSign\Support\IdentityDocument;
use DKBSign\Support\Position;
use DKBSign\Support\QualifiedSigner;
use DKBSign\Support\Signer;
use GuzzleHttp\Exception\RequestException;

require __DIR__.'/bootstrap.php';

/**
 * @return array{ok: bool, status: int|null, payload: mixed}
 */
function runDemo(string $action): array
{
    $client = client();

    return match ($action) {
        'otp' => respond($client->sendOtp()),
        'self-sign' => respond(selfSign($client)),
        'qualified-otp' => respond($client->sendQualifiedOtp(
            email: required('email'),
            channel: posted('channel', 'email'),
            subject: blankToNull(posted('subject')),
            body: blankToNull(posted('body')),
            phoneNumber: blankToNull(posted('phone_number')),
            message: blankToNull(posted('message')),
        )),
        'qualified' => respond(qualifiedSign($client)),
        'envelope' => respond(createEnvelope($client)),
        'invitation' => respond($client->sendEmail(
            recipientEmail: required('recipient_email'),
            subject: required('subject'),
            body: (new EmailTemplate(
                signUrl: required('signing_url'),
                template: blankToNull(posted('template')),
            ))->toHtml(),
            attachments: emailAttachments(),
        )),
        'sms' => respond($client->sendSms(
            phoneNumber: required('phone_number'),
            message: required('message'),
        )),
        'whatsapp' => respond($client->sendWhatsapp(
            phoneNumber: required('phone_number'),
            message: required('message'),
        )),
        'list-documents' => respond($client->listSignedDocuments(
            status: blankToNull(posted('status')),
            page: max(1, (int) posted('page', '1')),
            perPage: max(1, (int) posted('per_page', '20')),
        )),
        'verify' => respond($client->verifyDocument(required('uuid'))),
        'sent' => respond($client->listSentEnvelopes()),
        'received' => respond($client->listReceivedEnvelopes()),
        default => throw new InvalidArgumentException('Unknown action.'),
    };
}

function client(): DKBSign
{
    $token = (string) (getenv('DKBSIGN_API_TOKEN') ?: '');
    if ($token === '') {
        throw new RuntimeException('Set DKBSIGN_API_TOKEN in the project .env file.');
    }

    $baseUrl = (string) (getenv('DKBSIGN_BASE_URL') ?: 'https://api.dkbsigns.com');

    return new DKBSign(rtrim($baseUrl, '/'), $token);
}

function selfSign(DKBSign $client): HttpResponse
{
    $fieldType = posted('field_type', FieldType::SIGNATURE->value);
    $field = mark(
        type: $fieldType,
        text: blankToNull(posted('text')),
    );

    $client
        ->setFile(upload('pdf', true))
        ->setSignatureLevel(posted('signature_level', SignatureLevel::SIMPLE->value))
        ->addField(page: (int) posted('page', '0'), fields: [$field]);

    $image = upload('signature_image', $fieldType === FieldType::SIGNATURE->value);
    if ($image !== null) {
        $client->setSignatureImage($image);
    }

    return $client->selfSign((int) required('otp_code'));
}

function qualifiedSign(DKBSign $client): HttpResponse
{
    $field = mark(
        type: posted('field_type', FieldType::SIGNATURE->value),
        text: blankToNull(posted('text')),
    );

    $client
        ->setFile(upload('pdf', true))
        ->addField(page: (int) posted('page', '0'), fields: [$field])
        ->setQualifiedSigner(new QualifiedSigner(
            firstName: required('first_name'),
            lastName: required('last_name'),
            email: required('email'),
            identityDocument: new IdentityDocument(
                type: posted('document_type', DocumentType::CNI->value),
                number: required('document_number'),
            ),
            phone: required('phone'),
            reason: required('reason'),
        ));

    $image = upload('signature_image', false);
    if ($image !== null) {
        $client->setSignatureImage($image);
    }

    return $client->qualified(required('otp_code'));
}

function createEnvelope(DKBSign $client): HttpResponse
{
    $documents = uploads('documents');
    if ($documents === []) {
        throw new InvalidArgumentException('Upload at least one PDF.');
    }

    $client
        ->setDocuments($documents)
        ->setInitiatorName(posted('initiator', 'DKBSIGN') ?: 'DKBSIGN')
        ->setEnvelopeTitle(required('title'))
        ->setEnvelopeDescription(posted('description'))
        ->setSignatureOrder(posted('signature_order', SignatureOrder::ORDERED->value))
        ->setSignatureLevel(posted('signature_level', SignatureLevel::ADVANCED->value))
        ->setSigners([
            new Signer(
                firstName: required('first_name'),
                lastName: required('last_name'),
                email: required('email'),
                phone: required('phone'),
                priority: max(1, (int) posted('priority', '1')),
                positions: [
                    new Position(
                        x: (float) posted('x', '120'),
                        y: (float) posted('y', '200'),
                        width: (float) posted('width', '200'),
                        height: (float) posted('height', '70'),
                        page: (float) posted('page', '0'),
                        fieldType: posted('field_type', FieldType::SIGNATURE->value),
                        documentIndex: max(0, (int) posted('document_index', '0')),
                    ),
                ],
            ),
        ]);

    $attachment = upload('attachment', false);
    if ($attachment !== null) {
        $client->setAttachments(max(0, (int) posted('attachment_index', '0')), [$attachment]);
    }

    return $client->envelopes();
}

/**
 * @return list<EmailAttachment>
 */
function emailAttachments(): array
{
    $path = upload('attachment', false);
    if ($path === null) {
        return [];
    }

    return [EmailAttachment::fromPath($path)];
}

function mark(string $type, ?string $text): Field
{
    $field = new Field($type);
    if ($text !== null) {
        $field->setText($text);
    }

    if (posted('placement', 'position') === 'anchor') {
        $anchor = new Anchor(
            name: required('anchor'),
            width: (float) posted('width', '200'),
            height: (float) posted('height', '70'),
            occurrence: posted('occurrence') === '' ? null : (int) posted('occurrence'),
        );

        return $field->useAnchor($anchor);
    }

    return $field->usePosition(new Position(
        x: (float) posted('x', '120'),
        y: (float) posted('y', '200'),
        width: (float) posted('width', '200'),
        height: (float) posted('height', '70'),
    ));
}

/**
 * @return array{ok: bool, status: int|null, payload: mixed}
 */
function respond(HttpResponse $response): array
{
    return [
        'ok' => $response->statusCode >= 200 && $response->statusCode < 300,
        'status' => $response->statusCode,
        'payload' => $response->body,
    ];
}

function posted(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function required(string $key): string
{
    $value = posted($key);
    if ($value === '') {
        throw new InvalidArgumentException('Missing '.$key.'.');
    }

    return $value;
}

function blankToNull(string $value): ?string
{
    return $value === '' ? null : $value;
}

function upload(string $field, bool $required): ?string
{
    if (! isset($_FILES[$field]) || ! is_array($_FILES[$field])) {
        if ($required) {
            throw new InvalidArgumentException('Upload a file for '.$field.'.');
        }

        return null;
    }

    $error = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            throw new InvalidArgumentException('Upload a file for '.$field.'.');
        }

        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed for '.$field.'.');
    }

    return moveUpload((string) $_FILES[$field]['tmp_name'], (string) $_FILES[$field]['name']);
}

/**
 * @return list<string>
 */
function uploads(string $field): array
{
    if (! isset($_FILES[$field]['name']) || ! is_array($_FILES[$field]['name'])) {
        return [];
    }

    $paths = [];
    foreach ($_FILES[$field]['name'] as $index => $name) {
        $error = $_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed for '.$field.'.');
        }

        $paths[] = moveUpload((string) $_FILES[$field]['tmp_name'][$index], (string) $name);
    }

    return $paths;
}

function moveUpload(string $tmp, string $originalName): string
{
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?: 'bin';
    $destination = tempnam(sys_get_temp_dir(), 'dkbsign-');
    if ($destination === false || ! unlink($destination)) {
        throw new RuntimeException('Could not store the upload.');
    }

    $path = $destination.'.'.$extension;
    if (! move_uploaded_file($tmp, $path)) {
        throw new RuntimeException('Could not store the upload.');
    }

    return $path;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function field(string $key, string $default = ''): string
{
    return h(array_key_exists($key, $_POST) ? posted($key) : $default);
}

/**
 * @param  array<string, string>  $choices
 */
function options(array $choices, string $selected): string
{
    $html = '';
    foreach ($choices as $value => $label) {
        $isSelected = $value === $selected ? ' selected' : '';
        $html .= '<option value="'.h($value).'"'.$isSelected.'>'.h($label).'</option>';
    }

    return $html;
}

function checked(string $key, string $value, string $default): string
{
    $current = array_key_exists($key, $_POST) ? posted($key) : $default;

    return $current === $value ? ' checked' : '';
}

function placementMode(): string
{
    $current = array_key_exists('placement', $_POST) ? posted('placement') : 'position';

    return $current === 'anchor' ? 'anchor' : 'position';
}

$actions = ['otp', 'self-sign', 'qualified-otp', 'qualified', 'envelope', 'invitation', 'sms', 'whatsapp', 'list-documents', 'verify', 'sent', 'received'];
$result = null;
$error = null;
$action = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = posted('action');
    try {
        if (! in_array($action, $actions, true)) {
            throw new InvalidArgumentException('Unknown action.');
        }
        $result = runDemo($action);
    } catch (RequestException $exception) {
        $response = $exception->getResponse();
        $raw = $response ? (string) $response->getBody() : '';
        $decoded = json_decode($raw, true);
        $error = [
            'status' => $response?->getStatusCode(),
            'payload' => is_array($decoded) ? $decoded : $raw,
            'message' => $exception->getMessage(),
        ];
    } catch (Throwable $exception) {
        $error = [
            'status' => null,
            'payload' => null,
            'message' => $exception->getMessage(),
        ];
    }
}

$active = match ($action) {
    'list-documents', 'verify' => 'documents',
    'sent', 'received' => 'envelopes',
    'invitation', 'sms', 'whatsapp' => 'notifications',
    'otp', 'self-sign' => 'self-sign',
    'qualified', 'qualified-otp' => 'qualified',
    'envelope' => 'envelope',
    default => 'self-sign',
};

$baseUrl = (string) (getenv('DKBSIGN_BASE_URL') ?: 'https://api.dkbsigns.com');
$hasToken = (string) (getenv('DKBSIGN_API_TOKEN') ?: '') !== '';
$signerEmail = (string) (getenv('DKBSIGN_TEST_SIGNER_EMAIL') ?: '');
$signerPhone = (string) (getenv('DKBSIGN_TEST_SIGNER_PHONE') ?: '+2250700000000');
$accountEmail = (string) (getenv('DKBSIGN_TEST_EMAIL') ?: '');
$placement = placementMode();
$fieldType = array_key_exists('field_type', $_POST) ? posted('field_type') : FieldType::SIGNATURE->value;

$levels = [
    SignatureLevel::SIMPLE->value => 'Simple',
    SignatureLevel::ADVANCED->value => 'Advanced',
    SignatureLevel::QUALIFIED->value => 'Qualified',
];
$orders = [
    SignatureOrder::ORDERED->value => 'Ordered',
    SignatureOrder::ANY->value => 'Any',
];
$fieldTypes = [
    FieldType::SIGNATURE->value => 'Signature',
    FieldType::INITIALS->value => 'Initials',
    FieldType::TEXT->value => 'Text',
    FieldType::DATE->value => 'Date',
    FieldType::QRCODE->value => 'QR code',
    FieldType::APPROVAL->value => 'Approval',
    FieldType::SEAL->value => 'Seal',
];
$qualifiedTypes = [
    FieldType::SIGNATURE->value => 'Signature',
    FieldType::INITIALS->value => 'Initials',
    FieldType::TEXT->value => 'Text',
];
$documentTypes = [
    DocumentType::CNI->value => 'CNI',
    DocumentType::PASSPORT->value => 'Passport',
];

$tabs = [
    'self-sign' => 'Self-sign',
    'qualified' => 'Qualified',
    'envelope' => 'Envelope',
    'notifications' => 'Notifications',
    'documents' => 'Documents',
    'envelopes' => 'Envelopes',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DKBSign demo</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #1c1915;
            --muted: #6b645b;
            --line: #e3ddd3;
            --paper: #f6f3ee;
            --card: #fff;
            --accent: #1f4b3a;
            --accent-soft: #e7f0eb;
            --danger: #8d2f2f;
            --danger-soft: #f8ecec;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font: 15px/1.5 "Iowan Old Style", Palatino, "Palatino Linotype", Georgia, serif;
            color: var(--ink);
            background: var(--paper);
        }
        header, main { width: min(920px, calc(100% - 32px)); margin: 0 auto; }
        header { padding: 32px 0 8px; }
        h1 { margin: 0 0 6px; font-size: 28px; font-weight: 600; letter-spacing: -0.02em; }
        .lede { margin: 0; color: var(--muted); }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.92em; }
        .banner {
            margin-top: 16px;
            padding: 10px 12px;
            border: 1px solid #e2c8c8;
            background: var(--danger-soft);
            color: var(--danger);
        }
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 22px 0 16px;
        }
        .tab {
            border: 1px solid var(--line);
            background: transparent;
            color: var(--ink);
            padding: 7px 12px;
            font: inherit;
            cursor: pointer;
        }
        .tab[aria-selected="true"] {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        .panel {
            background: var(--card);
            border: 1px solid var(--line);
            padding: 20px;
            margin-bottom: 28px;
        }
        .panel h2 { margin: 0 0 6px; font-size: 20px; }
        .hint { margin: 0 0 16px; color: var(--muted); }
        form { display: grid; gap: 12px; }
        form + form { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--line); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        [hidden] { display: none !important; }
        label { display: grid; gap: 4px; font-size: 13px; color: var(--muted); }
        input, select, textarea {
            width: 100%;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            padding: 8px 10px;
            font: 14px/1.4 ui-sans-serif, system-ui, sans-serif;
        }
        textarea { min-height: 88px; resize: vertical; }
        .choice { display: flex; gap: 16px; align-items: center; color: var(--ink); font-size: 14px; }
        .choice label { display: flex; gap: 6px; align-items: center; color: var(--ink); font-size: 14px; }
        button[type="submit"] {
            justify-self: start;
            border: 0;
            background: var(--accent);
            color: #fff;
            padding: 9px 14px;
            font: inherit;
            cursor: pointer;
        }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .result {
            margin: 0 0 16px;
            padding: 12px;
            background: #221f1b;
            color: #f6f3ee;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .result.bad { background: #3a2222; }
        .meta { color: #c8bfb2; }
    </style>
</head>
<body<?= $action !== '' ? ' data-active="'.h($active).'"' : '' ?>>
<header>
    <h1>DKBSign demo</h1>
    <p class="lede">Calls the SDK against <code><?= h($baseUrl) ?></code>. Credentials stay in the project <code>.env</code>. Run <code>php -S localhost:8080 -t demo</code> from the repository root.</p>
    <?php if (! $hasToken): ?>
        <p class="banner">Set <code>DKBSIGN_API_TOKEN</code> in <code>.env</code> before submitting a form.</p>
    <?php endif; ?>
    <nav class="tabs" role="tablist">
        <?php foreach ($tabs as $id => $label): ?>
            <button class="tab" type="button" role="tab" data-tab="<?= h($id) ?>" aria-selected="<?= $id === $active ? 'true' : 'false' ?>"><?= h($label) ?></button>
        <?php endforeach; ?>
    </nav>
</header>
<main>
    <?php
    $resultBlock = '';
    if ($error !== null) {
        $body = $error['payload'] !== null
            ? json_encode($error['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $error['message'];
        $statusLine = $error['status'] !== null ? 'HTTP '.$error['status'] : 'Request failed';
        $resultBlock = '<pre class="result bad"><span class="meta">'.h($statusLine)."</span>\n".h((string) $body).'</pre>';
    } elseif ($result !== null) {
        $body = json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $resultBlock = '<pre class="result"><span class="meta">HTTP '.h((string) $result['status'])."</span>\n".h((string) $body).'</pre>';
    }
    ?>

    <section class="panel" id="panel-self-sign" <?= $active === 'self-sign' ? '' : 'hidden' ?>>
        <h2>Self-sign</h2>
        <p class="hint">Two steps. First send a code. It is emailed to the account behind the API token<?= $accountEmail !== '' ? ' (<code>'.h($accountEmail).'</code>)' : '' ?>. Then upload the PDF and submit that code. A signature image is used for a signature mark. The text field is used for a text mark. Coordinates are millimetres from the bottom-left. Page numbers start at 0.</p>
        <?= $active === 'self-sign' ? $resultBlock : '' ?>
        <form method="post">
            <input type="hidden" name="action" value="otp">
            <button type="submit">Send OTP</button>
        </form>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="self-sign">
            <div class="grid">
                <label>PDF<input type="file" name="pdf" accept="application/pdf" required></label>
                <label data-field="signature"<?= $fieldType === FieldType::SIGNATURE->value ? '' : ' hidden' ?>>Signature image<input type="file" name="signature_image" accept="image/png,image/jpeg" required<?= $fieldType === FieldType::SIGNATURE->value ? '' : ' disabled' ?>></label>
                <label>OTP code<input name="otp_code" inputmode="numeric" value="<?= field('otp_code') ?>" required></label>
                <label>Level
                    <select name="signature_level"><?= options($levels, posted('signature_level', SignatureLevel::SIMPLE->value)) ?></select>
                </label>
                <label>Field type
                    <select name="field_type"><?= options($fieldTypes, posted('field_type', FieldType::SIGNATURE->value)) ?></select>
                </label>
                <label>Page<input name="page" type="number" min="0" value="<?= field('page', '0') ?>"></label>
            </div>
            <div class="choice">
                <label><input type="radio" name="placement" value="position"<?= checked('placement', 'position', 'position') ?>> Position</label>
                <label><input type="radio" name="placement" value="anchor"<?= checked('placement', 'anchor', 'position') ?>> Anchor</label>
            </div>
            <div class="grid" data-placement="position"<?= $placement === 'position' ? '' : ' hidden' ?>>
                <label>X<input name="x" value="<?= field('x', '120') ?>"<?= $placement === 'position' ? '' : ' disabled' ?>></label>
                <label>Y<input name="y" value="<?= field('y', '200') ?>"<?= $placement === 'position' ? '' : ' disabled' ?>></label>
                <label>Width<input name="width" value="<?= field('width', '200') ?>"<?= $placement === 'position' ? '' : ' disabled' ?>></label>
                <label>Height<input name="height" value="<?= field('height', '70') ?>"<?= $placement === 'position' ? '' : ' disabled' ?>></label>
            </div>
            <div class="grid" data-placement="anchor"<?= $placement === 'anchor' ? '' : ' hidden' ?>>
                <label>Anchor text<input name="anchor" value="<?= field('anchor', '{{signature}}') ?>" placeholder="{{signature}}"<?= $placement === 'anchor' ? '' : ' disabled' ?>></label>
                <label>Occurrence<input name="occurrence" value="<?= field('occurrence') ?>" placeholder="optional"<?= $placement === 'anchor' ? '' : ' disabled' ?>></label>
                <label>Width<input name="width" value="<?= field('width', '200') ?>"<?= $placement === 'anchor' ? '' : ' disabled' ?>></label>
                <label>Height<input name="height" value="<?= field('height', '70') ?>"<?= $placement === 'anchor' ? '' : ' disabled' ?>></label>
            </div>
            <div class="grid" data-field="text"<?= $fieldType === FieldType::TEXT->value ? '' : ' hidden' ?>>
                <label>Text<input name="text" value="<?= field('text') ?>" placeholder="for a text mark"<?= $fieldType === FieldType::TEXT->value ? '' : ' disabled' ?>></label>
            </div>
            <button type="submit">Sign</button>
        </form>
    </section>

    <section class="panel" id="panel-qualified" <?= $active === 'qualified' ? '' : 'hidden' ?>>
        <h2>Qualified signature</h2>
        <p class="hint">Two steps. First send a code on the channel you choose. Put <code>{{otp_code}}</code> in the text. Then submit the PDF with that code. The API checks it before signing. The level is always qualified. A signature image is optional and only used by signature marks.</p>
        <?= $active === 'qualified' ? $resultBlock : '' ?>
        <form method="post">
            <input type="hidden" name="action" value="qualified-otp">
            <div class="grid">
                <label>Channel
                    <select name="channel"><?= options(['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'], posted('channel', 'email')) ?></select>
                </label>
                <label>Signer email<input name="email" type="email" value="<?= field('email', $signerEmail) ?>" required></label>
                <label>Subject<input name="subject" value="<?= field('subject', 'Votre code de signature') ?>"></label>
                <label>Phone<input name="phone_number" value="<?= field('phone_number', $signerPhone) ?>" placeholder="+225…"></label>
            </div>
            <label>Email body<textarea name="body" rows="4"><?= field('body', '<p>Votre code est {{otp_code}}.</p>') ?></textarea></label>
            <label>SMS or WhatsApp message<input name="message" value="<?= field('message', 'Votre code est {{otp_code}}') ?>"></label>
            <button type="submit">Send OTP to signer</button>
        </form>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="qualified">
            <div class="grid">
                <label>PDF<input type="file" name="pdf" accept="application/pdf" required></label>
                <label data-field="signature"<?= $fieldType === FieldType::SIGNATURE->value ? '' : ' hidden' ?>>Signature image<input type="file" name="signature_image" accept="image/png,image/jpeg"<?= $fieldType === FieldType::SIGNATURE->value ? '' : ' disabled' ?>></label>
                <label>First name<input name="first_name" value="<?= field('first_name') ?>" required></label>
                <label>Last name<input name="last_name" value="<?= field('last_name') ?>" required></label>
                <label>Email<input name="email" type="email" value="<?= field('email', $signerEmail) ?>" required></label>
                <label>Phone<input name="phone" value="<?= field('phone', $signerPhone) ?>" required></label>
                <label>Document type
                    <select name="document_type"><?= options($documentTypes, posted('document_type', DocumentType::CNI->value)) ?></select>
                </label>
                <label>Document number<input name="document_number" value="<?= field('document_number') ?>" required></label>
                <label>Reason<input name="reason" value="<?= field('reason', 'Acceptation du contrat') ?>" required></label>
                <label>OTP code<input name="otp_code" inputmode="numeric" value="<?= field('otp_code') ?>" required></label>
                <label>Field type
                    <select name="field_type"><?= options($qualifiedTypes, posted('field_type', FieldType::SIGNATURE->value)) ?></select>
                </label>
                <label>Page<input name="page" type="number" min="0" value="<?= field('page', '0') ?>"></label>
            </div>
            <div class="choice">
                <label><input type="radio" name="placement" value="position"<?= checked('placement', 'position', 'position') ?>> Position</label>
                <label><input type="radio" name="placement" value="anchor"<?= checked('placement', 'anchor', 'position') ?>> Anchor</label>
            </div>
            <div class="grid" data-placement="position"<?= $placement === 'position' ? '' : ' hidden' ?>>
                <label>X<input name="x" value="<?= field('x', '120') ?>"<?= $placement === 'position' ? '' : ' disabled' ?>></label>
                <label>Y<input name="y" value="<?= field('y', '200') ?>"<?= $placement === 'position' ? '' : ' disabled' ?>></label>
                <label>Width<input name="width" value="<?= field('width', '200') ?>"<?= $placement === 'position' ? '' : ' disabled' ?>></label>
                <label>Height<input name="height" value="<?= field('height', '70') ?>"<?= $placement === 'position' ? '' : ' disabled' ?>></label>
            </div>
            <div class="grid" data-placement="anchor"<?= $placement === 'anchor' ? '' : ' hidden' ?>>
                <label>Anchor text<input name="anchor" value="<?= field('anchor', '{{signature}}') ?>"<?= $placement === 'anchor' ? '' : ' disabled' ?>></label>
                <label>Occurrence<input name="occurrence" value="<?= field('occurrence') ?>" placeholder="optional"<?= $placement === 'anchor' ? '' : ' disabled' ?>></label>
                <label>Width<input name="width" value="<?= field('width', '200') ?>"<?= $placement === 'anchor' ? '' : ' disabled' ?>></label>
                <label>Height<input name="height" value="<?= field('height', '70') ?>"<?= $placement === 'anchor' ? '' : ' disabled' ?>></label>
            </div>
            <div class="grid" data-field="text"<?= $fieldType === FieldType::TEXT->value ? '' : ' hidden' ?>>
                <label>Text<input name="text" value="<?= field('text') ?>" placeholder="for a text mark"<?= $fieldType === FieldType::TEXT->value ? '' : ' disabled' ?>></label>
            </div>
            <button type="submit">Sign</button>
        </form>
    </section>

    <section class="panel" id="panel-envelope" <?= $active === 'envelope' ? '' : 'hidden' ?>>
        <h2>Create envelope</h2>
        <p class="hint">Creates the batch and returns each signer’s <code>signing_url</code>. It does not send email. Attachments are stored with a document and are not signed.</p>
        <?= $active === 'envelope' ? $resultBlock : '' ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="envelope">
            <div class="grid">
                <label>PDFs<input type="file" name="documents[]" accept="application/pdf" multiple required></label>
                <label>Attachment<input type="file" name="attachment"></label>
                <label>Attachment document index<input name="attachment_index" type="number" min="0" value="<?= field('attachment_index', '0') ?>"></label>
                <label>Title<input name="title" value="<?= field('title', 'NDA') ?>" required></label>
                <label>Description<input name="description" value="<?= field('description', 'Please sign this document') ?>"></label>
                <label>Initiator<input name="initiator" value="<?= field('initiator', 'DKBSIGN') ?>"></label>
                <label>Order
                    <select name="signature_order"><?= options($orders, posted('signature_order', SignatureOrder::ORDERED->value)) ?></select>
                </label>
                <label>Level
                    <select name="signature_level"><?= options($levels, posted('signature_level', SignatureLevel::ADVANCED->value)) ?></select>
                </label>
            </div>
            <div class="grid">
                <label>First name<input name="first_name" value="<?= field('first_name') ?>" required></label>
                <label>Last name<input name="last_name" value="<?= field('last_name') ?>" required></label>
                <label>Email<input name="email" type="email" value="<?= field('email', $signerEmail) ?>" required></label>
                <label>Phone<input name="phone" value="<?= field('phone', $signerPhone) ?>" required></label>
                <label>Priority<input name="priority" type="number" min="1" value="<?= field('priority', '1') ?>"></label>
                <label>Field type
                    <select name="field_type"><?= options($fieldTypes, posted('field_type', FieldType::SIGNATURE->value)) ?></select>
                </label>
                <label>Document index<input name="document_index" type="number" min="0" value="<?= field('document_index', '0') ?>"></label>
                <label>Page<input name="page" type="number" min="0" value="<?= field('page', '0') ?>"></label>
                <label>X<input name="x" value="<?= field('x', '120') ?>"></label>
                <label>Y<input name="y" value="<?= field('y', '200') ?>"></label>
                <label>Width<input name="width" value="<?= field('width', '200') ?>"></label>
                <label>Height<input name="height" value="<?= field('height', '70') ?>"></label>
            </div>
            <button type="submit">Create envelope</button>
        </form>
    </section>

    <section class="panel" id="panel-notifications" <?= $active === 'notifications' ? '' : 'hidden' ?>>
        <h2>Notifications</h2>
        <p class="hint">Same token for email, SMS, and WhatsApp. Delivery is asynchronous (HTTP 202 means accepted). SMS and WhatsApp share <code>POST /api/notifications/sms</code>; WhatsApp sets <code>channel</code> to <code>whatsapp</code>. <code>phone_number</code> is E.164 and starts with <code>+</code>. <code>message</code> is at most 255 characters. A read-only license returns 403 <code>LICENSE_READONLY</code>.</p>
        <?= $active === 'notifications' ? $resultBlock : '' ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="invitation">
            <div class="grid">
                <label>Recipient email<input name="recipient_email" type="email" value="<?= field('recipient_email', $signerEmail) ?>" required></label>
                <label>Subject<input name="subject" value="<?= field('subject', 'Document awaiting signature') ?>" required></label>
                <label>Signing URL<input name="signing_url" value="<?= field('signing_url') ?>" required></label>
                <label>Attachment<input type="file" name="attachment"></label>
            </div>
            <label>Custom HTML body<textarea name="template" placeholder="optional"><?= field('template') ?></textarea></label>
            <button type="submit">Queue email</button>
        </form>
        <form method="post">
            <input type="hidden" name="action" value="sms">
            <div class="grid">
                <label>Phone<input name="phone_number" value="<?= field('phone_number', $signerPhone) ?>" required></label>
                <label>Message<input name="message" maxlength="255" value="<?= field('message', 'Your code is 00990') ?>" required></label>
            </div>
            <button type="submit">Queue SMS</button>
        </form>
        <form method="post">
            <input type="hidden" name="action" value="whatsapp">
            <div class="grid">
                <label>Phone<input name="phone_number" value="<?= field('phone_number', $signerPhone) ?>" required></label>
                <label>Message<input name="message" maxlength="255" value="<?= field('message', 'Your code is 00990') ?>" required></label>
            </div>
            <button type="submit">Queue WhatsApp</button>
        </form>
    </section>

    <section class="panel" id="panel-documents" <?= $active === 'documents' ? '' : 'hidden' ?>>
        <h2>Documents</h2>
        <p class="hint">List documents on the account, then verify one by UUID.</p>
        <?= $active === 'documents' ? $resultBlock : '' ?>
        <form method="post">
            <input type="hidden" name="action" value="list-documents">
            <div class="grid">
                <label>Status<input name="status" value="<?= field('status', 'signed') ?>" placeholder="signed, or empty for all"></label>
                <label>Page<input name="page" type="number" min="1" value="<?= field('page', '1') ?>"></label>
                <label>Per page<input name="per_page" type="number" min="1" value="<?= field('per_page', '20') ?>"></label>
            </div>
            <button type="submit">List documents</button>
        </form>
        <form method="post">
            <input type="hidden" name="action" value="verify">
            <div class="grid">
                <label>Document UUID<input name="uuid" value="<?= field('uuid') ?>" required></label>
            </div>
            <button type="submit">Verify</button>
        </form>
    </section>

    <section class="panel" id="panel-envelopes" <?= $active === 'envelopes' ? '' : 'hidden' ?>>
        <h2>Envelopes</h2>
        <p class="hint">Sent envelopes are ones this account created. Received envelopes are ones where this account is a signer.</p>
        <?= $active === 'envelopes' ? $resultBlock : '' ?>
        <div class="actions">
            <form method="post">
                <input type="hidden" name="action" value="sent">
                <button type="submit">List sent</button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="received">
                <button type="submit">List received</button>
            </form>
        </div>
    </section>
</main>
<script>
    const tabs = document.querySelectorAll('.tab');
    const panels = document.querySelectorAll('.panel');

    function show(id) {
        const known = document.getElementById('panel-' + id);
        if (!known) {
            return;
        }
        tabs.forEach((tab) => tab.setAttribute('aria-selected', tab.dataset.tab === id ? 'true' : 'false'));
        panels.forEach((panel) => {
            panel.hidden = panel.id !== 'panel-' + id;
        });
    }

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            show(tab.dataset.tab);
            history.replaceState(null, '', '#' + tab.dataset.tab);
        });
    });

    function syncPlacement(form) {
        const selected = form.querySelector('input[name="placement"]:checked');
        if (!selected) {
            return;
        }
        form.querySelectorAll('[data-placement]').forEach((group) => {
            const active = group.dataset.placement === selected.value;
            group.hidden = !active;
            group.querySelectorAll('input').forEach((input) => {
                input.disabled = !active;
            });
        });
    }

    function syncFieldType(form) {
        const select = form.querySelector('select[name="field_type"]');
        if (!select) {
            return;
        }
        form.querySelectorAll('[data-field]').forEach((group) => {
            const active = group.dataset.field === select.value;
            group.hidden = !active;
            group.querySelectorAll('input').forEach((input) => {
                input.disabled = !active;
            });
        });
    }

    document.querySelectorAll('form').forEach((form) => {
        if (form.querySelector('input[name="placement"]')) {
            syncPlacement(form);
            form.addEventListener('change', (event) => {
                if (event.target.name === 'placement') {
                    syncPlacement(form);
                }
            });
        }
        if (form.querySelector('select[name="field_type"]')) {
            syncFieldType(form);
            form.addEventListener('change', (event) => {
                if (event.target.name === 'field_type') {
                    syncFieldType(form);
                }
            });
        }
    });

    const posted = document.body.dataset.active;
    const hash = location.hash.replace('#', '');
    if (posted) {
        show(posted);
    } else if (hash) {
        show(hash);
    } else {
        show('self-sign');
    }
</script>
</body>
</html>
