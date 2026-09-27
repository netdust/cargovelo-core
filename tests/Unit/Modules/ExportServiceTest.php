<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Modules;

use CargoVelo\Modules\Export\ExportService;
use CargoVelo\Tests\TestCase;

final class ExportServiceTest extends TestCase
{
    public function testRendersOneLinePerShipmentWithFrozenPrice(): void
    {
        $csv = ExportService::render([
            $this->shipmentRow(['status' => 'delivered', 'courier_id' => 3, 'updated_at' => '2026-09-28T15:42:00']),
            $this->shipmentRow(['id' => 43, 'reference' => 'CV-260928-0002', 'price_cents' => 2000, 'price_override_reason' => 'Wachttijd']),
        ], [3 => 'Kris']);
        $lines = explode("\n", trim(substr($csv, 3)));
        self::assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
        self::assertStringStartsWith('referentie;klant_id;klant;geleverd_op', $lines[0]);
        self::assertStringContainsString('CV-260928-0001;7;"Bloemen Bea";"2026-09-28 15:42";sameday;GNT', $lines[1]);
        self::assertStringContainsString(';2;14,40;;Kris;portal', $lines[1]);
        self::assertStringContainsString('20,00;Wachttijd;;portal', $lines[2]);
    }
}
