<?php
declare(strict_types=1);

namespace CargoVelo\Domain;

/**
 * The one shipment lifecycle. Every status change, from every channel, goes through
 * ShipmentService::transition(), which consults TRANSITIONS. The frontend mirrors this
 * table in app/src/domain/status.ts for hiding buttons only.
 */
enum ShipmentStatus: string
{
    case Draft = 'draft';
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'draft' => ['requested', 'cancelled'],
        'requested' => ['confirmed', 'cancelled'],
        'confirmed' => ['assigned', 'cancelled'],
        'assigned' => ['picked_up', 'confirmed', 'cancelled'],
        'picked_up' => ['in_transit', 'delivered', 'failed'],
        'in_transit' => ['delivered', 'failed'],
        'delivered' => [],
        'failed' => ['confirmed', 'cancelled'],
        'cancelled' => [],
    ];

    public function canTransitionTo(self $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$this->value], true);
    }

    /** @return list<self> */
    public function nextStatuses(): array
    {
        return array_map(static fn(string $s): self => self::from($s), self::TRANSITIONS[$this->value]);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Confirmed, self::Assigned, self::PickedUp, self::InTransit], true);
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Cancelled], true);
    }

    /** Statuses a courier may set on their own stops. */
    public static function courierSettable(): array
    {
        return [self::PickedUp, self::InTransit, self::Delivered, self::Failed];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_filter(self::values(), static fn(string $v): bool => self::from($v)->isOpen()));
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Ontwerp',
            self::Requested => 'Aangevraagd',
            self::Confirmed => 'Bevestigd',
            self::Assigned => 'Toegewezen',
            self::PickedUp => 'Opgehaald',
            self::InTransit => 'Onderweg',
            self::Delivered => 'Geleverd',
            self::Failed => 'Mislukt',
            self::Cancelled => 'Geannuleerd',
        };
    }
}
