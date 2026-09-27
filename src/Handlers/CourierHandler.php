<?php
declare(strict_types=1);

namespace CargoVelo\Handlers;

use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Modules\Access\Roles;
use CargoVelo\Modules\Shipment\ShipmentService;
use CargoVelo\Support\RestSupport;
use WP_Error;
use WP_REST_Request;

/** Courier app. Floor: cargovelo_courier. Scope: the actor's own assigned stops. */
final class CourierHandler
{
    use RestSupport;

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        $rest = ntdst_rest(self::REST_NAMESPACE);
        $courier = ['permission' => Roles::CAP_COURIER, 'rate_limit' => 240, 'rate_window' => 60];
        $id = '(?P<id>\d+)';

        $rest->get('/courier/stops', [$this, 'handleStops'], $courier);
        $rest->get("/courier/stops/{$id}", [$this, 'handleStop'], $courier);
        $rest->post("/courier/stops/{$id}/status", [$this, 'handleStatus'], $courier);
        $rest->post("/courier/stops/{$id}/note", [$this, 'handleNote'], $courier);
    }

    public function handleStops(WP_REST_Request $request): array
    {
        $date = $request->get_param('date');
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
        return ['items' => ntdst_get(ShipmentService::class)->stopsForCourier($this->actor(), $date)];
    }

    public function handleStop(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->get($this->intArg($request, 'id'), $this->actor());
        return $r instanceof WP_Error ? $this->fail($r, 404) : $r;
    }

    public function handleStatus(WP_REST_Request $request): array|WP_Error
    {
        $to = ShipmentStatus::tryFrom((string) ($request->get_param('status') ?? ''));
        if ($to === null) {
            return $this->fail(new WP_Error('invalid_status', 'Onbekende status.'));
        }
        $r = ntdst_get(ShipmentService::class)->transition($this->intArg($request, 'id'), $to, $this->actor(), $this->params($request));
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handleNote(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->addNote($this->intArg($request, 'id'), (string) ($request->get_param('note') ?? ''), $this->actor());
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }
}
