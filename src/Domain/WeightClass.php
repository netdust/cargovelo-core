<?php
declare(strict_types=1);

namespace CargoVelo\Domain;

enum WeightClass: string
{
    case XS = 'xs';
    case S = 's';
    case M = 'm';
    case L = 'l';
    case XL = 'xl';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function maxKg(): int
    {
        return match ($this) {
            self::XS => 2,
            self::S => 5,
            self::M => 15,
            self::L => 30,
            self::XL => 60,
        };
    }
}
