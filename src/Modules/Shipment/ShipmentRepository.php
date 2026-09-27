<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Shipment;

use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Support\TableRepository;
use WP_Error;

/**
 * Owns the cv_shipments table. Rows go out as arrays shaped like app/src/domain/types.ts::Shipment.
 */
final class ShipmentRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'shipments';
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $id > 0 ? $this->rowById($id) : null;
        return $row ? self::toArray($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            return null;
        }
        $db = $this->db();
        $row = $db->get_row($db->prepare("SELECT * FROM {$this->table()} WHERE tracking_token = %s", $token));
        return $row ? self::toArray($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByReference(string $reference): ?array
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare("SELECT * FROM {$this->table()} WHERE reference = %s", strtoupper(trim($reference))));
        return $row ? self::toArray($row) : null;
    }

    public function referenceExists(string $reference): bool
    {
        $db = $this->db();
        return (bool) $db->get_var($db->prepare("SELECT id FROM {$this->table()} WHERE reference = %s", $reference));
    }

    /** @param array<string, mixed> $data column => value, already validated by the service */
    public function insert(array $data): int|WP_Error
    {
        $now = cargovelo_now();
        return $this->insertRow($data + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): true|WP_Error
    {
        return $this->updateRow($id, $data + ['updated_at' => cargovelo_now()]);
    }

    /**
     * Optimistic status change: only succeeds when the row still has $from. Two dispatchers or a
     * courier and a dispatcher acting at once cannot both win.
     */
    public function transition(int $id, ShipmentStatus $from, ShipmentStatus $to, array $extra = []): bool
    {
        $db = $this->db();
        $set = ['status = %s', 'updated_at = %s'];
        $args = [$to->value, cargovelo_now()];
        foreach ($extra as $col => $val) {
            if (!preg_match('/^[a-z_]+$/', (string) $col)) {
                continue;
            }
            if ($val === null) {
                $set[] = "{$col} = NULL";
            } else {
                $set[] = "{$col} = " . (is_int($val) ? '%d' : '%s');
                $args[] = $val;
            }
        }
        $args[] = $id;
        $args[] = $from->value;
        $sql = "UPDATE {$this->table()} SET " . implode(', ', $set) . ' WHERE id = %d AND status = %s';
        $affected = $db->query($db->prepare($sql, ...$args));
        return (int) $affected === 1;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(ShipmentFilter $f): array
    {
        $db = $this->db();
        [$where, $args] = $this->whereClause($f);
        $order = $f->order === 'asc' ? 'ASC' : 'DESC';
        $offset = ($f->page - 1) * $f->perPage;

        $countSql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}";
        $total = (int) ($args ? $db->get_var($db->prepare($countSql, ...$args)) : $db->get_var($countSql));

        $listSql = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY COALESCE(pickup_start, created_at) {$order}, id {$order} LIMIT %d OFFSET %d";
        $rows = $db->get_results($db->prepare($listSql, ...[...$args, $f->perPage, $offset])) ?: [];

        return [
            'items' => array_map([self::class, 'toArray'], $rows),
            'total' => $total,
            'page' => $f->page,
            'per_page' => $f->perPage,
        ];
    }

    /**
     * Today's stops for a courier, in stop order. "Today" = pickup on the local date, or open
     * without a date (ASAP).
     * @return list<array<string, mixed>>
     */
    public function stopsForCourier(int $courierId, string $date): array
    {
        $db = $this->db();
        $open = "'" . implode("','", ShipmentStatus::openValues()) . "'";
        $sql = "SELECT * FROM {$this->table()}
                WHERE courier_id = %d
                  AND (DATE(pickup_start) = %s OR pickup_start IS NULL OR (status IN ({$open}) AND DATE(pickup_start) < %s))
                  AND status IN ({$open}, 'delivered', 'failed')
                  AND (status IN ({$open}) OR DATE(updated_at) = %s)
                ORDER BY COALESCE(stop_sequence, 9999) ASC, COALESCE(pickup_start, created_at) ASC, id ASC";
        $rows = $db->get_results($db->prepare($sql, $courierId, $date, $date, $date)) ?: [];
        return array_map([self::class, 'toArray'], $rows);
    }

    /**
     * @return array{by_status: array<string,int>, exceptions: int, today: int}
     */
    public function counts(?int $customerId = null, ?string $hub = null): array
    {
        $db = $this->db();
        $where = ['1=1'];
        $args = [];
        if ($customerId !== null) {
            $where[] = 'customer_id = %d';
            $args[] = $customerId;
        }
        if ($hub !== null && $hub !== '') {
            $where[] = 'hub = %s';
            $args[] = $hub;
        }
        $w = implode(' AND ', $where);
        $sql = "SELECT status, COUNT(*) AS n FROM {$this->table()} WHERE {$w} GROUP BY status";
        $rows = ($args ? $db->get_results($db->prepare($sql, ...$args)) : $db->get_results($sql)) ?: [];
        $byStatus = array_fill_keys(ShipmentStatus::values(), 0);
        foreach ($rows as $r) {
            if (isset($byStatus[$r->status])) {
                $byStatus[$r->status] = (int) $r->n;
            }
        }
        $open = "'" . implode("','", ShipmentStatus::openValues()) . "'";
        $excSql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$w} AND exception IS NOT NULL AND status IN ({$open})";
        $exceptions = (int) ($args ? $db->get_var($db->prepare($excSql, ...$args)) : $db->get_var($excSql));
        $today = (string) (function_exists('wp_date') ? wp_date('Y-m-d') : date('Y-m-d'));
        $todaySql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$w} AND (DATE(pickup_start) = %s OR (pickup_start IS NULL AND DATE(created_at) = %s))";
        $todayCount = (int) $db->get_var($db->prepare($todaySql, ...[...$args, $today, $today]));

        return ['by_status' => $byStatus, 'exceptions' => $exceptions, 'today' => $todayCount];
    }

    /**
     * Delivered rows in a period, for the priced export.
     * @return list<array<string, mixed>>
     */
    public function deliveredBetween(string $from, string $to, ?int $customerId = null): array
    {
        $db = $this->db();
        $where = ["status = 'delivered'", 'DATE(updated_at) BETWEEN %s AND %s'];
        $args = [$from, $to];
        if ($customerId !== null) {
            $where[] = 'customer_id = %d';
            $args[] = $customerId;
        }
        $sql = "SELECT * FROM {$this->table()} WHERE " . implode(' AND ', $where) . ' ORDER BY customer_name ASC, updated_at ASC';
        $rows = $db->get_results($db->prepare($sql, ...$args)) ?: [];
        return array_map([self::class, 'toArray'], $rows);
    }

    /**
     * Daily volume for the last N days: [{label, value: delivered, value2: failed}]
     * @return list<array{label:string, value:int, value2:int}>
     */
    public function dailyVolume(int $days = 7): array
    {
        $db = $this->db();
        $sql = "SELECT DATE(COALESCE(pickup_start, created_at)) AS d,
                       SUM(status = 'delivered') AS delivered,
                       SUM(status = 'failed') AS failed,
                       COUNT(*) AS total
                FROM {$this->table()}
                WHERE COALESCE(pickup_start, created_at) >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
                GROUP BY d ORDER BY d ASC";
        $rows = $db->get_results($db->prepare($sql, $days - 1)) ?: [];
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r->d] = ['delivered' => (int) $r->delivered, 'failed' => (int) $r->failed, 'total' => (int) $r->total];
        }
        $out = [];
        $cursor = new \DateTimeImmutable('today');
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $cursor->modify("-{$i} day");
            $key = $day->format('Y-m-d');
            $out[] = [
                'label' => $day->format('D d/m'),
                'value' => $byDay[$key]['total'] ?? 0,
                'value2' => $byDay[$key]['failed'] ?? 0,
            ];
        }
        return $out;
    }

    /** @return list<array{label:string, value:int}> */
    public function countBy(string $column, int $days = 30): array
    {
        if (!in_array($column, ['service', 'hub', 'channel'], true)) {
            return [];
        }
        $db = $this->db();
        $sql = "SELECT {$column} AS k, COUNT(*) AS n FROM {$this->table()}
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY) GROUP BY {$column} ORDER BY n DESC";
        $rows = $db->get_results($db->prepare($sql, $days)) ?: [];
        return array_map(static fn(object $r): array => ['label' => (string) $r->k, 'value' => (int) $r->n], $rows);
    }

    /** On-time rate over the last N days: delivered before delivery_end, among delivered rows with a window. */
    public function onTimeRate(int $days = 30): float
    {
        $db = $this->db();
        $sql = "SELECT SUM(updated_at <= delivery_end) AS on_time, COUNT(*) AS n FROM {$this->table()}
                WHERE status = 'delivered' AND delivery_end IS NOT NULL AND updated_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)";
        $row = $db->get_row($db->prepare($sql, $days));
        if (!$row || (int) $row->n === 0) {
            return 1.0;
        }
        return round((int) $row->on_time / (int) $row->n, 3);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function whereClause(ShipmentFilter $f): array
    {
        $db = $this->db();
        $where = ['1=1'];
        $args = [];

        if ($f->status !== []) {
            $where[] = 'status IN (' . $this->placeholders($f->status, '%s') . ')';
            array_push($args, ...$f->status);
        } elseif ($f->openOnly) {
            $open = ShipmentStatus::openValues();
            $where[] = 'status IN (' . $this->placeholders($open, '%s') . ')';
            array_push($args, ...$open);
        }
        if ($f->service !== []) {
            $where[] = 'service IN (' . $this->placeholders($f->service, '%s') . ')';
            array_push($args, ...$f->service);
        }
        if ($f->hub !== null) {
            $where[] = 'hub = %s';
            $args[] = $f->hub;
        }
        if ($f->customerId !== null) {
            $where[] = 'customer_id = %d';
            $args[] = $f->customerId;
        }
        if ($f->courierId !== null) {
            $where[] = 'courier_id = %d';
            $args[] = $f->courierId;
        }
        if ($f->dateFrom !== null) {
            $where[] = 'DATE(COALESCE(pickup_start, created_at)) >= %s';
            $args[] = $f->dateFrom;
        }
        if ($f->dateTo !== null) {
            $where[] = 'DATE(COALESCE(pickup_start, created_at)) <= %s';
            $args[] = $f->dateTo;
        }
        if ($f->exception === true) {
            $where[] = 'exception IS NOT NULL';
        } elseif ($f->exception === false) {
            $where[] = 'exception IS NULL';
        }
        if ($f->search !== '') {
            $like = '%' . $db->esc_like($f->search) . '%';
            $where[] = '(reference LIKE %s OR customer_name LIKE %s OR pickup_json LIKE %s OR delivery_json LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        return [implode(' AND ', $where), $args];
    }

    /** @return array<string, mixed> */
    public static function toArray(object $row): array
    {
        $iso = static fn(?string $dt): ?string => $dt ? str_replace(' ', 'T', $dt) : null;
        return [
            'id' => (int) $row->id,
            'reference' => (string) $row->reference,
            'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null,
            'customer_name' => (string) $row->customer_name,
            'contact_email' => (string) ($row->contact_email ?? ''),
            'service' => (string) $row->service,
            'hub' => (string) $row->hub,
            'status' => (string) $row->status,
            'exception' => $row->exception !== null && $row->exception !== '' ? (string) $row->exception : null,
            'pickup' => self::decodeJson($row->pickup_json),
            'delivery' => self::decodeJson($row->delivery_json),
            'pickup_window' => ['start' => $iso($row->pickup_start), 'end' => $iso($row->pickup_end)],
            'delivery_window' => ['start' => $iso($row->delivery_start), 'end' => $iso($row->delivery_end)],
            'parcels' => self::decodeJson($row->parcels_json),
            'price_cents' => $row->price_cents !== null ? (int) $row->price_cents : null,
            'price_override_reason' => $row->price_override_reason !== null ? (string) $row->price_override_reason : null,
            'courier_id' => $row->courier_id !== null ? (int) $row->courier_id : null,
            'courier_name' => null, // filled by the service from CourierRepository
            'stop_sequence' => $row->stop_sequence !== null ? (int) $row->stop_sequence : null,
            'channel' => (string) $row->channel,
            'tracking_token' => (string) $row->tracking_token,
            'remarks' => (string) ($row->remarks ?? ''),
            'created_by' => (int) $row->created_by,
            'created_at' => (string) $iso($row->created_at),
            'updated_at' => (string) $iso($row->updated_at),
        ];
    }
}
