<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Domain;

use CargoVelo\Domain\Address;
use CargoVelo\Domain\Parcels;
use CargoVelo\Domain\WeightClass;
use CargoVelo\Tests\TestCase;
use WP_Error;

final class AddressAndParcelsTest extends TestCase
{
    public function testAddressRequiresCoreFields(): void
    {
        $r = Address::fromArray(['name' => 'Jan', 'street' => 'Veldstraat'], 'Leveradres');
        self::assertInstanceOf(WP_Error::class, $r);
        self::assertSame('invalid_address', $r->get_error_code());
        self::assertSame(['number', 'postcode', 'city'], $r->get_error_data()['missing']);
    }

    public function testAddressRejectsNonBelgianPostcode(): void
    {
        $r = Address::fromArray(['name' => 'Jan', 'street' => 'A', 'number' => '1', 'postcode' => '1012 AB', 'city' => 'Amsterdam']);
        self::assertInstanceOf(WP_Error::class, $r);
        self::assertSame('invalid_postcode', $r->get_error_code());
    }

    public function testAddressNormalisesAndRoundTrips(): void
    {
        $a = Address::fromArray(['name' => ' Jan ', 'street' => 'Veldstraat', 'number' => '20', 'box' => '3', 'postcode' => ' 9000 ', 'city' => 'Gent', 'email' => 'jan@example.be']);
        self::assertInstanceOf(Address::class, $a);
        self::assertSame('Veldstraat 20 bus 3, 9000 Gent', $a->line());
        self::assertSame('Jan', $a->toArray()['name']);
        self::assertSame('Achteraan', $a->withInstructions(' Achteraan ')->instructions);
    }

    public function testParcelsValidateAndAggregate(): void
    {
        $p = Parcels::fromArray([
            ['count' => 2, 'weight_class' => 's', 'fragile' => 'ja', 'cooled' => false],
            ['count' => 1, 'weight_class' => 'L', 'fragile' => false, 'cooled' => '1'],
        ]);
        self::assertInstanceOf(Parcels::class, $p);
        self::assertSame(3, $p->totalCount());
        self::assertTrue($p->hasFragile());
        self::assertTrue($p->hasCooled());
        self::assertSame(WeightClass::L, $p->heaviest());
        self::assertSame('l', $p->toArray()[1]['weight_class']);
    }

    public function testParcelsRejectEmptyAndUnknownWeight(): void
    {
        self::assertInstanceOf(WP_Error::class, Parcels::fromArray([]));
        self::assertInstanceOf(WP_Error::class, Parcels::fromArray([['count' => 1, 'weight_class' => 'huge']]));
        self::assertInstanceOf(WP_Error::class, Parcels::fromArray([['count' => 0, 'weight_class' => 's']]));
    }
}
