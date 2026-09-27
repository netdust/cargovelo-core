<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Customer;

use CargoVelo\Support\TableRepository;
use WP_Error;

final class CustomerRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'customers';
    }

    public function find(int $id): ?object
    {
        return $id > 0 ? $this->rowById($id) : null;
    }

    public function findByEmail(string $email): ?object
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare("SELECT * FROM {$this->table()} WHERE email = %s ORDER BY id ASC LIMIT 1", strtolower(trim($email))));
        return $row ?: null;
    }

    /** @return list<object> */
    public function all(string $search = '', bool $activeOnly = false, int $limit = 200): array
    {
        $db = $this->db();
        $where = ['1=1'];
        $args = [];
        if ($search !== '') {
            $like = '%' . $db->esc_like($search) . '%';
            $where[] = '(name LIKE %s OR email LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        if ($activeOnly) {
            $where[] = 'active = 1';
        }
        $args[] = $limit;
        $sql = "SELECT * FROM {$this->table()} WHERE " . implode(' AND ', $where) . ' ORDER BY name ASC LIMIT %d';
        return $db->get_results($db->prepare($sql, ...$args)) ?: [];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int|WP_Error
    {
        $now = cargovelo_now();
        return $this->insertRow($this->columns($data) + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): true|WP_Error
    {
        return $this->updateRow($id, $this->columns($data, true) + ['updated_at' => cargovelo_now()]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function columns(array $data, bool $partial = false): array
    {
        $map = [
            'name' => static fn($v): string => mb_substr(trim((string) $v), 0, 160),
            'type' => static fn($v): string => in_array($v, ['occasional', 'account'], true) ? $v : 'occasional',
            'email' => static fn($v): string => strtolower(trim((string) $v)),
            'phone' => static fn($v): string => mb_substr(trim((string) $v), 0, 40),
            'hub' => static fn($v): string => mb_substr(trim((string) $v), 0, 20),
            'price_list_id' => static fn($v): ?int => $v ? (int) $v : null,
            'vat' => static fn($v): string => mb_substr(trim((string) $v), 0, 30),
            'billing_address' => static fn($v): string => mb_substr(trim((string) $v), 0, 1000),
            'notes' => static fn($v): string => mb_substr(trim((string) $v), 0, 4000),
            'active' => static fn($v): int => $v ? 1 : 0,
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
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'email' => (string) $row->email,
            'phone' => (string) $row->phone,
            'hub' => (string) $row->hub,
            'price_list_id' => $row->price_list_id !== null ? (int) $row->price_list_id : null,
            'vat' => (string) $row->vat,
            'billing_address' => (string) ($row->billing_address ?? ''),
            'notes' => (string) ($row->notes ?? ''),
            'active' => (bool) $row->active,
        ];
    }
}
