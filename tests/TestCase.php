<?php
declare(strict_types=1);

namespace CargoVelo\Tests;

use CargoVelo\Domain\Actor;
use CargoVelo\Domain\Role;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        cv_test_reset();
        $GLOBALS['cv_test']['now'] = '2026-09-28 10:00:00'; // a Monday, before every cut-off
    }

    protected function set(string $id, mixed $value): void
    {
        ntdst_set($id, $value);
    }

    protected function staff(): Actor
    {
        return new Actor(1, 'Dispatcher Dana', Role::Dispatcher);
    }

    protected function customer(int $customerId = 7): Actor
    {
        return new Actor(20, 'Klant Kim', Role::Customer, $customerId);
    }

    protected function courier(int $courierId = 3): Actor
    {
        return new Actor(30, 'Koerier Kris', Role::Courier, null, $courierId, 'GNT');
    }

    /** @return array<string, mixed> */
    protected function validInput(array $overrides = []): array
    {
        return array_replace_recursive([
            'service' => 'sameday',
            'contact_email' => 'shop@example.be',
            'contact_company' => 'Bloemen Bea',
            'pickup' => ['name' => 'Bea', 'company' => 'Bloemen Bea', 'street' => 'Korenmarkt', 'number' => '1', 'postcode' => '9000', 'city' => 'Gent', 'phone' => '0470', 'email' => 'shop@example.be'],
            'delivery' => ['name' => 'Jan', 'street' => 'Veldstraat', 'number' => '20', 'postcode' => '9000', 'city' => 'Gent', 'email' => 'jan@example.be'],
            'parcels' => [['count' => 2, 'weight_class' => 's', 'fragile' => true, 'cooled' => false]],
            'remarks' => 'Bel aan bij de buren',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected function shipmentRow(array $overrides = []): array
    {
        return array_replace([
            'id' => 42,
            'reference' => 'CV-260928-0001',
            'customer_id' => 7,
            'customer_name' => 'Bloemen Bea',
            'contact_email' => 'shop@example.be',
            'service' => 'sameday',
            'hub' => 'GNT',
            'status' => 'confirmed',
            'exception' => null,
            'pickup' => ['name' => 'Bea', 'street' => 'Korenmarkt', 'number' => '1', 'postcode' => '9000', 'city' => 'Gent', 'email' => 'shop@example.be'],
            'delivery' => ['name' => 'Jan', 'street' => 'Veldstraat', 'number' => '20', 'postcode' => '9000', 'city' => 'Gent', 'email' => 'jan@example.be', 'instructions' => ''],
            'pickup_window' => ['start' => '2026-09-28T10:00:00', 'end' => null],
            'delivery_window' => ['start' => null, 'end' => '2026-09-28T18:00:00'],
            'parcels' => [['count' => 2, 'weight_class' => 's', 'fragile' => true, 'cooled' => false]],
            'price_cents' => 1440,
            'price_override_reason' => null,
            'courier_id' => null,
            'courier_name' => null,
            'stop_sequence' => null,
            'channel' => 'portal',
            'tracking_token' => 'abcdefabcdefabcdefabcdef',
            'remarks' => '',
            'created_by' => 20,
            'created_at' => '2026-09-28T09:00:00',
            'updated_at' => '2026-09-28T09:00:00',
        ], $overrides);
    }
}
