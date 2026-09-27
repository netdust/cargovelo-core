<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Modules;

use CargoVelo\Modules\Settings\SettingsService;
use CargoVelo\Tests\TestCase;

final class SettingsServiceTest extends TestCase
{
    public function testDefaultZonesCoverTheFiveCities(): void
    {
        $s = new SettingsService();
        self::assertSame('GNT', $s->hubForPostcode('9000'));
        self::assertSame('ANR', $s->hubForPostcode('2018'));
        self::assertSame('BRU', $s->hubForPostcode('1050'));
        self::assertSame('MEC', $s->hubForPostcode('2800'));
        self::assertSame('LEU', $s->hubForPostcode('3001'));
        self::assertNull($s->hubForPostcode('8000'));
    }

    public function testStoredSettingsOverrideDefaultsAndPersist(): void
    {
        $s = new SettingsService();
        $s->update(['hubs' => [['code' => 'BRG', 'name' => 'Hub Brugge', 'city' => 'Brugge', 'postcodes' => [[8000, 8380]], 'cutoff_sameday' => '15:00']]]);
        self::assertSame('BRG', $s->hubForPostcode('8000'));
        self::assertNull($s->hubForPostcode('9000'));
        self::assertSame('15:00', $s->hub('BRG')['cutoff_sameday']);
        self::assertCount(4, $s->services());
        self::assertSame('BRG', (new SettingsService())->hubForPostcode('8200'));
    }
}
