<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Modules;

use CargoVelo\Modules\Shipment\ShipmentFilter;
use CargoVelo\Tests\TestCase;

final class ShipmentFilterTest extends TestCase
{
    public function testParsesAndSanitises(): void
    {
        $f = ShipmentFilter::fromParams([
            'status' => 'requested,confirmed,bogus',
            'service' => ['express', 'nope'],
            'hub' => 'GNT',
            'customer_id' => '7',
            'date_from' => '2026-09-01',
            'date_to' => 'yesterday',
            'search' => '  CV-26 ',
            'exception' => 'true',
            'page' => '0',
            'per_page' => '9999',
            'order' => 'ASC',
        ]);
        self::assertSame(['requested', 'confirmed'], $f->status);
        self::assertSame(['express'], $f->service);
        self::assertSame('GNT', $f->hub);
        self::assertSame(7, $f->customerId);
        self::assertSame('2026-09-01', $f->dateFrom);
        self::assertNull($f->dateTo);
        self::assertSame('CV-26', $f->search);
        self::assertTrue($f->exception);
        self::assertSame(1, $f->page);
        self::assertSame(200, $f->perPage);
        self::assertSame('asc', $f->order);
    }

    public function testDefaults(): void
    {
        $f = ShipmentFilter::fromParams([]);
        self::assertSame([], $f->status);
        self::assertNull($f->exception);
        self::assertSame(25, $f->perPage);
        self::assertSame('desc', $f->order);
    }
}
