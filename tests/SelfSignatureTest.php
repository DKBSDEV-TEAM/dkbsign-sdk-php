<?php

declare(strict_types=1);

use DKBSign\DKBSign;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureType;
use DKBSign\Signature;
use PHPUnit\Framework\TestCase;

final class SelfSignatureTest extends TestCase
{
    public function test_can_send_otp(): void
    {
        $response = new DKBSign('https://api.dkbsigns.com', '296|8eZcio82NqzaKM6PnxN3XpjNOZ3yqBEV8o9JTWBg109a0f75')->sendOtp();

        $this->assertArrayHasKey('email', $response);
        $this->assertTrue($response['email'] === 'elisee.nguessan@dkbsolutions.com');
    }

    public function test_can_self_sign(): void
    {
        $response = new DKBSign('https://api.dkbsigns.com', '296|8eZcio82NqzaKM6PnxN3XpjNOZ3yqBEV8o9JTWBg109a0f75')
            ->file('/Users/m1pro2021/Documents/sample-local-pdf.pdf')
            ->signatureImage('/Users/m1pro2021/Pictures/signature.png')
            ->signatureLevel(SignatureLevel::SIMPLE->value)
            ->signature(0, [
                new Signature(
                    positionX: 120,
                    positionY: 200,
                    width: 200,
                    height: 70,
                    signatureType: SignatureType::SIGNATURE->value
                ),
            ])
            ->selfSign(886990);

        $this->assertArrayHasKey('signed_pdf_url', $response);
        $this->assertTrue($response['message'] === 'Document signé avec succès');
    }
}
