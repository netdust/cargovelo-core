<?php
declare(strict_types=1);

namespace CargoVelo\Domain;

enum ServiceCode: string
{
    case Express = 'express';
    case SameDay = 'sameday';
    case NextDay = 'nextday';
    case Scheduled = 'scheduled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Express => 'Express',
            self::SameDay => 'Same day',
            self::NextDay => 'Next day',
            self::Scheduled => 'Gepland',
        };
    }

    public function slaMinutes(): ?int
    {
        return match ($this) {
            self::Express => 90,
            self::SameDay => null,
            self::NextDay => null,
            self::Scheduled => null,
        };
    }
}
