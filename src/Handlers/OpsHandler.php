<?php
declare(strict_types=1);

namespace CargoVelo\Handlers;

use CargoVelo\Domain\Channel;
use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Modules\Access\Roles;
use CargoVelo\Modules\Courier\CourierRepository;
use CargoVelo\Modules\Customer\AddressRepository;
use CargoVelo\Modules\Customer\CustomerRepository;
use CargoVelo\Modules\Dashboard\DashboardService;
use CargoVelo\Modules\Export\ExportService;
use CargoVelo\Modules\Pricing\PriceListRepository;
use CargoVelo\Modules\Settings\SettingsService;
use CargoVelo\Modules\Shipment\ShipmentFilter;
use CargoVelo\Modules\Shipment\ShipmentService;
use CargoVelo\Support\RestSupport;
use WP_Error;
use WP_REST_Request;

/**
 * Dispatch desk. Floor: cargovelo_dispatch. Master data (customers, couriers, prices, settings)
 * floor: cargovelo_manage.
 */
final class OpsHandler
{
    use RestSupport;

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        $rest = ntdst_rest(self::REST_NAMESPACE);
        $dispatch = ['permission' => Roles::CAP_DISPATCH, 'rate_limit' => 120, 'rate_window' => 60];
        $manage = ['permission' => Roles::CAP_MANAGE, 'rate_limit' => 60, 'rate_window' => 60];
        $id = '(?P<id>\d+)';

        $rest->get('/ops/shipments', [$this, 'handleList'], $dispatch);
        $rest->post('/ops/shipments', [$this, 'handleCreate'], $dispatch);
        $rest->get("/ops/shipments/{$id}", [$this, 'handleGet'], $dispatch);
        $rest->patch("/ops/shipments/{$id}", [$this, 'handleUpdate'], $dispatch);
        $rest->post("/ops/shipments/{$id}/status", [$this, 'handleStatus'], $dispatch);
        $rest->post("/ops/shipments/{$id}/assign", [$this, 'handleAssign'], $dispatch);
        $rest->post("/ops/shipments/{$id}/price", [$this, 'handlePrice'], $dispatch);
        $rest->post("/ops/shipments/{$id}/note", [$this, 'handleNote'], $dispatch);
        $rest->get("/ops/shipments/{$id}/pod/(?P<kind>photo|signature)", [$this, 'handlePod'], $dispatch);
        $rest->post('/ops/quote', [$this, 'handleQuote'], $dispatch);
        $rest->get('/ops/counts', [$this, 'handleCounts'], $dispatch);
        $rest->get('/ops/dashboard', [$this, 'handleDashboard'], $dispatch);
        $rest->get('/ops/export', [$this, 'handleExport'], $dispatch);

        $rest->get('/ops/couriers', [$this, 'handleCouriers'], $dispatch);
        $rest->post('/ops/couriers', [$this, 'handleCourierCreate'], $manage);
        $rest->patch("/ops/couriers/{$id}", [$this, 'handleCourierUpdate'], $manage);
        $rest->post("/ops/couriers/{$id}/reorder", [$this, 'handleReorder'], $dispatch);

        $rest->get('/ops/customers', [$this, 'handleCustomers'], $dispatch);
        $rest->post('/ops/customers', [$this, 'handleCustomerCreate'], $manage);
        $rest->patch("/ops/customers/{$id}", [$this, 'handleCustomerUpdate'], $manage);
        $rest->get("/ops/customers/{$id}/addresses", [$this, 'handleCustomerAddresses'], $dispatch);

        $rest->get('/ops/price-lists', [$this, 'handlePriceLists'], $manage);
        $rest->post('/ops/price-lists', [$this, 'handlePriceListCreate'], $manage);
        $rest->put("/ops/price-lists/{$id}", [$this, 'handlePriceListUpdate'], $manage);

        $rest->get('/ops/settings', [$this, 'handleSettings'], $manage);
        $rest->put('/ops/settings', [$this, 'handleSettingsUpdate'], $manage);
    }

    // ------------------------------------------------------------ shipments

    public function handleList(WP_REST_Request $request): array
    {
        return ntdst_get(ShipmentService::class)->list(ShipmentFilter::fromParams($this->params($request)), $this->actor());
    }

    public function handleGet(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->get($this->intArg($request, 'id'), $this->actor());
        return $r instanceof WP_Error ? $this->fail($r, 404) : $r;
    }

    public function handleCreate(WP_REST_Request $request): array|WP_Error
    {
        $params = $this->params($request);
        $channel = Channel::tryFrom((string) ($params['channel'] ?? 'phone')) ?? Channel::Phone;
        $r = ntdst_get(ShipmentService::class)->create($params, $this->actor(), $channel);
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handleUpdate(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->updateDetails($this->intArg($request, 'id'), $this->params($request), $this->actor());
        return $r instanceof WP_Error ? $this->fail($r) : $r;
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

    public function handleAssign(WP_REST_Request $request): array|WP_Error
    {
        $courierId = $request->get_param('courier_id');
        $seq = $request->get_param('stop_sequence');
        $r = ntdst_get(ShipmentService::class)->assign(
            $this->intArg($request, 'id'),
            $courierId === null || $courierId === '' ? null : (int) $courierId,
            $this->actor(),
            $seq === null || $seq === '' ? null : (int) $seq
        );
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handlePrice(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->overridePrice(
            $this->intArg($request, 'id'),
            (int) ($request->get_param('price_cents') ?? -1),
            (string) ($request->get_param('reason') ?? ''),
            $this->actor()
        );
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handleNote(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->addNote(
            $this->intArg($request, 'id'),
            (string) ($request->get_param('note') ?? ''),
            $this->actor(),
            (bool) $request->get_param('visible_to_customer')
        );
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handlePod(WP_REST_Request $request): WP_Error
    {
        $img = ntdst_get(ShipmentService::class)->podImage($this->intArg($request, 'id'), (string) $request->get_param('kind'), $this->actor());
        if ($img instanceof WP_Error) {
            return $this->fail($img, 404);
        }
        $this->download($img['bytes'], 'pod-' . $this->intArg($request, 'id') . '.' . explode('/', $img['mime'])[1], $img['mime'], true);
    }

    public function handleQuote(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->quote($this->params($request), $this->actor());
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handleCounts(WP_REST_Request $request): array
    {
        $hub = $request->get_param('hub');
        return ntdst_get(ShipmentService::class)->counts($this->actor(), is_string($hub) && $hub !== '' ? $hub : null);
    }

    public function handleDashboard(WP_REST_Request $request): array
    {
        $hub = $request->get_param('hub');
        return ntdst_get(DashboardService::class)->stats(is_string($hub) && $hub !== '' ? $hub : null);
    }

    public function handleExport(WP_REST_Request $request): WP_Error
    {
        $from = (string) ($request->get_param('from') ?? '');
        $to = (string) ($request->get_param('to') ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return $this->fail(new WP_Error('invalid_period', 'Geef een periode op (from, to als YYYY-MM-DD).'));
        }
        $customerId = (int) ($request->get_param('customer_id') ?? 0) ?: null;
        $csv = ntdst_get(ExportService::class)->deliveredCsv($from, $to, $customerId);
        $this->download($csv, sprintf('cargovelo-geleverd-%s-%s.csv', $from, $to), 'text/csv; charset=UTF-8');
    }

    // ------------------------------------------------------------ couriers

    public function handleCouriers(WP_REST_Request $request): array
    {
        $hub = $request->get_param('hub');
        $rows = ntdst_get(CourierRepository::class)->all(is_string($hub) ? $hub : null, (bool) $request->get_param('active'));
        return ['items' => array_map([CourierRepository::class, 'toArray'], $rows)];
    }

    public function handleCourierCreate(WP_REST_Request $request): array|WP_Error
    {
        $params = $this->params($request);
        if (trim((string) ($params['name'] ?? '')) === '') {
            return $this->fail(new WP_Error('invalid_courier', 'Naam is verplicht.'));
        }
        $repo = ntdst_get(CourierRepository::class);
        $id = $repo->create($params);
        if ($id instanceof WP_Error) {
            return $this->fail($id, 500);
        }
        $this->syncCourierRole($params);
        return CourierRepository::toArray($repo->find($id));
    }

    public function handleCourierUpdate(WP_REST_Request $request): array|WP_Error
    {
        $repo = ntdst_get(CourierRepository::class);
        $id = $this->intArg($request, 'id');
        if ($repo->find($id) === null) {
            return $this->fail(new WP_Error('not_found', 'Koerier niet gevonden.'), 404);
        }
        $params = $this->params($request);
        unset($params['id']);
        $ok = $repo->update($id, $params);
        if ($ok instanceof WP_Error) {
            return $this->fail($ok, 500);
        }
        $this->syncCourierRole($params);
        return CourierRepository::toArray($repo->find($id));
    }

    public function handleReorder(WP_REST_Request $request): array|WP_Error
    {
        $ids = $request->get_param('shipment_ids');
        if (!is_array($ids)) {
            return $this->fail(new WP_Error('invalid_order', 'shipment_ids ontbreekt.'));
        }
        $r = ntdst_get(ShipmentService::class)->reorderStops($this->intArg($request, 'id'), array_map('intval', $ids), $this->actor());
        return $r instanceof WP_Error ? $this->fail($r) : ['ok' => true];
    }

    /** A courier linked to a WordPress user gets the courier role so the courier app opens for them. */
    private function syncCourierRole(array $params): void
    {
        $userId = (int) ($params['user_id'] ?? 0);
        if ($userId > 0) {
            $user = get_userdata($userId);
            if ($user && !in_array(Roles::ROLE_COURIER, (array) $user->roles, true) && !user_can($userId, Roles::CAP_DISPATCH)) {
                $user->add_role(Roles::ROLE_COURIER);
            }
        }
    }

    // ------------------------------------------------------------ customers

    public function handleCustomers(WP_REST_Request $request): array
    {
        $rows = ntdst_get(CustomerRepository::class)->all((string) ($request->get_param('search') ?? ''), (bool) $request->get_param('active'));
        return ['items' => array_map([CustomerRepository::class, 'toArray'], $rows)];
    }

    public function handleCustomerCreate(WP_REST_Request $request): array|WP_Error
    {
        $params = $this->params($request);
        if (trim((string) ($params['name'] ?? '')) === '') {
            return $this->fail(new WP_Error('invalid_customer', 'Naam is verplicht.'));
        }
        $repo = ntdst_get(CustomerRepository::class);
        $id = $repo->create($params);
        if ($id instanceof WP_Error) {
            return $this->fail($id, 500);
        }
        $this->linkCustomerUsers($id, $params);
        return CustomerRepository::toArray($repo->find($id));
    }

    public function handleCustomerUpdate(WP_REST_Request $request): array|WP_Error
    {
        $repo = ntdst_get(CustomerRepository::class);
        $id = $this->intArg($request, 'id');
        if ($repo->find($id) === null) {
            return $this->fail(new WP_Error('not_found', 'Klant niet gevonden.'), 404);
        }
        $params = $this->params($request);
        unset($params['id']);
        $ok = $repo->update($id, $params);
        if ($ok instanceof WP_Error) {
            return $this->fail($ok, 500);
        }
        $this->linkCustomerUsers($id, $params);
        return CustomerRepository::toArray($repo->find($id));
    }

    public function handleCustomerAddresses(WP_REST_Request $request): array
    {
        $rows = ntdst_get(AddressRepository::class)->forCustomer($this->intArg($request, 'id'));
        return ['items' => array_map([AddressRepository::class, 'toArray'], $rows)];
    }

    /**
     * Optional "user_ids": WordPress users who may book for this customer. They get the customer
     * role and the customer id in user meta.
     * @param array<string, mixed> $params
     */
    private function linkCustomerUsers(int $customerId, array $params): void
    {
        if (!isset($params['user_ids']) || !is_array($params['user_ids'])) {
            return;
        }
        foreach ($params['user_ids'] as $userId) {
            $user = get_userdata((int) $userId);
            if (!$user) {
                continue;
            }
            update_user_meta((int) $userId, Roles::META_CUSTOMER_ID, $customerId);
            if (!in_array(Roles::ROLE_CUSTOMER, (array) $user->roles, true) && !user_can((int) $userId, Roles::CAP_DISPATCH)) {
                $user->add_role(Roles::ROLE_CUSTOMER);
            }
        }
    }

    // ------------------------------------------------------------ prices + settings

    public function handlePriceLists(WP_REST_Request $request): array
    {
        return ['items' => ntdst_get(PriceListRepository::class)->all()];
    }

    public function handlePriceListCreate(WP_REST_Request $request): array|WP_Error
    {
        $repo = ntdst_get(PriceListRepository::class);
        $name = trim((string) ($request->get_param('name') ?? ''));
        if ($name === '') {
            return $this->fail(new WP_Error('invalid_name', 'Naam is verplicht.'));
        }
        $id = $repo->create($name);
        if ($id instanceof WP_Error) {
            return $this->fail($id, 500);
        }
        return $repo->find($id) ?? [];
    }

    public function handlePriceListUpdate(WP_REST_Request $request): array|WP_Error
    {
        $repo = ntdst_get(PriceListRepository::class);
        $id = $this->intArg($request, 'id');
        if ($repo->find($id) === null) {
            return $this->fail(new WP_Error('not_found', 'Prijslijst niet gevonden.'), 404);
        }
        $name = $request->get_param('name');
        if (is_string($name) && trim($name) !== '') {
            $repo->rename($id, $name);
        }
        $rules = $request->get_param('rules');
        if (is_array($rules)) {
            foreach ($rules as $rule) {
                if (is_array($rule)) {
                    $ok = $repo->saveRule($id, $rule);
                    if ($ok instanceof WP_Error) {
                        return $this->fail($ok);
                    }
                }
            }
        }
        return $repo->find($id) ?? [];
    }

    public function handleSettings(WP_REST_Request $request): array
    {
        return ntdst_get(SettingsService::class)->all();
    }

    public function handleSettingsUpdate(WP_REST_Request $request): array|WP_Error
    {
        $params = $this->params($request);
        $clean = [];
        if (isset($params['hubs']) && is_array($params['hubs'])) {
            $hubs = [];
            foreach ($params['hubs'] as $h) {
                if (!is_array($h) || empty($h['code'])) {
                    continue;
                }
                $ranges = [];
                foreach ((array) ($h['postcodes'] ?? []) as $r) {
                    if (is_string($r) && preg_match('/^(\d{4})-(\d{4})$/', $r, $m)) {
                        $ranges[] = [(int) $m[1], (int) $m[2]];
                    } elseif (is_array($r) && count($r) === 2) {
                        $ranges[] = [(int) $r[0], (int) $r[1]];
                    }
                }
                $hubs[] = [
                    'code' => strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $h['code']) ?? '', 0, 6)),
                    'name' => mb_substr(trim((string) ($h['name'] ?? '')), 0, 80),
                    'city' => mb_substr(trim((string) ($h['city'] ?? '')), 0, 80),
                    'postcodes' => $ranges,
                    'cutoff_sameday' => preg_match('/^\d{2}:\d{2}$/', (string) ($h['cutoff_sameday'] ?? '')) ? $h['cutoff_sameday'] : '16:00',
                ];
            }
            $clean['hubs'] = $hubs;
        }
        if (isset($params['notify_email'])) {
            $clean['notify_email'] = filter_var((string) $params['notify_email'], FILTER_VALIDATE_EMAIL) ? (string) $params['notify_email'] : '';
        }
        if (isset($params['tracking_page'])) {
            $clean['tracking_page'] = '/' . trim(mb_substr((string) $params['tracking_page'], 0, 120), '/') . '/';
        }
        $settings = ntdst_get(SettingsService::class);
        $settings->update($clean);
        return $settings->all();
    }
}
