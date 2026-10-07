<?php

declare(strict_types=1);

namespace DKBSign\Enums;

enum SignatureOrder: string
{
    case ORDERED = 'ordered';

    case ANY = 'any';
}
