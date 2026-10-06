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
        $response = new DKBSign('https://api.dkbsigns.com', 'api-token')->sendOtp();

        $this->assertArrayHasKey('email', $response);
        $this->assertTrue($response['email'] === 'john@doe.com');
    }

    public function test_can_self_sign(): void
    {
        $response = new DKBSign('https://api.dkbsigns.com', 'api-token')
            ->file('/path/to/document.pdf')
            ->signatureImage('/path/to/signature.png')
            ->signatureLevel(SignatureLevel::SIMPLE->value)
            ->signature(0, [
                new Signature(
                    positionX: 120,
                    positionY: 200,
                    width: 200,
                    height: 70,
                    type: SignatureType::SIGNATURE->value
                ),
            ])
            ->selfSign(886990);

        $this->assertArrayHasKey('signed_pdf_url', $response);
        $this->assertTrue($response['message'] === 'Document signé avec succès');
    }
}
