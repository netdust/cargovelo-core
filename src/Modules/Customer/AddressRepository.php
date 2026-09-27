<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Customer;

use CargoVelo\Domain\Address;
use CargoVelo\Support\TableRepository;
use WP_Error;

/** Address book of an account customer. Snapshots on shipments never point back here. */
final class AddressRepository extends TableRepository
{
    protected function tableName(): string
    {
        return 'addresses';
    }

    public function find(int $id): ?object
    {
        return $this->rowById($id);
    }

    /** @return list<object> */
    public function forCustomer(int $customerId): array
    {
        $db = $this->db();
        return $db->get_results($db->prepare("SELECT * FROM {$this->table()} WHERE customer_id = %d ORDER BY label ASC, id ASC", $customerId)) ?: [];
    }

    public function create(int $customerId, string $label, Address $address): int|WP_Error
    {
        return $this->insertRow([
            'customer_id' => $customerId,
            'label' => mb_substr(trim($label), 0, 80),
            'created_at' => cargovelo_now(),
        ] + $address->toArray());
    }

    public function delete(int $id, int $customerId): bool
    {
        $db = $this->db();
        return (bool) $db->delete($this->table(), ['id' => $id, 'customer_id' => $customerId]);
    }

    /** @return array<string, mixed> */
    public static function toArray(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'customer_id' => (int) $row->customer_id,
            'label' => (string) $row->label,
            'name' => (string) $row->name,
            'company' => (string) $row->company,
            'street' => (string) $row->street,
            'number' => (string) $row->number,
            'box' => (string) $row->box,
            'postcode' => (string) $row->postcode,
            'city' => (string) $row->city,
            'phone' => (string) $row->phone,
            'email' => (string) $row->email,
            'instructions' => (string) $row->instructions,
        ];
    }
}
