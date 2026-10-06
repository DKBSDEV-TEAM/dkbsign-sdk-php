<?php

declare(strict_types=1);

namespace DKBSign\Enums;

enum SignatureLevel: string
{
    case SIMPLE = 'simple';

    case ADVANCED = 'advanced';

    case QUALIFIED = 'qualified';
}
