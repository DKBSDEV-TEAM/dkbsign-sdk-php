<?php

declare(strict_types=1);

namespace DKBSign\Support;

final class Position
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?float $page = null,
        public ?string $fieldType = null,
        public int $documentIndex = 0,
    ) {}
}
