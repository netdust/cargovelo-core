<?php
declare(strict_types=1);

namespace CargoVelo\Handlers;

use CargoVelo\Domain\Actor;
use CargoVelo\Domain\Channel;
use CargoVelo\Modules\Shipment\ShipmentService;
use CargoVelo\Modules\Tracking\TrackingService;
use CargoVelo\Support\RestSupport;
use WP_Error;
use WP_REST_Request;

/**
 * The whole anonymous surface: price preview, website booking, tracking page, recipient
 * instructions. Everything else requires a capability. Rate limits are per IP.
 */
final class PublicHandler
{
    use RestSupport;

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        $rest = ntdst_rest(self::REST_NAMESPACE);
        $anon = static fn(): bool => true;

        $rest->post('/public/quote', [$this, 'handleQuote'], ['permission' => $anon, 'rate_limit' => 60, 'rate_window' => 60]);
        $rest->post('/public/bookings', [$this, 'handleBooking'], ['permission' => $anon, 'rate_limit' => 10, 'rate_window' => 60]);
        $rest->get('/public/tracking/(?P<token>[a-f0-9]{24})', [$this, 'handleTracking'], ['rate_limit' => 60, 'rate_window' => 60])->public();
        $rest->post('/public/tracking/(?P<token>[a-f0-9]{24})/instructions', [$this, 'handleInstructions'], ['permission' => $anon, 'rate_limit' => 10, 'rate_window' => 60]);
    }

    public function handleQuote(WP_REST_Request $request): array|WP_Error
    {
        $result = ntdst_get(ShipmentService::class)->quote($this->params($request), Actor::guest());
        return $result instanceof WP_Error ? $this->fail($result) : $result;
    }

    public function handleBooking(WP_REST_Request $request): array|WP_Error
    {
        $params = $this->params($request);
        if (!empty($params['website'])) {
            // Honeypot field filled in: a bot. Pretend success without creating anything.
            return ['reference' => 'CV-000000-0000', 'tracking_token' => ''];
        }
        $result = ntdst_get(ShipmentService::class)->create($params, Actor::guest(), Channel::Web);
        if ($result instanceof WP_Error) {
            return $this->fail($result);
        }
        return [
            'reference' => $result['reference'],
            'status' => $result['status'],
            'price_cents' => $result['price_cents'],
            'tracking_token' => $result['tracking_token'],
            'tracking_url' => ntdst_get(TrackingService::class)->url($result['tracking_token']),
        ];
    }

    public function handleTracking(WP_REST_Request $request): array|WP_Error
    {
        $result = ntdst_get(TrackingService::class)->view((string) $request->get_param('token'));
        return $result instanceof WP_Error ? $this->fail($result, 404) : $result;
    }

    public function handleInstructions(WP_REST_Request $request): array|WP_Error
    {
        $result = ntdst_get(ShipmentService::class)->setInstructionsByToken(
            (string) $request->get_param('token'),
            (string) ($request->get_param('instructions') ?? '')
        );
        if ($result instanceof WP_Error) {
            return $this->fail($result);
        }
        $view = ntdst_get(TrackingService::class)->view((string) $request->get_param('token'));
        return $view instanceof WP_Error ? $this->fail($view, 404) : $view;
    }
}
