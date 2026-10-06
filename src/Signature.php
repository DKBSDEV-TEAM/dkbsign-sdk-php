<?php

declare(strict_types=1);

namespace DKBSign;

use DKBSign\Enums\SignatureType;

class Signature
{
    public function __construct(
        public float $positionX,
        public float $positionY,
        public float $width,
        public float $height,
        public string $signatureType = SignatureType::SIGNATURE->value
    ) {}
}
