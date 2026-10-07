<?php

declare(strict_types=1);

use DKBSign\DKBSign;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Enums\SignatureType;
use DKBSign\Support\Position;
use DKBSign\Support\Signature;
use DKBSign\Support\Signer;
use PHPUnit\Framework\TestCase;

final class SelfSignatureTest extends TestCase
{
    protected string $baseUrl;

    protected string $apiToken;

    protected string $filePath;

    protected string $signaturePath;

    protected string $email;

    protected int $otpCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = getenv('DKBSIGN_BASE_URL') ?: 'https://api.dkbsigns.com';
        $this->apiToken = getenv('DKBSIGN_API_TOKEN') ?: '';
        $this->email = getenv('DKBSIGN_TEST_EMAIL') ?: '';
        $this->filePath = getenv('DKBSIGN_TEST_PDF') ?: '';
        $this->signaturePath = getenv('DKBSIGN_TEST_SIGNATURE_IMAGE') ?: '';
        $this->otpCode = (int) (getenv('DKBSIGN_TEST_OTP') ?: '0');

        if ($this->apiToken === '' || $this->email === '' || $this->filePath === '' || $this->signaturePath === '') {
            $this->markTestSkipped(
                'Set DKBSIGN_API_TOKEN, DKBSIGN_TEST_EMAIL, DKBSIGN_TEST_PDF, and DKBSIGN_TEST_SIGNATURE_IMAGE to run integration tests.'
            );
        }
    }

    public function test_can_send_otp(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)->sendOtp();

        $this->assertArrayHasKey('email', $response);
        $this->assertTrue($response['email'] === $this->email);
    }

    public function test_can_self_sign(): void
    {
        if ($this->otpCode <= 0) {
            $this->markTestSkipped('Set DKBSIGN_TEST_OTP to run self-sign integration test.');
        }

        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile($this->filePath)
            ->setSignatureImage($this->signaturePath)
            ->setSignatureLevel(SignatureLevel::SIMPLE->value)
            ->addSignature(0, [
                new Signature(
                    position: new Position(
                        x: 120,
                        y: 200,
                        width: 200,
                        height: 70,
                    ),
                    type: SignatureType::SIGNATURE->value
                ),
            ])
            ->selfSign($this->otpCode);

        $this->assertArrayHasKey('signed_pdf_url', $response);
    }

    public function test_can_create_envelope(): void
    {
        $signerEmail = getenv('DKBSIGN_TEST_SIGNER_EMAIL') ?: '';
        if ($signerEmail === '') {
            $this->markTestSkipped('Set DKBSIGN_TEST_SIGNER_EMAIL to run envelope integration test.');
        }

        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setDocuments([$this->filePath])
            ->setInitiatorName(getenv('DKBSIGN_TEST_INITIATOR_NAME') ?: 'Integration Test')
            ->setEnvelopeTitle('NDA')
            ->setEnvelopeDescription('Please sign this NDA')
            ->setSignatureOrder(SignatureOrder::ORDERED->value)
            ->setSignatureLevel(SignatureLevel::ADVANCED->value)
            ->addSigner([
                new Signer(
                    firstName: 'Sarah',
                    lastName: 'Doe',
                    email: $signerEmail,
                    phone: getenv('DKBSIGN_TEST_SIGNER_PHONE') ?: '+2250000000000',
                    priority: 1,
                    positions: [
                        new Position(
                            x: 120,
                            y: 200,
                            width: 200,
                            height: 70,
                            page: 0,
                            signatureType: SignatureType::SIGNATURE->value
                        ),
                    ]
                ),
            ])
            ->envelopes();

        $this->assertArrayHasKey('batch_id', $response);
    }
}
