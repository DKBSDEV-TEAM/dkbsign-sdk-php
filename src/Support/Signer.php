<?php

declare(strict_types=1);

namespace DKBSign\Support;

final class Signer
{
    /**
     * @param  array<int, Position>  $positions
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public int $priority,
        public array $positions,
        public ?Anchor $anchor = null,
        public ?string $reason = null
    ) {}
}
