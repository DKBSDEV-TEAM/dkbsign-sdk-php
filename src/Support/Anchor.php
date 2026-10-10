<?php

declare(strict_types=1);

namespace DKBSign\Support;

final class Anchor
{
    public function __construct(
        public string $name,
        public float $width,
        public float $height,
        public ?float $page = null,
        public ?string $signatureType = null,
        public int $documentIndex = 0,
        public ?int $occurrence = null,
    ) {}
}
