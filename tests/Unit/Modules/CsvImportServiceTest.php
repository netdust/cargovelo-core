<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Modules;

use CargoVelo\Modules\Import\CsvImportService;
use CargoVelo\Tests\TestCase;

final class CsvImportServiceTest extends TestCase
{
    public function testTemplateParsesIntoAShipmentInput(): void
    {
        $parsed = CsvImportService::parse(CsvImportService::template());
        self::assertArrayNotHasKey('error', $parsed);
        self::assertCount(1, $parsed['rows']);
        $input = $parsed['rows'][0]['input'];
        self::assertSame('sameday', $input['service']);
        self::assertSame('UZ Gent', $input['pickup']['company']);
        self::assertSame('9000', $input['delivery']['postcode']);
        self::assertSame(2, $input['parcels'][0]['count']);
        self::assertTrue($input['parcels'][0]['fragile']);
        self::assertTrue($input['parcels'][0]['cooled']);
        self::assertNull($input['pickup_window']['start']);
    }

    public function testCommaSeparatedWithDateAndBom(): void
    {
        $csv = "\xEF\xBB\xBFservice,pickup_street,delivery_street,pickup_date,pickup_time_from,pickup_time_to\nscheduled,\"Korenmarkt, 1\",Veldstraat,2026-10-01,09:30,11:00\n";
        $parsed = CsvImportService::parse($csv);
        self::assertArrayNotHasKey('error', $parsed);
        $input = $parsed['rows'][0]['input'];
        self::assertSame('Korenmarkt, 1', $input['pickup']['street']);
        self::assertSame('2026-10-01T09:30:00', $input['pickup_window']['start']);
        self::assertSame('2026-10-01T11:00:00', $input['pickup_window']['end']);
    }

    public function testUnknownColumnIsRejected(): void
    {
        $parsed = CsvImportService::parse("service;pickup_street;delivery_street;price\nx;y;z;1\n");
        self::assertStringContainsString('Onbekende kolommen: price', $parsed['error']);
    }

    public function testRowWithWrongColumnCountIsReportedPerLine(): void
    {
        $parsed = CsvImportService::parse("service;pickup_street;delivery_street\nsameday;A\nsameday;A;B\n");
        self::assertCount(2, $parsed['rows']);
        self::assertSame(2, $parsed['rows'][0]['line']);
        self::assertStringContainsString('2 kolommen gevonden', $parsed['rows'][0]['error']);
        self::assertArrayHasKey('input', $parsed['rows'][1]);
    }
}
