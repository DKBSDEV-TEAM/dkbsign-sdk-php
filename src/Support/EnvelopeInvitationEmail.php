<?php

declare(strict_types=1);

namespace DKBSign\Support;

final class EnvelopeInvitationEmail
{
    public static function build(
        string $recipientEmail,
        string $documentName,
        string $senderName,
        string $signUrl,
        ?string $accessCode = null,
        ?string $recipientName = null,
    ): array {
        $documentEsc = self::escape($documentName);
        $senderEsc = self::escape($senderName);
        $signUrlEsc = self::escape($signUrl);
        $greeting = $recipientName
            ? 'Bonjour '.self::escape($recipientName).','
            : 'Bonjour,';

        $codeBlock = '';
        if ($accessCode !== null && $accessCode !== '') {
            $codeBlock = '<p style="margin:16px 0;"><strong>Code d\'accès :</strong> '
                .self::escape($accessCode).'</p>';
        }

        $body = <<<HTML
<p style="margin:0 0 16px;">{$greeting}</p>
<p style="margin:0 0 16px;"><strong>{$senderEsc}</strong> vous invite à signer le document <strong>{$documentEsc}</strong>.</p>
<p style="margin:0 0 16px;"><a href="{$signUrlEsc}" style="display:inline-block;background:#2563eb;color:#ffffff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:600;">Accéder au document</a></p>
{$codeBlock}
<p style="margin:16px 0 0;color:#64748b;font-size:13px;">Si le bouton ne fonctionne pas, copiez ce lien : {$signUrlEsc}</p>
HTML;

        $subject = 'DKBSIGN — Signature requise : '.self::safeSubject($documentName);

        return [
            'email' => $recipientEmail,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function safeSubject(string $value): string
    {
        return mb_substr(preg_replace('/[\r\n]+/', ' ', $value) ?? $value, 0, 255);
    }
}
