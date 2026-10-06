<?php

declare(strict_types=1);

namespace DKBSign\Enums;

enum SignatureType: string
{
    case SIGNATURE = 'signature';

    case INITIALS = 'initials';

    case SEAL = 'seal';

    case QRCODE = 'qrcode';

    case DATE = 'date';

    case TEXT = 'text';

    case APPROVAL = 'approval';
}
