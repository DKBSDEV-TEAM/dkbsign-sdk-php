<?php

declare(strict_types=1);

namespace DKBSign\Support;

final class IdentityDocument
{
    public function __construct(
        public string $type,
        public string $number,
    ) {}
}
