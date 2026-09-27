<?php
declare(strict_types=1);

namespace CargoVelo\Domain;

/** Application role as seen by services. Derived from WordPress capabilities by AccessContext. */
enum Role: string
{
    case Admin = 'admin';
    case Dispatcher = 'dispatcher';
    case Courier = 'courier';
    case Customer = 'customer';
    case Guest = 'guest';

    public function isStaff(): bool
    {
        return $this === self::Admin || $this === self::Dispatcher;
    }
}
