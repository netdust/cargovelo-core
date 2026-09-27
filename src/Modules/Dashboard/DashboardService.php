<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Dashboard;

use CargoVelo\Modules\Shipment\ShipmentRepository;

final class DashboardService
{
    public function __construct(private readonly ShipmentRepository $shipments)
    {
    }

    /** @return array<string, mixed> shaped like app/src/domain/types.ts::DashboardStats */
    public function stats(?string $hub = null): array
    {
        $counts = $this->shipments->counts(null, $hub);
        $byStatus = $counts['by_status'];
        $open = 0;
        foreach (['requested', 'confirmed', 'assigned', 'picked_up', 'in_transit'] as $s) {
            $open += $byStatus[$s] ?? 0;
        }
        return [
            'today_total' => $counts['today'],
            'today_delivered' => $byStatus['delivered'] ?? 0,
            'today_open' => $open,
            'exceptions' => $counts['exceptions'],
            'on_time_rate' => $this->shipments->onTimeRate(30),
            'volume_7d' => $this->shipments->dailyVolume(7),
            'by_service' => $this->shipments->countBy('service', 30),
            'by_hub' => $this->shipments->countBy('hub', 30),
            'by_channel' => $this->shipments->countBy('channel', 30),
            'by_status' => $byStatus,
        ];
    }
}
