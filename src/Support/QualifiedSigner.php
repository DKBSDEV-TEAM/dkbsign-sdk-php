<?php

declare(strict_types=1);

namespace DKBSign\Support;

final class QualifiedSigner
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public IdentityDocument $identityDocument,
        public ?string $phone = null,
        public ?string $reason = null,
    ) {}
}
