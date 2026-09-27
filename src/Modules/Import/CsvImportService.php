<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Import;

use CargoVelo\Domain\Actor;
use CargoVelo\Domain\Channel;
use CargoVelo\Modules\Shipment\ShipmentService;
use WP_Error;

/**
 * Bulk booking from a CSV (account customers already work with import files).
 * Header row required; separator "," or ";" auto-detected; UTF-8 with optional BOM.
 */
final class CsvImportService
{
    public const COLUMNS = [
        'service', 'pickup_name', 'pickup_company', 'pickup_street', 'pickup_number', 'pickup_box', 'pickup_postcode', 'pickup_city', 'pickup_phone', 'pickup_email', 'pickup_instructions',
        'delivery_name', 'delivery_company', 'delivery_street', 'delivery_number', 'delivery_box', 'delivery_postcode', 'delivery_city', 'delivery_phone', 'delivery_email', 'delivery_instructions',
        'parcels', 'weight_class', 'fragile', 'cooled', 'pickup_date', 'pickup_time_from', 'pickup_time_to', 'remarks',
    ];

    public const MAX_ROWS = 500;

    public function __construct(private readonly ShipmentService $shipments)
    {
    }

    /**
     * Pure parse step: CSV text → list of ShipmentInput arrays or per-line errors.
     * @return array{rows: list<array{line:int, input?: array<string,mixed>, error?: string}>, error?: string}
     */
    public static function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if (count($lines) < 2) {
            return ['rows' => [], 'error' => 'Het bestand bevat geen gegevens (kopregel + minstens één rij).'];
        }
        $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(static fn(string $h): string => strtolower(trim($h)), str_getcsv($lines[0], $sep, '"', '\\'));
        $unknown = array_diff($header, self::COLUMNS);
        if ($unknown !== []) {
            return ['rows' => [], 'error' => 'Onbekende kolommen: ' . implode(', ', $unknown)];
        }
        foreach (['service', 'pickup_street', 'delivery_street'] as $required) {
            if (!in_array($required, $header, true)) {
                return ['rows' => [], 'error' => "Verplichte kolom ontbreekt: {$required}"];
            }
        }
        $rows = [];
        foreach (array_slice($lines, 1, self::MAX_ROWS) as $i => $line) {
            $lineNo = $i + 2;
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, $sep, '"', '\\');
            if (count($cells) !== count($header)) {
                $rows[] = ['line' => $lineNo, 'error' => sprintf('%d kolommen gevonden, %d verwacht.', count($cells), count($header))];
                continue;
            }
            $r = array_combine($header, array_map('trim', $cells));
            $rows[] = ['line' => $lineNo, 'input' => self::toInput($r)];
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            $rows[] = ['line' => self::MAX_ROWS + 2, 'error' => sprintf('Maximum %d rijen per import; de rest werd overgeslagen.', self::MAX_ROWS)];
        }
        return ['rows' => $rows];
    }

    /**
     * @return array{created:int, failed:int, rows: list<array{line:int, ok:bool, reference?:string, error?:string}>}
     */
    public function import(string $csv, Actor $actor): array
    {
        $parsed = self::parse($csv);
        if (isset($parsed['error'])) {
            return ['created' => 0, 'failed' => 1, 'rows' => [['line' => 1, 'ok' => false, 'error' => $parsed['error']]]];
        }
        $out = ['created' => 0, 'failed' => 0, 'rows' => []];
        foreach ($parsed['rows'] as $row) {
            if (isset($row['error'])) {
                $out['failed']++;
                $out['rows'][] = ['line' => $row['line'], 'ok' => false, 'error' => $row['error']];
                continue;
            }
            $result = $this->shipments->create($row['input'], $actor, Channel::Import);
            if ($result instanceof WP_Error) {
                $out['failed']++;
                $out['rows'][] = ['line' => $row['line'], 'ok' => false, 'error' => $result->get_error_message()];
            } else {
                $out['created']++;
                $out['rows'][] = ['line' => $row['line'], 'ok' => true, 'reference' => $result['reference']];
            }
        }
        return $out;
    }

    /**
     * @param array<string, string> $r
     * @return array<string, mixed>
     */
    private static function toInput(array $r): array
    {
        $addr = static fn(string $p): array => [
            'name' => $r[$p . '_name'] ?? '', 'company' => $r[$p . '_company'] ?? '', 'street' => $r[$p . '_street'] ?? '',
            'number' => $r[$p . '_number'] ?? '', 'box' => $r[$p . '_box'] ?? '', 'postcode' => $r[$p . '_postcode'] ?? '',
            'city' => $r[$p . '_city'] ?? '', 'phone' => $r[$p . '_phone'] ?? '', 'email' => $r[$p . '_email'] ?? '',
            'instructions' => $r[$p . '_instructions'] ?? '',
        ];
        $window = ['start' => null, 'end' => null];
        if (!empty($r['pickup_date'])) {
            $window['start'] = $r['pickup_date'] . 'T' . (($r['pickup_time_from'] ?? '') ?: '09:00') . ':00';
            if (!empty($r['pickup_time_to'])) {
                $window['end'] = $r['pickup_date'] . 'T' . $r['pickup_time_to'] . ':00';
            }
        }
        return [
            'service' => strtolower($r['service'] ?? ''),
            'pickup' => $addr('pickup'),
            'delivery' => $addr('delivery'),
            'parcels' => [[
                'count' => max(1, (int) (($r['parcels'] ?? '') ?: 1)),
                'weight_class' => strtolower(($r['weight_class'] ?? '') ?: 's'),
                'fragile' => in_array(strtolower($r['fragile'] ?? ''), ['1', 'ja', 'yes', 'true', 'x'], true),
                'cooled' => in_array(strtolower($r['cooled'] ?? ''), ['1', 'ja', 'yes', 'true', 'x'], true),
            ]],
            'pickup_window' => $window,
            'remarks' => $r['remarks'] ?? '',
        ];
    }

    public static function template(): string
    {
        return implode(';', self::COLUMNS) . "\n"
            . "sameday;Labo Noord;UZ Gent;Corneel Heymanslaan;10;;9000;Gent;09 332 21 11;;Ingang C;Dr. Peeters;AZ Sint-Lucas;Groenebriel;1;;9000;Gent;;;Onthaal;2;s;ja;ja;;;;Stalen koel houden\n";
    }
}
