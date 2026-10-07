<?php

declare(strict_types=1);

namespace DKBSign\Services;

final class HttpResponse
{
    public function __construct(
        public array $body,
        public int $statusCode
    ) {}
}
