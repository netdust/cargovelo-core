<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Modules;

use CargoVelo\Modules\Notification\MailerInterface;
use CargoVelo\Modules\Notification\NotificationService;
use CargoVelo\Modules\Settings\SettingsService;
use CargoVelo\Modules\Shipment\ShipmentEventRepository;
use CargoVelo\Modules\Shipment\ShipmentRepository;
use CargoVelo\Modules\Tracking\TrackingService;
use CargoVelo\Tests\TestCase;

final class TrackingAndNotificationTest extends TestCase
{
    public function testTrackingProjectionHidesInternals(): void
    {
        $shipments = $this->createMock(ShipmentRepository::class);
        $shipments->method('findByToken')->willReturn($this->shipmentRow(['status' => 'in_transit', 'courier_id' => 3]));
        $events = $this->createMock(ShipmentEventRepository::class);
        $events->method('forShipment')->willReturn([
            ['type' => 'status', 'to_status' => 'confirmed', 'created_at' => '2026-09-28T09:00:00', 'payload' => [], 'actor_role' => 'customer'],
            ['type' => 'note', 'to_status' => null, 'created_at' => '2026-09-28T09:30:00', 'payload' => ['note' => 'intern', 'visible_to_customer' => false], 'actor_role' => 'dispatcher'],
            ['type' => 'price', 'to_status' => null, 'created_at' => '2026-09-28T09:40:00', 'payload' => ['to_cents' => 5], 'actor_role' => 'dispatcher'],
            ['type' => 'status', 'to_status' => 'in_transit', 'created_at' => '2026-09-28T11:00:00', 'payload' => [], 'actor_role' => 'courier'],
        ]);
        $events->method('latestPod')->willReturn(null);

        $view = (new TrackingService($shipments, $events, new SettingsService()))->view('abcdefabcdefabcdefabcdef');

        self::assertSame('CV-260928-0001', $view['reference']);
        self::assertSame('Onderweg', $view['status_label']);
        self::assertSame('Gent', $view['delivery_city']);
        self::assertTrue($view['can_edit_instructions']);
        self::assertSame(['confirmed', 'in_transit'], array_column($view['events'], 'to_status'));
        self::assertArrayNotHasKey('price_cents', $view);
        self::assertArrayNotHasKey('pickup', $view);
        self::assertNull($view['pod']);
    }

    public function testTrackingUrlUsesSettingAndFilter(): void
    {
        $settings = new SettingsService();
        $tracking = new TrackingService($this->createMock(ShipmentRepository::class), $this->createMock(ShipmentEventRepository::class), $settings);
        self::assertSame('https://cargovelo.test/volg-je-zending/?t=abc', $tracking->url('abc'));
        $settings->update(['tracking_page' => '/track/']);
        self::assertSame('https://cargovelo.test/track/?t=abc', $tracking->url('abc'));
    }

    public function testMailsOnCreatedAndDeliveredAndFailed(): void
    {
        $sent = [];
        $mailer = new class($sent) implements MailerInterface {
            public function __construct(private array &$sent)
            {
            }

            public function send(string $to, string $subject, string $html): bool
            {
                $this->sent[] = [$to, $subject];
                return true;
            }
        };
        $settings = new SettingsService();
        $settings->update(['notify_email' => 'dispatch@cargovelo.be']);
        $tracking = new TrackingService($this->createMock(ShipmentRepository::class), $this->createMock(ShipmentEventRepository::class), $settings);
        new NotificationService($mailer, $tracking, $settings);

        do_action('cargovelo/shipment/created', ['shipment' => $this->shipmentRow(['status' => 'requested', 'channel' => 'web']), 'actor' => []]);
        do_action('cargovelo/shipment/status_changed', ['shipment' => $this->shipmentRow(['status' => 'picked_up']), 'from' => 'assigned', 'to' => 'picked_up', 'actor' => [], 'payload' => []]);
        do_action('cargovelo/shipment/status_changed', ['shipment' => $this->shipmentRow(['status' => 'delivered']), 'from' => 'in_transit', 'to' => 'delivered', 'actor' => [], 'payload' => []]);
        do_action('cargovelo/shipment/status_changed', ['shipment' => $this->shipmentRow(['status' => 'failed', 'exception' => 'not_home']), 'from' => 'in_transit', 'to' => 'failed', 'actor' => [], 'payload' => ['reason' => 'not_home']]);

        $recipients = array_column($sent, 0);
        self::assertSame(['shop@example.be', 'dispatch@cargovelo.be', 'jan@example.be', 'shop@example.be', 'jan@example.be', 'shop@example.be', 'dispatch@cargovelo.be'], $recipients);
        self::assertStringContainsString('aangevraagd', $sent[0][1]);
        self::assertStringContainsString('onderweg', $sent[2][1]);
        self::assertStringContainsString('geleverd', $sent[3][1]);
        self::assertStringContainsString('mislukt', $sent[5][1]);
    }
}
