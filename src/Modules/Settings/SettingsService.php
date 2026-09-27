<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Settings;

use CargoVelo\Domain\ServiceCode;

/**
 * Hubs, served postcodes, cut-off times and the service catalogue. Stored in one option,
 * seeded with Cargo Velo's five cities. Editable from the ops desk (manage capability).
 */
final class SettingsService
{
    public const OPTION = 'cargovelo_settings';

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Nothing to hook; settings are read lazily.
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = array_replace(self::defaults(), is_array($stored) ? $stored : []);
        }
        return $this->cache;
    }

    /** @param array<string, mixed> $settings */
    public function update(array $settings): void
    {
        $merged = array_replace($this->all(), $settings);
        update_option(self::OPTION, $merged);
        $this->cache = $merged;
    }

    /** @return list<array{code:string,name:string,city:string,postcodes:list<array{0:int,1:int}>,cutoff_sameday:string}> */
    public function hubs(): array
    {
        return array_values($this->all()['hubs']);
    }

    /** @return array{code:string,name:string,city:string,postcodes:list<array{0:int,1:int}>,cutoff_sameday:string}|null */
    public function hub(string $code): ?array
    {
        foreach ($this->hubs() as $hub) {
            if ($hub['code'] === $code) {
                return $hub;
            }
        }
        return null;
    }

    /** Hub that serves a postcode, or null when outside every served zone. */
    public function hubForPostcode(string $postcode): ?string
    {
        $pc = (int) $postcode;
        foreach ($this->hubs() as $hub) {
            foreach ($hub['postcodes'] as [$from, $to]) {
                if ($pc >= $from && $pc <= $to) {
                    return $hub['code'];
                }
            }
        }
        return null;
    }

    /** @return list<array{code:string,name:string,description:string,sla_minutes:int|null}> */
    public function services(): array
    {
        return array_values($this->all()['services']);
    }

    public function notifyEmail(): string
    {
        return (string) ($this->all()['notify_email'] ?: get_option('admin_email'));
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'hubs' => [
                ['code' => 'GNT', 'name' => 'Hub Gent', 'city' => 'Gent', 'postcodes' => [[9000, 9052]], 'cutoff_sameday' => '16:00'],
                ['code' => 'ANR', 'name' => 'Hub Antwerpen', 'city' => 'Antwerpen', 'postcodes' => [[2000, 2660]], 'cutoff_sameday' => '16:00'],
                ['code' => 'BRU', 'name' => 'Hub Brussel', 'city' => 'Brussel', 'postcodes' => [[1000, 1210]], 'cutoff_sameday' => '16:00'],
                ['code' => 'MEC', 'name' => 'Hub Mechelen', 'city' => 'Mechelen', 'postcodes' => [[2800, 2812]], 'cutoff_sameday' => '15:00'],
                ['code' => 'LEU', 'name' => 'Hub Leuven', 'city' => 'Leuven', 'postcodes' => [[3000, 3012]], 'cutoff_sameday' => '15:00'],
            ],
            'services' => [
                ['code' => ServiceCode::Express->value, 'name' => 'Express', 'description' => 'Ophaling binnen het uur, levering binnen 30 min na ophaling.', 'sla_minutes' => 90],
                ['code' => ServiceCode::SameDay->value, 'name' => 'Same day', 'description' => 'Geboekt voor de cut-off, vandaag geleverd.', 'sla_minutes' => null],
                ['code' => ServiceCode::NextDay->value, 'name' => 'Next day', 'description' => 'Volgende werkdag geleverd.', 'sla_minutes' => null],
                ['code' => ServiceCode::Scheduled->value, 'name' => 'Gepland', 'description' => 'Kies zelf dag en tijdsvenster.', 'sla_minutes' => null],
            ],
            'notify_email' => '',
        ];
    }
}
