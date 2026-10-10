<?php

declare(strict_types=1);

use DKBSign\DKBSign;
use DKBSign\Enums\DocumentType;
use DKBSign\Enums\FieldType;
use DKBSign\Enums\SignatureLevel;
use DKBSign\Enums\SignatureOrder;
use DKBSign\Support\Anchor;
use DKBSign\Support\EmailTemplate;
use DKBSign\Support\Field;
use DKBSign\Support\IdentityDocument;
use DKBSign\Support\Position;
use DKBSign\Services\HttpResponse;
use DKBSign\Support\QualifiedSigner;
use DKBSign\Support\Signer;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    protected string $baseUrl;

    protected string $apiToken;

    protected string $filePath;

    protected string $attachmentPath;

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
        $this->attachmentPath = getenv('DKBSIGN_TEST_PDF_ATTACHMENT') ?: '';
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

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('Code de vérification envoyé', $response->body['message']);
        $this->assertSame($this->email, $response->body['email']);
        $this->assertGreaterThan(0, $response->body['expires_in_minutes']);
    }

    public function test_can_self_sign(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile($this->filePath)
            ->setSignatureImage($this->signaturePath)
            ->setSignatureLevel(SignatureLevel::SIMPLE->value)
            ->addField(page: 0, fields: [
                new Field(FieldType::SIGNATURE->value)
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

        $this->assertSignedPdf($response);
    }

    public function test_can_self_sign_with_anchor(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile(getenv('DKBSIGN_TEST_PDF_WITH_ANCHOR'))
            ->setSignatureImage($this->signaturePath)
            ->setSignatureLevel(SignatureLevel::SIMPLE->value)
            ->addField(page: 0, fields: [
                new Field(FieldType::SIGNATURE->value)
                    ->useAnchor(
                        new Anchor(
                            name: '{{signature}}',
                            width: 200,
                            height: 70,
                        )
                    ),
            ])
            ->selfSign($this->otpCode);

        $this->assertSignedPdf($response);
    }

    public function test_can_sign_qualified(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile(getenv('DKBSIGN_TEST_PDF_WITH_ANCHOR'))
            ->setSignatureImage($this->signaturePath)
            ->addField(page: 0, fields: [
                new Field(FieldType::SIGNATURE->value)
                    ->useAnchor(
                        new Anchor(
                            name: '{{signature}}',
                            width: 200,
                            height: 70,
                        )
                    ),
            ])
            ->setQualifiedSigner(
                new QualifiedSigner(
                    firstName: $firstName = $this->faker->firstName,
                    lastName: $lastName = $this->faker->lastName,
                    email: $this->email,
                    identityDocument: new IdentityDocument(
                        type: DocumentType::CNI->value,
                        number: 'CI000000000000',
                    ),
                    phone: getenv('DKBSIGN_TEST_SIGNER_PHONE') ?: '+2250000000000',
                    reason: 'Acceptation du contrat',
                )
            )
            ->qualified();

        $this->assertQualifiedSignature($response, $firstName, $lastName);
    }

    public function test_can_sign_qualified_with_position(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile($this->filePath)
            ->setSignatureImage($this->signaturePath)
            ->addField(page: 0, fields: [
                new Field(FieldType::SIGNATURE->value)
                    ->usePosition(
                        new Position(
                            x: 120,
                            y: 200,
                            width: 200,
                            height: 70,
                        )
                    ),
            ])
            ->setQualifiedSigner(
                new QualifiedSigner(
                    firstName: $firstName = $this->faker->firstName,
                    lastName: $lastName = $this->faker->lastName,
                    email: $this->email,
                    identityDocument: new IdentityDocument(
                        type: DocumentType::CNI->value,
                        number: 'CI000000000000',
                    ),
                    phone: getenv('DKBSIGN_TEST_SIGNER_PHONE') ?: '+2250000000000',
                    reason: 'Acceptation du contrat',
                )
            )
            ->qualified();

        $this->assertQualifiedSignature($response, $firstName, $lastName);
    }

    public function test_can_create_envelope(): void
    {
        $title = $this->faker->word;
        $signerEmail = getenv('DKBSIGN_TEST_SIGNER_EMAIL');

        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setDocuments([$this->filePath])
            ->setInitiatorName($this->faker->company)
            ->setEnvelopeTitle($title)
            ->setEnvelopeDescription($this->faker->sentence)
            ->setSignatureOrder(SignatureOrder::ORDERED->value)
            ->setSignatureLevel(SignatureLevel::ADVANCED->value)
            ->setSigners([
                new Signer(
                    firstName: $this->faker->firstName,
                    lastName: $this->faker->lastName,
                    email: $signerEmail,
                    phone: getenv('DKBSIGN_TEST_SIGNER_PHONE'),
                    priority: 1,
                    positions: [
                        new Position(
                            x: 120,
                            y: 200,
                            width: 200,
                            height: 70,
                            page: 0,
                            fieldType: FieldType::SIGNATURE->value
                        ),
                    ]
                )]
            )
            ->envelopes();

        $this->assertCreatedEnvelope($response, $title, $signerEmail, 1);
    }

    public function test_can_create_envelope_with_multiple_documents(): void
    {
        $title = $this->faker->word;
        $signerEmail = getenv('DKBSIGN_TEST_SIGNER_EMAIL');

        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setDocuments([$this->filePath, $this->filePath])
            ->setAttachments(documentIndex: 0, paths: [$this->attachmentPath])
            ->setAttachments(documentIndex: 1, paths: [$this->attachmentPath])
            ->setInitiatorName($this->faker->company)
            ->setEnvelopeTitle($title)
            ->setEnvelopeDescription($this->faker->sentence)
            ->setSignatureOrder(SignatureOrder::ORDERED->value)
            ->setSignatureLevel(SignatureLevel::ADVANCED->value)
            ->setSigners([
                new Signer(
                    firstName: $this->faker->firstName,
                    lastName: $this->faker->lastName,
                    email: $signerEmail,
                    phone: getenv('DKBSIGN_TEST_SIGNER_PHONE'),
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
                    ]
                ),
            ])
            ->envelopes();

        $this->assertCreatedEnvelope($response, $title, $signerEmail, 2, 1);
    }

    public function test_can_create_envelope_with_multiple_fields(): void
    {
        $title = $this->faker->word;
        $signerEmail = getenv('DKBSIGN_TEST_SIGNER_EMAIL');

        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setDocuments([$this->filePath])
            ->setAttachments(documentIndex: 0, paths: [$this->attachmentPath])
            ->setInitiatorName($this->faker->company)
            ->setEnvelopeTitle($title)
            ->setEnvelopeDescription($this->faker->sentence)
            ->setSignatureOrder(SignatureOrder::ANY->value)
            ->setSignatureLevel(SignatureLevel::ADVANCED->value)
            ->setSigners([
                new Signer(
                    firstName: $this->faker->firstName,
                    lastName: $this->faker->lastName,
                    email: $signerEmail,
                    phone: getenv('DKBSIGN_TEST_SIGNER_PHONE'),
                    priority: 1,
                    positions: [
                        new Position(x: 120, y: 200, width: 200, height: 70, page: 0, fieldType: FieldType::SIGNATURE->value),
                        new Position(x: 20, y: 200, width: 80, height: 40, page: 0, fieldType: FieldType::INITIALS->value),
                        new Position(x: 20, y: 140, width: 80, height: 80, page: 0, fieldType: FieldType::QRCODE->value),
                        new Position(x: 120, y: 140, width: 180, height: 40, page: 0, fieldType: FieldType::TEXT->value),
                        new Position(x: 120, y: 90, width: 120, height: 30, page: 0, fieldType: FieldType::DATE->value),
                        new Position(x: 120, y: 40, width: 160, height: 30, page: 0, fieldType: FieldType::APPROVAL->value),
                    ]
                ),
            ])
            ->envelopes();

        $this->assertCreatedEnvelope($response, $title, $signerEmail, 1, 1);
    }

    public function test_can_self_sign_with_multiple_fields(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile($this->filePath)
            ->setSignatureImage($this->signaturePath)
            ->setSignatureLevel(SignatureLevel::SIMPLE->value)
            ->addField(page: 0, fields: [
                new Field(FieldType::SIGNATURE->value)->usePosition(new Position(x: 120, y: 200, width: 200, height: 70)),
                new Field(FieldType::INITIALS->value)->usePosition(new Position(x: 20, y: 200, width: 80, height: 40)),
                new Field(FieldType::QRCODE->value)->usePosition(new Position(x: 20, y: 120, width: 80, height: 80)),
                new Field(FieldType::TEXT->value)
                    ->setText('Lorem ipsum dolor sit amet')
                    ->usePosition(new Position(x: 120, y: 120, width: 180, height: 40)),
            ])
            ->selfSign($this->otpCode);

        $this->assertSignedPdf($response);
    }

    public function test_can_sign_qualified_with_multiple_fields(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->setFile($this->filePath)
            ->setSignatureImage($this->signaturePath)
            ->addField(page: 0, fields: [
                new Field(FieldType::SIGNATURE->value)->usePosition(new Position(x: 120, y: 200, width: 200, height: 70)),
                new Field(FieldType::INITIALS->value)->usePosition(new Position(x: 20, y: 200, width: 80, height: 40)),
                new Field(FieldType::TEXT->value)
                    ->setText('Lorem ipsum dolor sit amet')
                    ->usePosition(new Position(x: 120, y: 120, width: 180, height: 40)),
            ])
            ->setQualifiedSigner(
                new QualifiedSigner(
                    firstName: $firstName = $this->faker->firstName,
                    lastName: $lastName = $this->faker->lastName,
                    email: $this->email,
                    identityDocument: new IdentityDocument(
                        type: DocumentType::CNI->value,
                        number: 'CI000000000000',
                    ),
                    phone: getenv('DKBSIGN_TEST_SIGNER_PHONE') ?: '+2250000000000',
                    reason: 'Acceptation du contrat',
                )
            )
            ->qualified();

        $this->assertQualifiedSignature($response, $firstName, $lastName);
    }

    public function test_can_send_invitation(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->sendInvitation(
                recipientEmail: getenv('DKBSIGN_TEST_SIGNER_EMAIL'),
                subject: 'Document awaiting for signature',
                emailTemplate: new EmailTemplate('https://signature.link')
            );

        $this->assertSame(202, $response->statusCode);
        $this->assertSame('success', $response->body['status']);
        $this->assertSame('Notification sent successfuly', $response->body['message']);
    }

    public function test_can_list_signed_documents(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)
            ->listSignedDocuments(status: 'signed');

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($response->body['documents']);
        $this->assertSame(1, $response->body['page']);
        $this->assertArrayHasKey('total', $response->body);

        foreach ($response->body['documents'] as $document) {
            $this->assertSame('signed', $document['status']);
            $this->assertNotSame('', $document['uuid']);
        }
    }

    public function test_can_verify_signed_document(): void
    {
        $client = new DKBSign($this->baseUrl, $this->apiToken);
        $listed = $client->listSignedDocuments(status: 'signed');

        $this->assertSame(200, $listed->statusCode);

        if ($listed->body['documents'] === []) {
            $this->markTestSkipped('No signed document is available to verify.');
        }

        $uuid = $listed->body['documents'][0]['uuid'];
        $response = $client->verifyDocument($uuid);

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->body['valid']);
        $this->assertSame($uuid, $response->body['document']['uuid']);
        $this->assertSame('signed', $response->body['document']['status']);
        $this->assertNotSame('', $response->body['signer']['name']);
    }

    public function test_can_list_sent_envelopes(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)->listSentEnvelopes();

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($response->body['envelopes']);

        foreach ($response->body['envelopes'] as $envelope) {
            $this->assertNotSame('', $envelope['batch_id']);
            $this->assertContains($envelope['status'], ['pending', 'in_progress', 'completed', 'refused', 'cancelled']);
            $this->assertIsArray($envelope['documents']);
            $this->assertIsArray($envelope['signers']);
        }
    }

    public function test_can_list_received_envelopes(): void
    {
        $response = new DKBSign($this->baseUrl, $this->apiToken)->listReceivedEnvelopes();

        $this->assertSame(200, $response->statusCode);
        $this->assertIsArray($response->body['envelopes']);

        foreach ($response->body['envelopes'] as $envelope) {
            $this->assertNotSame('', $envelope['batch_id']);
            $this->assertContains($envelope['status'], ['pending', 'in_progress', 'completed', 'refused', 'cancelled']);
            $this->assertIsArray($envelope['documents']);
            $this->assertArrayHasKey('can_sign', $envelope);
        }
    }

    private function assertSignedPdf(HttpResponse $response): void
    {
        $this->assertSame(200, $response->statusCode);
        $this->assertSame('Document signé avec succès', $response->body['message']);
        $this->assertNotSame('', $response->body['document_uuid']);
        $this->assertStringContainsString($response->body['document_uuid'], $response->body['signed_pdf_url']);
        $this->assertStringContainsString(
            '/api/v4/verify/'.$response->body['document_uuid'],
            $response->body['verification_url']
        );
    }

    private function assertQualifiedSignature(HttpResponse $response, string $firstName, string $lastName): void
    {
        $this->assertSignedPdf($response);
        $signer = $response->body['signer'];
        $this->assertSame(strtolower($this->email), $signer['email']);
        $this->assertSame($firstName, $signer['first_name']);
        $this->assertSame($lastName, $signer['last_name']);
        $this->assertSame('Acceptation du contrat', $signer['reason']);
        $this->assertSame('cni', $signer['id_card']['document_type']);
        $this->assertSame('CI••••0000', $signer['id_card']['document_number_masked']);
        $this->assertNotSame('', $response->body['certificate']['serial']);
        $this->assertNotSame('', $response->body['certificate']['expires_at']);
    }

    private function assertCreatedEnvelope(
        HttpResponse $response,
        string $title,
        string $signerEmail,
        int $documentCount,
        int $attachmentsPerDocument = 0,
    ): void {
        $this->assertSame(201, $response->statusCode);
        $this->assertSame($title, $response->body['title']);
        $this->assertNotSame('', $response->body['batch_id']);
        $this->assertCount($documentCount, $response->body['documents']);
        $this->assertCount(1, $response->body['signers']);
        $this->assertSame(strtolower($signerEmail), strtolower($response->body['signers'][0]['email']));
        $this->assertSame('pending', $response->body['signers'][0]['status']);
        $this->assertNotSame('', $response->body['signers'][0]['signing_url']);

        foreach ($response->body['documents'] as $document) {
            $this->assertSame('pending', $document['status']);
            $this->assertCount($attachmentsPerDocument, $document['attachments']);
        }
    }
}
