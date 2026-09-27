<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Tracking;

use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Modules\Settings\SettingsService;
use CargoVelo\Modules\Shipment\ShipmentEventRepository;
use CargoVelo\Modules\Shipment\ShipmentRepository;
use WP_Error;

/**
 * The public tracking projection: what a recipient with the token link may see. No addresses
 * beyond the delivery city, no prices, no internal notes.
 */
final class TrackingService
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentEventRepository $events,
        private readonly SettingsService $settings,
    ) {
    }

    /** @return array<string, mixed>|WP_Error */
    public function view(string $token): array|WP_Error
    {
        $shipment = $this->shipments->findByToken($token);
        if ($shipment === null) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        $status = ShipmentStatus::from($shipment['status']);
        $events = [];
        foreach ($this->events->forShipment($shipment['id']) as $e) {
            if ($e['type'] === ShipmentEventRepository::TYPE_STATUS) {
                $events[] = ['type' => 'status', 'to_status' => $e['to_status'], 'created_at' => $e['created_at']];
            } elseif ($e['type'] === ShipmentEventRepository::TYPE_NOTE && ($e['payload']['visible_to_customer'] ?? false) && $e['actor_role'] === 'guest') {
                $events[] = ['type' => 'note', 'to_status' => null, 'created_at' => $e['created_at'], 'note' => (string) ($e['payload']['note'] ?? '')];
            }
        }
        $pod = $this->events->latestPod($shipment['id']);

        return [
            'reference' => $shipment['reference'],
            'status' => $status->value,
            'status_label' => $status->label(),
            'exception' => $shipment['exception'],
            'service' => $shipment['service'],
            'delivery_city' => (string) ($shipment['delivery']['city'] ?? ''),
            'delivery_window' => $shipment['delivery_window'],
            'events' => $events,
            'can_edit_instructions' => $status->isOpen(),
            'instructions' => (string) ($shipment['delivery']['instructions'] ?? ''),
            'pod' => $pod ? [
                'receiver_name' => (string) ($pod['payload']['receiver_name'] ?? ''),
                'delivered_at' => $pod['created_at'],
                'has_photo' => isset($pod['payload']['photo_path']),
            ] : null,
        ];
    }

    /** Public URL of the tracking page for a token. Filter cargovelo/tracking_url to point at a custom page. */
    public function url(string $token): string
    {
        $base = (string) ($this->settings->all()['tracking_page'] ?? '');
        $url = $base !== '' ? home_url($base) : home_url('/volg-je-zending/');
        $url = add_query_arg('t', $token, $url);
        return (string) apply_filters('cargovelo/tracking_url', $url, $token);
    }
}
