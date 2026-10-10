<?php

declare(strict_types=1);

use DKBSign\DKBSign;
use DKBSign\Enums\DocumentType;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Enums\SignatureType;
use DKBSign\Support\Anchor;
use DKBSign\Support\EmailTemplate;
use DKBSign\Support\IdentityDocument;
use DKBSign\Support\Position;
use DKBSign\Support\QualifiedSigner;
use DKBSign\Support\Signature;
use DKBSign\Support\Signer;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    protected string $baseUrl;

    protected string $apiToken;

    protected string $filePath;

    protected string $signaturePath;

    protected string $email;

    protected int $otpCode;

    protected Generator $faker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->faker = Factory::create();

        $this->baseUrl = getenv('DKBSIGN_BASE_URL') ?: 'https://api.dkbsigns.com';
        $this->apiToken = getenv('DKBSIGN_API_TOKEN') ?: '';
        $this->email = getenv('DKBSIGN_TEST_EMAIL') ?: '';
        $this->filePath = getenv('DKBSIGN_TEST_PDF') ?: '';
        $this->signaturePath = getenv('DKBSIGN_TEST_SIGNATURE_IMAGE') ?: '';
        $this->otpCode = 522872;

        if ($this->apiToken === '' || $this->email === '' || $this->filePath === '' || $this->signaturePath === '') {
            $this->markTestSkipped(
                'Set DKBSIGN_API_TOKEN, DKBSIGN_TEST_EMAIL, DKBSIGN_TEST_PDF, and DKBSIGN_TEST_SIGNATURE_IMAGE to run integration tests.'
            );
        }
    }

    public function test_can_send_otp(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)->sendOtp();

        $this->assertTrue($response->statusCode === 200);
        $this->assertTrue($response->body['email'] === $this->email);
    }

    public function test_can_self_sign(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile($this->filePath)
            ->setSignatureImage($this->signaturePath)
            ->setSignatureLevel(SignatureLevel::SIMPLE->value)
            ->addSignature(0, [
                new Signature(SignatureType::SIGNATURE->value)
                    ->usePosition(
                        new Position(
                            x: 120,
                            y: 200,
                            width: 200,
                            height: 70,
                        )
                    ),
            ])
            ->selfSign($this->otpCode);

        $this->assertTrue($response->statusCode === 200);
    }

    public function test_can_self_sign_with_anchor(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile(getenv('DKBSIGN_TEST_PDF_WITH_ANCRAGE'))
            ->setSignatureImage($this->signaturePath)
            ->setSignatureLevel(SignatureLevel::SIMPLE->value)
            ->addSignature(0, [
                new Signature(SignatureType::SIGNATURE->value)
                    ->useAnchor(
                        new Anchor(
                            name: '{{signature}}',
                            width: 200,
                            height: 70,
                        )
                    ),
            ])
            ->selfSign($this->otpCode);

        $this->assertTrue($response->statusCode === 200);
    }

    public function test_can_sign_qualified(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile(getenv('DKBSIGN_TEST_PDF_WITH_ANCRAGE'))
            ->setSignatureImage($this->signaturePath)
            ->addSignature(0, [
                new Signature(SignatureType::SIGNATURE->value)
                    ->useAnchor(
                        new Anchor(
                            name: '-signature-',
                            width: 200,
                            height: 70,
                        )
                    ),
            ])
            ->qualified(new QualifiedSigner(
                firstName: $this->faker->firstName,
                lastName: $this->faker->lastName,
                email: $this->email,
                identityDocument: new IdentityDocument(
                    type: DocumentType::CNI->value,
                    number: 'CI000000000000',
                ),
                phone: getenv('DKBSIGN_TEST_SIGNER_PHONE') ?: null,
                reason: 'Acceptation du contrat',
            ));

        $this->assertTrue($response->statusCode === 200);
    }

    public function test_can_sign_qualified_with_position(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile($this->filePath)
            ->setSignatureImage($this->signaturePath)
            ->addSignature(0, [
                new Signature(SignatureType::SIGNATURE->value)
                    ->usePosition(
                        new Position(
                            x: 120,
                            y: 200,
                            width: 200,
                            height: 70,
                        )
                    ),
            ])
            ->qualified(new QualifiedSigner(
                firstName: $this->faker->firstName,
                lastName: $this->faker->lastName,
                email: $this->email,
                identityDocument: new IdentityDocument(
                    type: DocumentType::CNI->value,
                    number: 'CI000000000000',
                ),
                phone: getenv('DKBSIGN_TEST_SIGNER_PHONE') ?: null,
                reason: 'Acceptation du contrat',
            ));

        $this->assertTrue($response->statusCode === 200);
    }

    public function test_can_create_envelope(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setDocuments([$this->filePath])
            ->setInitiatorName($this->faker->company)
            ->setEnvelopeTitle($this->faker->word)
            ->setEnvelopeDescription($this->faker->sentence)
            ->setSignatureOrder(SignatureOrder::ORDERED->value)
            ->setSignatureLevel(SignatureLevel::ADVANCED->value)
            ->addSigner([
                new Signer(
                    firstName: $this->faker->firstName,
                    lastName: $this->faker->lastName,
                    email: getenv('DKBSIGN_TEST_SIGNER_EMAIL'),
                    phone: getenv('DKBSIGN_TEST_SIGNER_PHONE'),
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
                )]
            )
            ->envelopes();

        $this->assertTrue($response->statusCode === 200);
    }

    public function test_can_send_invitation(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->sendInvitation(
                recipientEmail: getenv('DKBSIGN_TEST_SIGNER_EMAIL'),
                subject: 'Document awaiting for signature',
                emailTemplate: new EmailTemplate('https://signature.link')
            );

        $this->assertTrue($response->statusCode === 202);
    }
}
