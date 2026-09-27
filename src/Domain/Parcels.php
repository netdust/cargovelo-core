<?php
declare(strict_types=1);

namespace CargoVelo\Domain;

use WP_Error;

/** Parcel lines of a shipment: [{count, weight_class, fragile, cooled}]. */
final class Parcels
{
    /** @param list<array{count:int, weight_class:string, fragile:bool, cooled:bool}> $lines */
    private function __construct(public readonly array $lines)
    {
    }

    /** @param mixed $raw */
    public static function fromArray(mixed $raw): self|WP_Error
    {
        if (!is_array($raw) || $raw === []) {
            return new WP_Error('invalid_parcels', 'Minstens één pakket is vereist.', ['field' => 'parcels']);
        }
        $lines = [];
        foreach (array_values($raw) as $i => $line) {
            if (!is_array($line)) {
                return new WP_Error('invalid_parcels', 'Ongeldige pakketregel.', ['field' => 'parcels', 'index' => $i]);
            }
            $count = (int) ($line['count'] ?? 1);
            if ($count < 1 || $count > 200) {
                return new WP_Error('invalid_parcels', 'Aantal per regel moet tussen 1 en 200 liggen.', ['field' => 'parcels', 'index' => $i]);
            }
            $weight = strtolower((string) ($line['weight_class'] ?? 's'));
            if (!in_array($weight, WeightClass::values(), true)) {
                return new WP_Error('invalid_parcels', 'Onbekende gewichtsklasse.', ['field' => 'parcels', 'index' => $i]);
            }
            $lines[] = [
                'count' => $count,
                'weight_class' => $weight,
                'fragile' => self::bool($line['fragile'] ?? false),
                'cooled' => self::bool($line['cooled'] ?? false),
            ];
        }
        return new self($lines);
    }

    public function totalCount(): int
    {
        return array_sum(array_column($this->lines, 'count'));
    }

    public function hasFragile(): bool
    {
        return (bool) array_filter($this->lines, static fn(array $l): bool => $l['fragile']);
    }

    public function hasCooled(): bool
    {
        return (bool) array_filter($this->lines, static fn(array $l): bool => $l['cooled']);
    }

    public function heaviest(): WeightClass
    {
        $order = WeightClass::values();
        $max = 0;
        foreach ($this->lines as $l) {
            $max = max($max, (int) array_search($l['weight_class'], $order, true));
        }
        return WeightClass::from($order[$max]);
    }

    /** @return list<array{count:int, weight_class:string, fragile:bool, cooled:bool}> */
    public function toArray(): array
    {
        return $this->lines;
    }

    private static function bool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'ja', 'on'], true);
    }
}
