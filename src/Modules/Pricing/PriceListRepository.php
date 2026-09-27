<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Pricing;

use CargoVelo\Domain\ServiceCode;
use CargoVelo\Install\Schema;
use CargoVelo\Support\TableRepository;
use WP_Error;

/**
 * Price lists with one rule per service. The default list applies to guests and to customers
 * without a contract list. Seeds a default list on first use so pricing never returns null.
 */
final class PriceListRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'price_lists';
    }

    private function rulesTable(): string
    {
        return Schema::table('price_rules');
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $db = $this->db();
        $lists = $db->get_results("SELECT * FROM {$this->table()} ORDER BY is_default DESC, name ASC") ?: [];
        if ($lists === []) {
            $this->seedDefault();
            $lists = $db->get_results("SELECT * FROM {$this->table()} ORDER BY is_default DESC, name ASC") ?: [];
        }
        $out = [];
        foreach ($lists as $list) {
            $out[] = $this->toArray($list);
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->rowById($id);
        return $row ? $this->toArray($row) : null;
    }

    /** @return array<string, mixed> */
    public function defaultList(): array
    {
        $db = $this->db();
        $row = $db->get_row("SELECT * FROM {$this->table()} WHERE is_default = 1 ORDER BY id ASC LIMIT 1");
        if (!$row) {
            $this->seedDefault();
            $row = $db->get_row("SELECT * FROM {$this->table()} WHERE is_default = 1 ORDER BY id ASC LIMIT 1");
        }
        return $row ? $this->toArray($row) : ['id' => 0, 'name' => 'Standaard', 'is_default' => true, 'rules' => self::defaultRules(0)];
    }

    public function create(string $name): int|WP_Error
    {
        $id = $this->insertRow(['name' => mb_substr(trim($name), 0, 120), 'is_default' => 0]);
        if (is_int($id)) {
            foreach (self::defaultRules($id) as $rule) {
                $this->saveRule($id, $rule);
            }
        }
        return $id;
    }

    /** @param array<string, mixed> $rule */
    public function saveRule(int $listId, array $rule): true|WP_Error
    {
        $service = (string) ($rule['service'] ?? '');
        if (!in_array($service, ServiceCode::values(), true)) {
            return new WP_Error('invalid_service', 'Onbekende dienst.');
        }
        $db = $this->db();
        $data = [
            'price_list_id' => $listId,
            'service' => $service,
            'base_cents' => max(0, (int) ($rule['base_cents'] ?? 0)),
            'extra_parcel_cents' => max(0, (int) ($rule['extra_parcel_cents'] ?? 0)),
            'surcharges_json' => self::encodeJson(self::cleanSurcharges($rule['surcharges'] ?? [])),
        ];
        $existing = $db->get_var($db->prepare("SELECT id FROM {$this->rulesTable()} WHERE price_list_id = %d AND service = %s", $listId, $service));
        $ok = $existing
            ? $db->update($this->rulesTable(), $data, ['id' => (int) $existing])
            : $db->insert($this->rulesTable(), $data);
        return $ok === false ? new WP_Error('db_error', 'Opslaan mislukt.') : true;
    }

    public function rename(int $id, string $name): true|WP_Error
    {
        return $this->updateRow($id, ['name' => mb_substr(trim($name), 0, 120)]);
    }

    /** @return array<string, mixed> */
    private function toArray(object $row): array
    {
        $db = $this->db();
        $rules = $db->get_results($db->prepare("SELECT * FROM {$this->rulesTable()} WHERE price_list_id = %d ORDER BY id ASC", (int) $row->id)) ?: [];
        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'is_default' => (bool) $row->is_default,
            'rules' => array_map(static fn(object $r): array => [
                'id' => (int) $r->id,
                'price_list_id' => (int) $r->price_list_id,
                'service' => (string) $r->service,
                'base_cents' => (int) $r->base_cents,
                'extra_parcel_cents' => (int) $r->extra_parcel_cents,
                'surcharges' => self::decodeJson($r->surcharges_json),
            ], $rules),
        ];
    }

    private function seedDefault(): void
    {
        $id = $this->insertRow(['name' => 'Standaard', 'is_default' => 1]);
        if (is_int($id)) {
            foreach (self::defaultRules($id) as $rule) {
                $this->saveRule($id, $rule);
            }
        }
    }

    /**
     * Placeholder tariffs until Cargo Velo hands over the real price structure.
     * @return list<array<string, mixed>>
     */
    public static function defaultRules(int $listId): array
    {
        $surcharges = ['m' => 250, 'l' => 500, 'xl' => 1000, 'fragile' => 200, 'cooled' => 400];
        return [
            ['price_list_id' => $listId, 'service' => 'express', 'base_cents' => 1490, 'extra_parcel_cents' => 300, 'surcharges' => $surcharges],
            ['price_list_id' => $listId, 'service' => 'sameday', 'base_cents' => 990, 'extra_parcel_cents' => 250, 'surcharges' => $surcharges],
            ['price_list_id' => $listId, 'service' => 'nextday', 'base_cents' => 790, 'extra_parcel_cents' => 200, 'surcharges' => $surcharges],
            ['price_list_id' => $listId, 'service' => 'scheduled', 'base_cents' => 890, 'extra_parcel_cents' => 200, 'surcharges' => $surcharges],
        ];
    }

    /** @return array<string, int> */
    private static function cleanSurcharges(mixed $raw): array
    {
        $allowed = ['xs', 's', 'm', 'l', 'xl', 'fragile', 'cooled'];
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $k => $v) {
                if (in_array($k, $allowed, true)) {
                    $out[$k] = max(0, (int) $v);
                }
            }
        }
        return $out;
    }
}
