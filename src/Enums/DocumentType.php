<?php

declare(strict_types=1);

namespace DKBSign\Enums;

enum DocumentType: string
{
    case CNI = 'cni';

    case PASSPORT = 'passport';
}
