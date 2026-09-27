<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Courier;

use CargoVelo\Support\TableRepository;
use WP_Error;

final class CourierRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'couriers';
    }

    public function find(int $id): ?object
    {
        return $id > 0 ? $this->rowById($id) : null;
    }

    public function findByUser(int $userId): ?object
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare("SELECT * FROM {$this->table()} WHERE user_id = %d LIMIT 1", $userId));
        return $row ?: null;
    }

    /** @return list<object> */
    public function all(?string $hub = null, bool $activeOnly = false): array
    {
        $db = $this->db();
        $where = ['1=1'];
        $args = [];
        if ($hub !== null && $hub !== '') {
            $where[] = 'hub = %s';
            $args[] = $hub;
        }
        if ($activeOnly) {
            $where[] = 'active = 1';
        }
        $sql = "SELECT * FROM {$this->table()} WHERE " . implode(' AND ', $where) . ' ORDER BY name ASC';
        return ($args ? $db->get_results($db->prepare($sql, ...$args)) : $db->get_results($sql)) ?: [];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int|WP_Error
    {
        return $this->insertRow($this->columns($data) + ['created_at' => cargovelo_now()]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): true|WP_Error
    {
        return $this->updateRow($id, $this->columns($data, true));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data, bool $partial = false): array
    {
        $map = [
            'user_id' => static fn($v): ?int => $v ? (int) $v : null,
            'name' => static fn($v): string => mb_substr(trim((string) $v), 0, 120),
            'hub' => static fn($v): string => mb_substr(trim((string) $v), 0, 20),
            'phone' => static fn($v): string => mb_substr(trim((string) $v), 0, 40),
            'active' => static fn($v): int => $v === null || $v ? 1 : 0,
        ];
        $out = [];
        foreach ($map as $key => $fn) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $fn($data[$key]);
            } elseif (!$partial) {
                $out[$key] = $fn(null);
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function toArray(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
            'name' => (string) $row->name,
            'hub' => (string) $row->hub,
            'phone' => (string) $row->phone,
            'active' => (bool) $row->active,
        ];
    }
}
