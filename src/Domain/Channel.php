<?php
declare(strict_types=1);

namespace CargoVelo\Domain;

enum Channel: string
{
    case Web = 'web';
    case Portal = 'portal';
    case Import = 'import';
    case Api = 'api';
    case Phone = 'phone';
    case Recurring = 'recurring';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
