<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Modules;

use CargoVelo\Domain\Channel;
use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Modules\Courier\CourierRepository;
use CargoVelo\Modules\Customer\CustomerRepository;
use CargoVelo\Modules\Pricing\PriceListRepository;
use CargoVelo\Modules\Pricing\PricingService;
use CargoVelo\Modules\Settings\SettingsService;
use CargoVelo\Modules\Shipment\PodStorage;
use CargoVelo\Modules\Shipment\ReferenceGenerator;
use CargoVelo\Modules\Shipment\ShipmentEventRepository;
use CargoVelo\Modules\Shipment\ShipmentFilter;
use CargoVelo\Modules\Shipment\ShipmentRepository;
use CargoVelo\Modules\Shipment\ShipmentService;
use CargoVelo\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use WP_Error;

final class ShipmentServiceTest extends TestCase
{
    private ShipmentRepository&MockObject $shipments;
    private ShipmentEventRepository&MockObject $events;
    private CustomerRepository&MockObject $customers;
    private CourierRepository&MockObject $couriers;
    private PriceListRepository&MockObject $lists;
    private ShipmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shipments = $this->createMock(ShipmentRepository::class);
        $this->events = $this->createMock(ShipmentEventRepository::class);
        $this->customers = $this->createMock(CustomerRepository::class);
        $this->couriers = $this->createMock(CourierRepository::class);
        $this->lists = $this->createMock(PriceListRepository::class);
        $this->lists->method('defaultList')->willReturn(['id' => 1, 'name' => 'Standaard', 'is_default' => true, 'rules' => PriceListRepository::defaultRules(1)]);
        $this->lists->method('find')->willReturnCallback(fn(int $id) => $id === 9
            ? ['id' => 9, 'name' => 'Contract', 'is_default' => false, 'rules' => [['service' => 'sameday', 'base_cents' => 500, 'extra_parcel_cents' => 0, 'surcharges' => []]]]
            : null);
        $this->events->method('forShipment')->willReturn([]);
        $this->service = new ShipmentService(
            $this->shipments, $this->events, $this->customers, $this->couriers,
            new PricingService($this->lists), new SettingsService(), new ReferenceGenerator(), new PodStorage()
        );
    }

    // ------------------------------------------------------------- create

    public function testGuestBookingCreatesOccasionalCustomerAndRequestedShipment(): void
    {
        $this->customers->method('findByEmail')->willReturn(null);
        $this->customers->expects(self::once())->method('create')->with(self::callback(fn(array $d): bool => $d['type'] === 'occasional' && $d['email'] === 'shop@example.be' && $d['name'] === 'Bloemen Bea'))->willReturn(7);
        $this->customers->method('find')->willReturn((object) ['id' => 7, 'name' => 'Bloemen Bea', 'type' => 'occasional', 'email' => 'shop@example.be', 'phone' => '', 'hub' => 'GNT', 'price_list_id' => null, 'vat' => '', 'billing_address' => '', 'notes' => '', 'active' => 1]);

        $inserted = null;
        $this->shipments->method('referenceExists')->willReturn(false);
        $this->shipments->expects(self::once())->method('insert')->willReturnCallback(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 42;
        });
        $this->shipments->method('find')->willReturnCallback(fn() => $this->shipmentRow(['status' => 'requested', 'channel' => 'web']));
        $this->events->expects(self::once())->method('append')->with(42, 'status', self::anything(), self::anything(), null, ShipmentStatus::Requested);

        $result = $this->service->create($this->validInput(), \CargoVelo\Domain\Actor::guest(), Channel::Web);

        self::assertIsArray($result);
        self::assertSame('requested', $inserted['status']);
        self::assertSame('GNT', $inserted['hub']);
        self::assertSame('web', $inserted['channel']);
        self::assertMatchesRegularExpression('/^CV-\d{6}-\d{4}$/', $inserted['reference']);
        self::assertSame(24, strlen($inserted['tracking_token']));
        // default sameday 990 + 1 extra 250 + fragile 200
        self::assertSame(1440, $inserted['price_cents']);
        self::assertSame('2026-09-28 18:00:00', $inserted['delivery_end']);
        self::assertSame(1, did_action('cargovelo/shipment/created'));
    }

    public function testAccountCustomerBookingIsConfirmedAtContractPrice(): void
    {
        $this->customers->method('find')->with(7)->willReturn((object) ['id' => 7, 'name' => 'Labo Noord', 'type' => 'account', 'email' => 'labo@example.be', 'phone' => '', 'hub' => 'GNT', 'price_list_id' => 9, 'vat' => '', 'billing_address' => '', 'notes' => '', 'active' => 1]);
        $this->customers->expects(self::never())->method('create');
        $this->shipments->method('referenceExists')->willReturn(false);
        $inserted = null;
        $this->shipments->method('insert')->willReturnCallback(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 43;
        });
        $this->shipments->method('find')->willReturn($this->shipmentRow());

        $result = $this->service->create($this->validInput(['contact_email' => 'ignored@example.be']), $this->customer(7), Channel::Portal);

        self::assertIsArray($result);
        self::assertSame('confirmed', $inserted['status']);
        self::assertSame(7, $inserted['customer_id']);
        self::assertSame('Labo Noord', $inserted['customer_name']);
        self::assertSame(500, $inserted['price_cents']);
    }

    public function testCustomerWithoutLinkedCustomerCannotBook(): void
    {
        $r = $this->service->create($this->validInput(), new \CargoVelo\Domain\Actor(20, 'X', \CargoVelo\Domain\Role::Customer, null), Channel::Portal);
        self::assertInstanceOf(WP_Error::class, $r);
        self::assertSame('no_customer', $r->get_error_code());
    }

    public function testOutsideZoneIsRefused(): void
    {
        $r = $this->service->create($this->validInput(['pickup' => ['postcode' => '8000', 'city' => 'Brugge']]), $this->staff(), Channel::Phone);
        self::assertInstanceOf(WP_Error::class, $r);
        self::assertSame('outside_zone', $r->get_error_code());
    }

    public function testSameDayAfterCutoffIsRefused(): void
    {
        $GLOBALS['cv_test']['now'] = '2026-09-28 16:30:00';
        $r = $this->service->create($this->validInput(), $this->staff(), Channel::Phone);
        self::assertInstanceOf(WP_Error::class, $r);
        self::assertSame('cutoff_passed', $r->get_error_code());
    }

    public function testScheduledNeedsAFuturePickupWindow(): void
    {
        $r = $this->service->create($this->validInput(['service' => 'scheduled']), $this->staff(), Channel::Phone);
        self::assertSame('window_required', $r->get_error_code());

        $r = $this->service->create($this->validInput(['service' => 'scheduled', 'pickup_window' => ['start' => '2026-09-27T09:00:00']]), $this->staff(), Channel::Phone);
        self::assertSame('window_past', $r->get_error_code());
    }

    public function testNextDaySkipsTheWeekend(): void
    {
        $GLOBALS['cv_test']['now'] = '2026-10-02 10:00:00'; // Friday
        $this->customers->method('findByEmail')->willReturn(null);
        $this->customers->method('create')->willReturn(7);
        $this->customers->method('find')->willReturn((object) ['id' => 7, 'name' => 'B', 'type' => 'occasional', 'email' => 'shop@example.be', 'phone' => '', 'hub' => '', 'price_list_id' => null, 'vat' => '', 'billing_address' => '', 'notes' => '', 'active' => 1]);
        $this->shipments->method('referenceExists')->willReturn(false);
        $inserted = null;
        $this->shipments->method('insert')->willReturnCallback(function (array $row) use (&$inserted): int {
            $inserted = $row;
            return 1;
        });
        $this->shipments->method('find')->willReturn($this->shipmentRow());

        $this->service->create($this->validInput(['service' => 'nextday']), \CargoVelo\Domain\Actor::guest(), Channel::Web);
        self::assertSame('2026-10-05 09:00:00', $inserted['pickup_start']);
        self::assertSame('2026-10-05 18:00:00', $inserted['delivery_end']);
    }

    // ------------------------------------------------------------- scoping

    public function testCustomerCannotSeeAnotherCustomersShipment(): void
    {
        $this->shipments->method('find')->willReturn($this->shipmentRow(['customer_id' => 8]));
        $r = $this->service->get(42, $this->customer(7));
        self::assertInstanceOf(WP_Error::class, $r);
        self::assertSame(404, $r->get_error_data()['status']);
    }

    public function testCourierOnlySeesOwnAssignedShipments(): void
    {
        $this->shipments->method('find')->willReturn($this->shipmentRow(['courier_id' => 3, 'status' => 'assigned']));
        $this->couriers->method('find')->willReturn((object) ['id' => 3, 'name' => 'Kris', 'active' => 1]);
        self::assertIsArray($this->service->get(42, $this->courier(3)));
        self::assertInstanceOf(WP_Error::class, $this->service->get(42, $this->courier(4)));
    }

    public function testListIsScopedByRole(): void
    {
        $captured = [];
        $this->shipments->method('list')->willReturnCallback(function (ShipmentFilter $f) use (&$captured): array {
            $captured[] = [$f->customerId, $f->courierId];
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25];
        });
        $this->service->list(ShipmentFilter::fromParams(['customer_id' => 99]), $this->customer(7));
        $this->service->list(ShipmentFilter::fromParams([]), $this->courier(3));
        $this->service->list(ShipmentFilter::fromParams(['customer_id' => 99]), $this->staff());
        self::assertSame([[7, null], [null, 3], [99, null]], $captured);
    }

    public function testCustomerSeesOnlyCustomerVisibleNotes(): void
    {
        $events = $this->createMock(ShipmentEventRepository::class);
        $events->method('forShipment')->willReturn([
            ['id' => 1, 'type' => 'status', 'payload' => [], 'to_status' => 'confirmed'],
            ['id' => 2, 'type' => 'note', 'payload' => ['note' => 'intern', 'visible_to_customer' => false]],
            ['id' => 3, 'type' => 'note', 'payload' => ['note' => 'voor klant', 'visible_to_customer' => true]],
        ]);
        $service = new ShipmentService($this->shipments, $events, $this->customers, $this->couriers, new PricingService($this->lists), new SettingsService(), new ReferenceGenerator(), new PodStorage());
        $this->shipments->method('find')->willReturn($this->shipmentRow());
        $r = $service->get(42, $this->customer(7));
        self::assertSame([1, 3], array_column($r['events'], 'id'));
    }

    // ------------------------------------------------------------- transitions

    public function testCourierCannotConfirmOrCancel(): void
    {
        $this->shipments->method('find')->willReturn($this->shipmentRow(['courier_id' => 3, 'status' => 'assigned']));
        $this->couriers->method('find')->willReturn((object) ['id' => 3, 'name' => 'Kris', 'active' => 1]);
        $r = $this->service->transition(42, ShipmentStatus::Cancelled, $this->courier(3));
        self::assertSame('forbidden', $r->get_error_code());
    }

    public function testCustomerCanCancelOnlyBeforeAssignment(): void
    {
        $this->shipments->method('find')->willReturn($this->shipmentRow(['status' => 'assigned', 'courier_id' => 3]));
        $this->couriers->method('find')->willReturn((object) ['id' => 3, 'name' => 'Kris', 'active' => 1]);
        $r = $this->service->transition(42, ShipmentStatus::Cancelled, $this->customer(7));
        self::assertSame('forbidden', $r->get_error_code());
    }

    public function testInvalidTransitionIs409(): void
    {
        $this->shipments->method('find')->willReturn($this->shipmentRow(['status' => 'confirmed']));
        $r = $this->service->transition(42, ShipmentStatus::Delivered, $this->staff());
        self::assertSame('invalid_transition', $r->get_error_code());
        self::assertSame(409, $r->get_error_data()['status']);
    }

    public function testFailedRecordsReasonAsExceptionAndFiresEvent(): void
    {
        $this->shipments->method('find')->willReturnOnConsecutiveCalls(
            $this->shipmentRow(['status' => 'in_transit', 'courier_id' => 3]),
            $this->shipmentRow(['status' => 'failed', 'courier_id' => 3, 'exception' => 'not_home']),
        );
        $this->couriers->method('find')->willReturn((object) ['id' => 3, 'name' => 'Kris', 'active' => 1]);
        $this->shipments->expects(self::once())->method('transition')
            ->with(42, ShipmentStatus::InTransit, ShipmentStatus::Failed, ['exception' => 'not_home'])
            ->willReturn(true);
        $this->events->expects(self::once())->method('append')->with(42, 'status', self::anything(), ['reason' => 'not_home', 'note' => 'Bel ging niet'], ShipmentStatus::InTransit, ShipmentStatus::Failed);

        $r = $this->service->transition(42, ShipmentStatus::Failed, $this->courier(3), ['reason' => 'not_home', 'note' => 'Bel ging niet']);
        self::assertSame('failed', $r['status']);
        self::assertSame(1, did_action('cargovelo/shipment/status_changed'));
    }

    public function testDeliveredStoresProofOfDelivery(): void
    {
        $this->shipments->method('find')->willReturnOnConsecutiveCalls(
            $this->shipmentRow(['status' => 'in_transit', 'courier_id' => 3]),
            $this->shipmentRow(['status' => 'delivered', 'courier_id' => 3]),
        );
        $this->couriers->method('find')->willReturn((object) ['id' => 3, 'name' => 'Kris', 'active' => 1]);
        $this->shipments->method('transition')->willReturn(true);
        $appended = [];
        $this->events->method('append')->willReturnCallback(function (int $id, string $type, $actor, array $payload) use (&$appended): int {
            $appended[] = [$type, $payload];
            return 1;
        });
        $png = 'data:image/png;base64,' . base64_encode("\x89PNG\r\n\x1a\nfake");

        $this->service->transition(42, ShipmentStatus::Delivered, $this->courier(3), ['receiver_name' => 'Jan', 'photo_base64' => $png]);

        self::assertSame('pod', $appended[0][0]);
        self::assertSame('Jan', $appended[0][1]['receiver_name']);
        self::assertMatchesRegularExpression('#^42/photo-\d{14}\.png$#', $appended[0][1]['photo_path']);
        self::assertSame('status', $appended[1][0]);
    }

    public function testConcurrentChangeIsAConflict(): void
    {
        $this->shipments->method('find')->willReturn($this->shipmentRow(['status' => 'confirmed']));
        $this->shipments->method('transition')->willReturn(false);
        $r = $this->service->transition(42, ShipmentStatus::Cancelled, $this->staff());
        self::assertSame('conflict', $r->get_error_code());
    }

    // ------------------------------------------------------------- assign

    public function testAssignMovesConfirmedToAssignedWithCourier(): void
    {
        $this->shipments->method('find')->willReturnOnConsecutiveCalls(
            $this->shipmentRow(['status' => 'confirmed']),
            $this->shipmentRow(['status' => 'assigned', 'courier_id' => 3]),
        );
        $this->couriers->method('find')->with(3)->willReturn((object) ['id' => 3, 'name' => 'Kris', 'active' => 1]);
        $this->shipments->expects(self::once())->method('transition')
            ->with(42, ShipmentStatus::Confirmed, ShipmentStatus::Assigned, ['courier_id' => 3, 'stop_sequence' => 2])
            ->willReturn(true);
        $r = $this->service->assign(42, 3, $this->staff(), 2);
        self::assertSame('Kris', $r['courier_name']);
        self::assertSame(1, did_action('cargovelo/shipment/assigned'));
    }

    public function testOnlyStaffAssigns(): void
    {
        $r = $this->service->assign(42, 3, $this->customer());
        self::assertSame('forbidden', $r->get_error_code());
    }

    public function testInactiveCourierIsRefused(): void
    {
        $this->shipments->method('find')->willReturn($this->shipmentRow(['status' => 'confirmed']));
        $this->couriers->method('find')->willReturn((object) ['id' => 3, 'name' => 'Kris', 'active' => 0]);
        $r = $this->service->assign(42, 3, $this->staff());
        self::assertSame('invalid_courier', $r->get_error_code());
    }

    public function testUnassignReturnsToConfirmedAndClearsCourier(): void
    {
        $this->shipments->method('find')->willReturnOnConsecutiveCalls(
            $this->shipmentRow(['status' => 'assigned', 'courier_id' => 3]),
            $this->shipmentRow(['status' => 'assigned', 'courier_id' => 3]),
            $this->shipmentRow(['status' => 'confirmed']),
        );
        $this->shipments->expects(self::once())->method('transition')
            ->with(42, ShipmentStatus::Assigned, ShipmentStatus::Confirmed, ['exception' => null, 'courier_id' => null, 'stop_sequence' => null])
            ->willReturn(true);
        $r = $this->service->assign(42, null, $this->staff());
        self::assertSame('confirmed', $r['status']);
    }

    // ------------------------------------------------------------- price + instructions

    public function testPriceOverrideNeedsReason(): void
    {
        $r = $this->service->overridePrice(42, 1000, '  ', $this->staff());
        self::assertSame('invalid_price', $r->get_error_code());
    }

    public function testRecipientInstructionsOnlyWhileOpen(): void
    {
        $this->shipments->method('findByToken')->willReturn($this->shipmentRow(['status' => 'delivered']));
        $r = $this->service->setInstructionsByToken('abcdefabcdefabcdefabcdef', 'Bij de buren');
        self::assertSame('closed', $r->get_error_code());
    }

    public function testRecipientInstructionsAreSnapshottedOnTheShipment(): void
    {
        $this->shipments->method('findByToken')->willReturn($this->shipmentRow(['status' => 'assigned']));
        $this->shipments->expects(self::once())->method('update')->with(42, self::callback(fn(array $d): bool => str_contains($d['delivery_json'], '"instructions":"Bij de buren"')))->willReturn(true);
        $this->events->expects(self::once())->method('append')->with(42, 'note', self::anything(), self::callback(fn(array $p): bool => $p['visible_to_customer'] === true));
        self::assertTrue($this->service->setInstructionsByToken('abcdefabcdefabcdefabcdef', 'Bij de buren'));
    }
}
