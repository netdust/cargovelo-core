<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Domain;

use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Tests\TestCase;

final class ShipmentStatusTest extends TestCase
{
    public function testHappyPathIsReachable(): void
    {
        $path = ['requested', 'confirmed', 'assigned', 'picked_up', 'in_transit', 'delivered'];
        for ($i = 0; $i < count($path) - 1; $i++) {
            self::assertTrue(ShipmentStatus::from($path[$i])->canTransitionTo(ShipmentStatus::from($path[$i + 1])), "{$path[$i]} -> {$path[$i + 1]}");
        }
    }

    public function testClosedStatusesAreTerminalExceptFailed(): void
    {
        self::assertSame([], ShipmentStatus::Delivered->nextStatuses());
        self::assertSame([], ShipmentStatus::Cancelled->nextStatuses());
        self::assertTrue(ShipmentStatus::Failed->canTransitionTo(ShipmentStatus::Confirmed));
        self::assertFalse(ShipmentStatus::Failed->canTransitionTo(ShipmentStatus::Delivered));
    }

    public function testCannotSkipPickup(): void
    {
        self::assertFalse(ShipmentStatus::Assigned->canTransitionTo(ShipmentStatus::Delivered));
        self::assertFalse(ShipmentStatus::Confirmed->canTransitionTo(ShipmentStatus::InTransit));
    }

    public function testOpenAndClosedPartitionAllStatusesExceptDraft(): void
    {
        foreach (ShipmentStatus::cases() as $s) {
            if ($s === ShipmentStatus::Draft) {
                continue;
            }
            self::assertTrue($s->isOpen() xor $s->isClosed(), $s->value);
        }
        self::assertSame(['requested', 'confirmed', 'assigned', 'picked_up', 'in_transit'], ShipmentStatus::openValues());
    }

    public function testEveryTransitionTargetIsAKnownStatus(): void
    {
        foreach (ShipmentStatus::TRANSITIONS as $from => $targets) {
            self::assertNotNull(ShipmentStatus::tryFrom($from));
            foreach ($targets as $t) {
                self::assertNotNull(ShipmentStatus::tryFrom($t), "{$from} -> {$t}");
            }
        }
    }
}
