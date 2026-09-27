<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Shipment;

use CargoVelo\Domain\Actor;
use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Support\TableRepository;
use WP_Error;

/** Append-only audit trail per shipment: status changes, notes, proof of delivery, exceptions, price. */
final class ShipmentEventRepository extends TableRepository
{
    public const TYPE_STATUS = 'status';
    public const TYPE_NOTE = 'note';
    public const TYPE_POD = 'pod';
    public const TYPE_EXCEPTION = 'exception';
    public const TYPE_PRICE = 'price';
    public const TYPE_ASSIGNMENT = 'assignment';
    public const TYPE_EDIT = 'edit';

    protected function tableName(): string
    {
        return 'shipment_events';
    }

    /** @param array<string, mixed> $payload */
    public function append(
        int $shipmentId,
        string $type,
        Actor $actor,
        array $payload = [],
        ?ShipmentStatus $from = null,
        ?ShipmentStatus $to = null,
    ): int|WP_Error {
        return $this->insertRow([
            'shipment_id' => $shipmentId,
            'type' => $type,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_id' => $actor->userId > 0 ? $actor->userId : null,
            'actor_name' => mb_substr($actor->name, 0, 120),
            'actor_role' => $actor->role->value,
            'payload_json' => self::encodeJson($payload),
            'created_at' => cargovelo_now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function forShipment(int $shipmentId): array
    {
        $db = $this->db();
        $rows = $db->get_results($db->prepare("SELECT * FROM {$this->table()} WHERE shipment_id = %d ORDER BY id ASC", $shipmentId)) ?: [];
        return array_map([self::class, 'toArray'], $rows);
    }

    /** Latest proof-of-delivery event, if any. @return array<string, mixed>|null */
    public function latestPod(int $shipmentId): ?array
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare("SELECT * FROM {$this->table()} WHERE shipment_id = %d AND type = %s ORDER BY id DESC LIMIT 1", $shipmentId, self::TYPE_POD));
        return $row ? self::toArray($row) : null;
    }

    /** @return array<string, mixed> */
    public static function toArray(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'shipment_id' => (int) $row->shipment_id,
            'type' => (string) $row->type,
            'from_status' => $row->from_status !== null ? (string) $row->from_status : null,
            'to_status' => $row->to_status !== null ? (string) $row->to_status : null,
            'actor_id' => $row->actor_id !== null ? (int) $row->actor_id : null,
            'actor_name' => (string) $row->actor_name,
            'actor_role' => (string) $row->actor_role,
            'payload' => self::decodeJson($row->payload_json),
            'created_at' => str_replace(' ', 'T', (string) $row->created_at),
        ];
    }
}
