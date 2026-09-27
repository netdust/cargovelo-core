<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Modules;

use CargoVelo\Domain\Parcels;
use CargoVelo\Domain\ServiceCode;
use CargoVelo\Modules\Pricing\PricingService;
use CargoVelo\Tests\TestCase;

final class PricingServiceTest extends TestCase
{
    private const RULE = ['base_cents' => 990, 'extra_parcel_cents' => 250, 'surcharges' => ['m' => 250, 'l' => 500, 'fragile' => 200, 'cooled' => 400]];

    public function testSingleSmallParcelIsBasePrice(): void
    {
        $q = PricingService::compute(ServiceCode::SameDay, Parcels::fromArray([['count' => 1, 'weight_class' => 's']]), self::RULE);
        self::assertSame(990, $q['price_cents']);
        self::assertCount(1, $q['breakdown']);
        self::assertSame('EUR', $q['currency']);
    }

    public function testExtrasAndSurchargesAdd(): void
    {
        $q = PricingService::compute(ServiceCode::SameDay, Parcels::fromArray([
            ['count' => 2, 'weight_class' => 's', 'fragile' => true],
            ['count' => 1, 'weight_class' => 'l', 'cooled' => true],
        ]), self::RULE);
        // 990 + 2 extra * 250 + heaviest L 500 + fragile 200 + cooled 400
        self::assertSame(990 + 500 + 500 + 200 + 400, $q['price_cents']);
        self::assertSame(['Same day', '2 extra pakketten', 'Gewichtsklasse L', 'Breekbaar', 'Gekoeld transport'], array_column($q['breakdown'], 'label'));
        self::assertSame($q['price_cents'], array_sum(array_column($q['breakdown'], 'cents')));
    }

    public function testMissingSurchargeKeysAreZero(): void
    {
        $q = PricingService::compute(ServiceCode::Express, Parcels::fromArray([['count' => 1, 'weight_class' => 'xl', 'fragile' => true]]), ['base_cents' => 1490, 'extra_parcel_cents' => 0, 'surcharges' => []]);
        self::assertSame(1490, $q['price_cents']);
    }
}
