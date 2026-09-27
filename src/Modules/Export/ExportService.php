<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Export;

use CargoVelo\Modules\Courier\CourierRepository;
use CargoVelo\Modules\Shipment\ShipmentRepository;

/**
 * Delivered shipments with their frozen price as CSV, per customer per period, for the
 * accounting package. Stride's rule applies: this system quotes and exports, it never invoices.
 */
final class ExportService
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly CourierRepository $couriers,
    ) {
    }

    public function deliveredCsv(string $from, string $to, ?int $customerId = null): string
    {
        $rows = $this->shipments->deliveredBetween($from, $to, $customerId);
        $names = [];
        foreach ($this->couriers->all() as $c) {
            $names[(int) $c->id] = (string) $c->name;
        }
        return self::render($rows, $names);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $courierNames
     */
    public static function render(array $rows, array $courierNames = []): string
    {
        $out = fopen('php://temp', 'r+');
        $header = ['referentie', 'klant_id', 'klant', 'geleverd_op', 'dienst', 'hub', 'ophaling', 'levering', 'pakketten', 'prijs_eur', 'prijs_aangepast', 'koerier', 'kanaal'];
        fputcsv($out, $header, ';', '"', '\\');
        foreach ($rows as $s) {
            $line = static fn(array $a): string => sprintf('%s, %s %s%s, %s %s', $a['name'] ?? '', $a['street'] ?? '', $a['number'] ?? '', !empty($a['box']) ? ' bus ' . $a['box'] : '', $a['postcode'] ?? '', $a['city'] ?? '');
            $parcels = array_sum(array_map(static fn(array $p): int => (int) ($p['count'] ?? 0), $s['parcels'] ?? []));
            fputcsv($out, [
                $s['reference'],
                $s['customer_id'] ?? '',
                $s['customer_name'],
                str_replace('T', ' ', substr((string) $s['updated_at'], 0, 16)),
                $s['service'],
                $s['hub'],
                $line($s['pickup']),
                $line($s['delivery']),
                $parcels,
                $s['price_cents'] !== null ? number_format($s['price_cents'] / 100, 2, ',', '') : '',
                $s['price_override_reason'] ?? '',
                $courierNames[$s['courier_id'] ?? 0] ?? '',
                $s['channel'],
            ], ';', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return "\xEF\xBB\xBF" . $csv;
    }
}
