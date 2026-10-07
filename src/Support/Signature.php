<?php

declare(strict_types=1);

namespace DKBSign\Support;

use DKBSign\Enums\SignatureType;

final class Signature
{
    public function __construct(
        public Position $position,
        public string $type = SignatureType::SIGNATURE->value
    ) {}
}
