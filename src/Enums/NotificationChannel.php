<?php

declare(strict_types=1);

namespace DKBSign\Enums;

enum NotificationChannel: string
{
    case SMS = 'sms';

    case WHATSAPP = 'whatsapp';

    case EMAIL = 'email';
}
