<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Shipment;

use CargoVelo\Domain\Actor;
use CargoVelo\Domain\Address;
use CargoVelo\Domain\Channel;
use CargoVelo\Domain\Parcels;
use CargoVelo\Domain\Role;
use CargoVelo\Domain\ServiceCode;
use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Modules\Courier\CourierRepository;
use CargoVelo\Modules\Customer\CustomerRepository;
use CargoVelo\Modules\Pricing\PricingService;
use CargoVelo\Modules\Settings\SettingsService;
use WP_Error;

/**
 * Every shipment operation from every channel. Handlers validate shape and delegate here;
 * this class decides scope (who may see or change what) and the lifecycle.
 *
 * Domain events (array payload): cargovelo/shipment/created, cargovelo/shipment/status_changed,
 * cargovelo/shipment/assigned, cargovelo/shipment/note.
 */
final class ShipmentService
{
    public const FAIL_REASONS = ['not_home', 'closed', 'wrong_address', 'refused', 'damaged', 'not_ready', 'other'];

    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly ShipmentEventRepository $events,
        private readonly CustomerRepository $customers,
        private readonly CourierRepository $couriers,
        private readonly PricingService $pricing,
        private readonly SettingsService $settings,
        private readonly ReferenceGenerator $references,
        private readonly PodStorage $pod,
    ) {
        $this->init();
    }

    private function init(): void
    {
        // No hooks: this service is called by handlers and by NotificationService listeners only.
    }

    // ---------------------------------------------------------------- reads

    /** @return array<string, mixed>|WP_Error */
    public function get(int $id, Actor $actor, bool $withEvents = true): array|WP_Error
    {
        $shipment = $this->shipments->find($id);
        if ($shipment === null || !$this->canSee($shipment, $actor)) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        $this->decorate($shipment);
        if ($withEvents) {
            $shipment['events'] = $this->events->forShipment($id);
            if ($actor->role === Role::Customer) {
                // Customers see the timeline, not internal notes.
                $shipment['events'] = array_values(array_filter(
                    $shipment['events'],
                    static fn(array $e): bool => $e['type'] !== ShipmentEventRepository::TYPE_NOTE || ($e['payload']['visible_to_customer'] ?? false)
                ));
            }
        }
        return $shipment;
    }

    /** @return array{items: list<array<string,mixed>>, total:int, page:int, per_page:int} */
    public function list(ShipmentFilter $filter, Actor $actor): array
    {
        $this->scopeFilter($filter, $actor);
        $result = $this->shipments->list($filter);
        $names = $this->courierNames($result['items']);
        foreach ($result['items'] as &$item) {
            $item['courier_name'] = $item['courier_id'] !== null ? ($names[$item['courier_id']] ?? null) : null;
        }
        return $result;
    }

    /** @return array{by_status: array<string,int>, exceptions:int, today:int} */
    public function counts(Actor $actor, ?string $hub = null): array
    {
        return match ($actor->role) {
            Role::Customer => $this->shipments->counts($actor->customerId ?? -1),
            Role::Courier => ['by_status' => [], 'exceptions' => 0, 'today' => count($this->stopsForCourier($actor))],
            default => $this->shipments->counts(null, $hub),
        };
    }

    /** @return list<array<string, mixed>> */
    public function stopsForCourier(Actor $actor, ?string $date = null): array
    {
        if ($actor->courierId === null) {
            return [];
        }
        $date ??= function_exists('wp_date') ? wp_date('Y-m-d') : date('Y-m-d');
        $stops = $this->shipments->stopsForCourier($actor->courierId, $date);
        foreach ($stops as &$s) {
            $s['courier_name'] = $actor->name;
        }
        return $stops;
    }

    // ---------------------------------------------------------------- create

    /**
     * @param array<string, mixed> $input see app/src/domain/types.ts::ShipmentInput
     * @return array<string, mixed>|WP_Error
     */
    public function quote(array $input, Actor $actor): array|WP_Error
    {
        $service = self::service($input['service'] ?? null);
        if ($service instanceof WP_Error) {
            return $service;
        }
        $parcels = Parcels::fromArray($input['parcels'] ?? null);
        if ($parcels instanceof WP_Error) {
            return $parcels;
        }
        $customer = $this->resolveCustomerForRead($input, $actor);
        return $this->pricing->quote($service, $parcels, $customer['price_list_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    public function create(array $input, Actor $actor, Channel $channel): array|WP_Error
    {
        $service = self::service($input['service'] ?? null);
        if ($service instanceof WP_Error) {
            return $service;
        }
        $pickup = Address::fromArray(is_array($input['pickup'] ?? null) ? $input['pickup'] : [], 'Ophaaladres');
        if ($pickup instanceof WP_Error) {
            return $pickup;
        }
        $delivery = Address::fromArray(is_array($input['delivery'] ?? null) ? $input['delivery'] : [], 'Leveradres');
        if ($delivery instanceof WP_Error) {
            return $delivery;
        }
        $parcels = Parcels::fromArray($input['parcels'] ?? null);
        if ($parcels instanceof WP_Error) {
            return $parcels;
        }

        // Both ends must be inside a served zone; the pickup hub owns the shipment. Different hubs
        // (Gent -> Antwerpen) are accepted and bundled hub-to-hub by dispatch.
        $hub = $this->settings->hubForPostcode($pickup->postcode);
        if ($hub === null) {
            return new WP_Error('outside_zone', 'Het ophaaladres ligt buiten onze zones (Gent, Antwerpen, Brussel, Mechelen, Leuven).', ['field' => 'pickup']);
        }
        if ($this->settings->hubForPostcode($delivery->postcode) === null) {
            return new WP_Error('outside_zone', 'Het leveradres ligt buiten onze zones (Gent, Antwerpen, Brussel, Mechelen, Leuven).', ['field' => 'delivery']);
        }

        $windows = $this->resolveWindows($service, $input, $hub);
        if ($windows instanceof WP_Error) {
            return $windows;
        }

        $customer = $this->resolveCustomerForWrite($input, $actor, $pickup);
        if ($customer instanceof WP_Error) {
            return $customer;
        }

        $quote = $this->pricing->quote($service, $parcels, $customer['price_list_id'] ?? null);
        $status = ($actor->isStaff() || ($customer['type'] ?? '') === 'account') ? ShipmentStatus::Confirmed : ShipmentStatus::Requested;

        $reference = $this->references->reference();
        for ($i = 0; $i < 5 && $this->shipments->referenceExists($reference); $i++) {
            $reference = $this->references->reference();
        }

        $row = [
            'reference' => $reference,
            'customer_id' => $customer['id'] ?? null,
            'customer_name' => (string) ($customer['name'] ?? $pickup->company ?: $pickup->name),
            'contact_email' => (string) ($input['contact_email'] ?? $customer['email'] ?? $pickup->email),
            'service' => $service->value,
            'hub' => $hub,
            'status' => $status->value,
            'exception' => null,
            'pickup_json' => json_encode($pickup->toArray(), JSON_UNESCAPED_UNICODE),
            'delivery_json' => json_encode($delivery->toArray(), JSON_UNESCAPED_UNICODE),
            'pickup_start' => $windows['pickup_start'],
            'pickup_end' => $windows['pickup_end'],
            'delivery_start' => $windows['delivery_start'],
            'delivery_end' => $windows['delivery_end'],
            'parcels_json' => json_encode($parcels->toArray()),
            'price_cents' => $quote['price_cents'],
            'price_override_reason' => null,
            'courier_id' => null,
            'stop_sequence' => null,
            'channel' => $channel->value,
            'tracking_token' => $this->references->token(),
            'remarks' => mb_substr(trim((string) ($input['remarks'] ?? '')), 0, 2000),
            'created_by' => $actor->userId,
        ];

        $id = $this->shipments->insert($row);
        if ($id instanceof WP_Error) {
            return $id;
        }
        $this->events->append($id, ShipmentEventRepository::TYPE_STATUS, $actor, ['channel' => $channel->value, 'quote' => $quote], null, $status);

        $shipment = $this->shipments->find($id) ?? [];
        $this->decorate($shipment);
        do_action('cargovelo/shipment/created', ['shipment' => $shipment, 'actor' => $actor->toArray()]);
        cargovelo_log('shipment')->info('created', ['id' => $id, 'reference' => $reference, 'channel' => $channel->value]);
        return $shipment;
    }

    // ---------------------------------------------------------------- lifecycle

    /**
     * @param array<string, mixed> $payload reason (failed), pod fields (delivered), note
     * @return array<string, mixed>|WP_Error
     */
    public function transition(int $id, ShipmentStatus $to, Actor $actor, array $payload = []): array|WP_Error
    {
        $shipment = $this->shipments->find($id);
        if ($shipment === null || !$this->canSee($shipment, $actor)) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        $from = ShipmentStatus::from($shipment['status']);

        $allowed = $this->roleAllows($from, $to, $actor);
        if ($allowed instanceof WP_Error) {
            return $allowed;
        }
        if (!$from->canTransitionTo($to)) {
            return new WP_Error('invalid_transition', sprintf('Van "%s" naar "%s" is niet mogelijk.', $from->label(), $to->label()), ['status' => 409]);
        }

        $extra = [];
        $eventPayload = [];
        if ($to === ShipmentStatus::Failed) {
            $reason = (string) ($payload['reason'] ?? 'other');
            if (!in_array($reason, self::FAIL_REASONS, true)) {
                $reason = 'other';
            }
            $extra['exception'] = $reason;
            $eventPayload['reason'] = $reason;
        }
        if ($to === ShipmentStatus::Confirmed || $to === ShipmentStatus::Delivered) {
            $extra['exception'] = null;
        }
        if ($to === ShipmentStatus::Confirmed && $from === ShipmentStatus::Assigned) {
            $extra['courier_id'] = null;
            $extra['stop_sequence'] = null;
        }
        if ($to === ShipmentStatus::Cancelled) {
            $extra['exception'] = null;
            $eventPayload['reason'] = mb_substr(trim((string) ($payload['reason'] ?? '')), 0, 200);
        }
        if (!empty($payload['note'])) {
            $eventPayload['note'] = mb_substr(trim((string) $payload['note']), 0, 1000);
        }

        if (!$this->shipments->transition($id, $from, $to, $extra)) {
            return new WP_Error('conflict', 'De zending is intussen gewijzigd. Herlaad en probeer opnieuw.', ['status' => 409]);
        }

        if ($to === ShipmentStatus::Delivered) {
            $podResult = $this->recordPod($id, $actor, $payload);
            if ($podResult instanceof WP_Error) {
                cargovelo_log('shipment')->warning('pod not stored', ['id' => $id, 'error' => $podResult->get_error_message()]);
                $eventPayload['pod_error'] = $podResult->get_error_message();
            }
        }
        $this->events->append($id, ShipmentEventRepository::TYPE_STATUS, $actor, $eventPayload, $from, $to);

        $updated = $this->get($id, $actor);
        if (!$updated instanceof WP_Error) {
            do_action('cargovelo/shipment/status_changed', [
                'shipment' => $updated, 'from' => $from->value, 'to' => $to->value, 'actor' => $actor->toArray(), 'payload' => $eventPayload,
            ]);
        }
        return $updated;
    }

    /** Staff only. $courierId null = unassign. @return array<string, mixed>|WP_Error */
    public function assign(int $id, ?int $courierId, Actor $actor, ?int $stopSequence = null): array|WP_Error
    {
        if (!$actor->isStaff()) {
            return new WP_Error('forbidden', 'Alleen dispatch kan toewijzen.', ['status' => 403]);
        }
        $shipment = $this->shipments->find($id);
        if ($shipment === null) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        $from = ShipmentStatus::from($shipment['status']);

        if ($courierId === null) {
            if ($from !== ShipmentStatus::Assigned) {
                return new WP_Error('invalid_transition', 'Alleen een toegewezen zending kan losgekoppeld worden.', ['status' => 409]);
            }
            return $this->transition($id, ShipmentStatus::Confirmed, $actor);
        }

        $courier = $this->couriers->find($courierId);
        if ($courier === null || !(bool) $courier->active) {
            return new WP_Error('invalid_courier', 'Koerier niet gevonden of inactief.', ['status' => 400]);
        }
        if (!in_array($from, [ShipmentStatus::Confirmed, ShipmentStatus::Assigned, ShipmentStatus::PickedUp, ShipmentStatus::InTransit], true)) {
            return new WP_Error('invalid_transition', sprintf('Een zending met status "%s" kan niet toegewezen worden.', $from->label()), ['status' => 409]);
        }
        $to = $from === ShipmentStatus::Confirmed ? ShipmentStatus::Assigned : $from;
        $extra = ['courier_id' => $courierId, 'stop_sequence' => $stopSequence];
        if (!$this->shipments->transition($id, $from, $to, $extra)) {
            return new WP_Error('conflict', 'De zending is intussen gewijzigd.', ['status' => 409]);
        }
        $this->events->append($id, ShipmentEventRepository::TYPE_ASSIGNMENT, $actor, [
            'courier_id' => $courierId, 'courier_name' => (string) $courier->name, 'stop_sequence' => $stopSequence,
        ], $from, $to);

        $updated = $this->get($id, $actor);
        if (!$updated instanceof WP_Error) {
            do_action('cargovelo/shipment/assigned', ['shipment' => $updated, 'actor' => $actor->toArray()]);
        }
        return $updated;
    }

    /** Staff only: reorder a courier's stops. @param list<int> $orderedIds */
    public function reorderStops(int $courierId, array $orderedIds, Actor $actor): true|WP_Error
    {
        if (!$actor->isStaff()) {
            return new WP_Error('forbidden', 'Alleen dispatch kan herschikken.', ['status' => 403]);
        }
        $seq = 1;
        foreach ($orderedIds as $id) {
            $shipment = $this->shipments->find((int) $id);
            if ($shipment === null || $shipment['courier_id'] !== $courierId) {
                continue;
            }
            $this->shipments->update((int) $id, ['stop_sequence' => $seq++]);
        }
        return true;
    }

    /** Staff only. @return array<string, mixed>|WP_Error */
    public function overridePrice(int $id, int $cents, string $reason, Actor $actor): array|WP_Error
    {
        if (!$actor->isStaff()) {
            return new WP_Error('forbidden', 'Alleen dispatch kan de prijs aanpassen.', ['status' => 403]);
        }
        $reason = mb_substr(trim($reason), 0, 200);
        if ($cents < 0 || $reason === '') {
            return new WP_Error('invalid_price', 'Prijs en reden zijn verplicht.', ['status' => 400]);
        }
        $shipment = $this->shipments->find($id);
        if ($shipment === null) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        $ok = $this->shipments->update($id, ['price_cents' => $cents, 'price_override_reason' => $reason]);
        if ($ok instanceof WP_Error) {
            return $ok;
        }
        $this->events->append($id, ShipmentEventRepository::TYPE_PRICE, $actor, ['from_cents' => $shipment['price_cents'], 'to_cents' => $cents, 'reason' => $reason]);
        return $this->get($id, $actor);
    }

    /** @return array<string, mixed>|WP_Error */
    public function addNote(int $id, string $note, Actor $actor, bool $visibleToCustomer = false): array|WP_Error
    {
        $shipment = $this->shipments->find($id);
        if ($shipment === null || !$this->canSee($shipment, $actor)) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        $note = mb_substr(trim($note), 0, 2000);
        if ($note === '') {
            return new WP_Error('invalid_note', 'Lege notitie.', ['status' => 400]);
        }
        $this->events->append($id, ShipmentEventRepository::TYPE_NOTE, $actor, [
            'note' => $note,
            'visible_to_customer' => $visibleToCustomer || $actor->role === Role::Customer,
        ]);
        do_action('cargovelo/shipment/note', ['shipment_id' => $id, 'note' => $note, 'actor' => $actor->toArray()]);
        return $this->get($id, $actor);
    }

    /**
     * Staff edit of an open shipment: addresses, windows, parcels, remarks, service. Re-prices unless overridden.
     * @param array<string, mixed> $patch
     * @return array<string, mixed>|WP_Error
     */
    public function updateDetails(int $id, array $patch, Actor $actor): array|WP_Error
    {
        if (!$actor->isStaff()) {
            return new WP_Error('forbidden', 'Alleen dispatch kan een zending bewerken.', ['status' => 403]);
        }
        $shipment = $this->shipments->find($id);
        if ($shipment === null) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        if (ShipmentStatus::from($shipment['status'])->isClosed()) {
            return new WP_Error('closed', 'Een afgesloten zending kan niet bewerkt worden.', ['status' => 409]);
        }

        $row = [];
        $changes = [];
        $service = ServiceCode::from($shipment['service']);
        if (isset($patch['service'])) {
            $service = self::service($patch['service']);
            if ($service instanceof WP_Error) {
                return $service;
            }
            $row['service'] = $service->value;
            $changes[] = 'service';
        }
        foreach (['pickup' => 'Ophaaladres', 'delivery' => 'Leveradres'] as $key => $label) {
            if (isset($patch[$key]) && is_array($patch[$key])) {
                $addr = Address::fromArray($patch[$key], $label);
                if ($addr instanceof WP_Error) {
                    return $addr;
                }
                $row[$key . '_json'] = json_encode($addr->toArray(), JSON_UNESCAPED_UNICODE);
                $changes[] = $key;
            }
        }
        $parcels = Parcels::fromArray($shipment['parcels']);
        if (isset($patch['parcels'])) {
            $parcels = Parcels::fromArray($patch['parcels']);
            if ($parcels instanceof WP_Error) {
                return $parcels;
            }
            $row['parcels_json'] = json_encode($parcels->toArray());
            $changes[] = 'parcels';
        }
        foreach (['pickup_window' => 'pickup', 'delivery_window' => 'delivery'] as $key => $prefix) {
            if (array_key_exists($key, $patch)) {
                $w = is_array($patch[$key]) ? $patch[$key] : [];
                $row[$prefix . '_start'] = self::dt($w['start'] ?? null);
                $row[$prefix . '_end'] = self::dt($w['end'] ?? null);
                $changes[] = $key;
            }
        }
        if (array_key_exists('remarks', $patch)) {
            $row['remarks'] = mb_substr(trim((string) $patch['remarks']), 0, 2000);
            $changes[] = 'remarks';
        }
        if (array_key_exists('customer_id', $patch)) {
            $customer = $this->customers->find((int) $patch['customer_id']);
            if ($customer === null) {
                return new WP_Error('invalid_customer', 'Klant niet gevonden.', ['status' => 400]);
            }
            $row['customer_id'] = (int) $customer->id;
            $row['customer_name'] = (string) $customer->name;
            $changes[] = 'customer';
        }
        if ($row === []) {
            return $this->get($id, $actor);
        }
        if ($shipment['price_override_reason'] === null && (isset($row['service']) || isset($row['parcels_json']) || isset($row['customer_id']))) {
            $customerId = $row['customer_id'] ?? $shipment['customer_id'];
            $customer = $customerId ? $this->customers->find((int) $customerId) : null;
            $row['price_cents'] = $this->pricing->quote($service, $parcels, $customer?->price_list_id ? (int) $customer->price_list_id : null)['price_cents'];
        }
        $ok = $this->shipments->update($id, $row);
        if ($ok instanceof WP_Error) {
            return $ok;
        }
        $this->events->append($id, ShipmentEventRepository::TYPE_EDIT, $actor, ['fields' => $changes]);
        return $this->get($id, $actor);
    }

    /** Recipient-facing: update delivery instructions from the tracking page while the shipment is open. */
    public function setInstructionsByToken(string $token, string $instructions): true|WP_Error
    {
        $shipment = $this->shipments->findByToken($token);
        if ($shipment === null) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        $status = ShipmentStatus::from($shipment['status']);
        if (!$status->isOpen()) {
            return new WP_Error('closed', 'Deze zending is afgesloten.', ['status' => 409]);
        }
        $delivery = Address::fromArray($shipment['delivery'], 'Leveradres');
        if ($delivery instanceof WP_Error) {
            return $delivery;
        }
        $delivery = $delivery->withInstructions($instructions);
        $ok = $this->shipments->update($shipment['id'], ['delivery_json' => json_encode($delivery->toArray(), JSON_UNESCAPED_UNICODE)]);
        if ($ok instanceof WP_Error) {
            return $ok;
        }
        $this->events->append($shipment['id'], ShipmentEventRepository::TYPE_NOTE, Actor::guest('Ontvanger'), [
            'note' => 'Leverinstructies aangepast: ' . $delivery->instructions,
            'visible_to_customer' => true,
        ]);
        return true;
    }

    /** @return array{bytes:string, mime:string}|WP_Error */
    public function podImage(int $id, string $kind, Actor $actor): array|WP_Error
    {
        $shipment = $this->shipments->find($id);
        if ($shipment === null || !$this->canSee($shipment, $actor)) {
            return new WP_Error('not_found', 'Zending niet gevonden.', ['status' => 404]);
        }
        $pod = $this->events->latestPod($id);
        $path = $pod['payload'][$kind . '_path'] ?? null;
        $img = is_string($path) ? $this->pod->read($path) : null;
        return $img ?? new WP_Error('not_found', 'Geen bewijs beschikbaar.', ['status' => 404]);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $shipment */
    public function canSee(array $shipment, Actor $actor): bool
    {
        return match ($actor->role) {
            Role::Admin, Role::Dispatcher => true,
            Role::Customer => $actor->customerId !== null && $shipment['customer_id'] === $actor->customerId,
            Role::Courier => $actor->courierId !== null && $shipment['courier_id'] === $actor->courierId,
            Role::Guest => false,
        };
    }

    private function roleAllows(ShipmentStatus $from, ShipmentStatus $to, Actor $actor): true|WP_Error
    {
        return match ($actor->role) {
            Role::Admin, Role::Dispatcher => true,
            Role::Courier => in_array($to, ShipmentStatus::courierSettable(), true)
                ? true
                : new WP_Error('forbidden', 'Een koerier kan deze status niet zetten.', ['status' => 403]),
            Role::Customer => ($to === ShipmentStatus::Cancelled && in_array($from, [ShipmentStatus::Requested, ShipmentStatus::Confirmed], true))
                ? true
                : new WP_Error('forbidden', 'Annuleren kan enkel zolang de zending niet toegewezen is. Neem contact op met dispatch.', ['status' => 403]),
            Role::Guest => new WP_Error('forbidden', 'Niet toegestaan.', ['status' => 403]),
        };
    }

    private function scopeFilter(ShipmentFilter $filter, Actor $actor): void
    {
        switch ($actor->role) {
            case Role::Customer:
                $filter->customerId = $actor->customerId ?? -1;
                break;
            case Role::Courier:
                $filter->courierId = $actor->courierId ?? -1;
                break;
            case Role::Guest:
                $filter->customerId = -1;
                break;
            default:
                break;
        }
    }

    /** @param array<string, mixed> $shipment */
    private function decorate(array &$shipment): void
    {
        if (($shipment['courier_id'] ?? null) !== null) {
            $courier = $this->couriers->find((int) $shipment['courier_id']);
            $shipment['courier_name'] = $courier ? (string) $courier->name : null;
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<int, string>
     */
    private function courierNames(array $items): array
    {
        $ids = array_values(array_unique(array_filter(array_column($items, 'courier_id'))));
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach ($this->couriers->all() as $c) {
            $names[(int) $c->id] = (string) $c->name;
        }
        return $names;
    }

    /** @param array<string, mixed> $payload */
    private function recordPod(int $id, Actor $actor, array $payload): true|WP_Error
    {
        $pod = [
            'receiver_name' => mb_substr(trim((string) ($payload['receiver_name'] ?? '')), 0, 120),
            'note' => mb_substr(trim((string) ($payload['note'] ?? '')), 0, 500),
            'lat' => isset($payload['lat']) ? (float) $payload['lat'] : null,
            'lng' => isset($payload['lng']) ? (float) $payload['lng'] : null,
            'delivered_at' => cargovelo_now(),
        ];
        foreach (['photo', 'signature'] as $kind) {
            $data = $payload[$kind . '_base64'] ?? null;
            if (is_string($data) && $data !== '') {
                $path = $this->pod->store($id, $kind, $data);
                if ($path instanceof WP_Error) {
                    return $path;
                }
                $pod[$kind . '_path'] = $path;
            }
        }
        $this->events->append($id, ShipmentEventRepository::TYPE_POD, $actor, $pod);
        return true;
    }

    private static function service(mixed $raw): ServiceCode|WP_Error
    {
        $service = ServiceCode::tryFrom((string) $raw);
        return $service ?? new WP_Error('invalid_service', 'Kies een dienst.', ['field' => 'service']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{pickup_start:?string, pickup_end:?string, delivery_start:?string, delivery_end:?string}|WP_Error
     */
    private function resolveWindows(ServiceCode $service, array $input, string $hub): array|WP_Error
    {
        $now = new \DateTimeImmutable(cargovelo_now());
        $pickupStart = self::dt($input['pickup_window']['start'] ?? null);
        $pickupEnd = self::dt($input['pickup_window']['end'] ?? null);
        $deliveryStart = self::dt($input['delivery_window']['start'] ?? null);
        $deliveryEnd = self::dt($input['delivery_window']['end'] ?? null);

        switch ($service) {
            case ServiceCode::Express:
                $pickupStart ??= $now->format('Y-m-d H:i:s');
                $deliveryEnd ??= (new \DateTimeImmutable($pickupStart))->modify('+' . $service->slaMinutes() . ' minutes')->format('Y-m-d H:i:s');
                break;
            case ServiceCode::SameDay:
                $cutoff = $this->settings->hub($hub)['cutoff_sameday'] ?? '16:00';
                if ($now->format('H:i') > $cutoff) {
                    return new WP_Error('cutoff_passed', sprintf('De cut-off voor same day (%s) is voorbij. Kies next day of een geplande levering.', $cutoff), ['field' => 'service']);
                }
                $pickupStart ??= $now->format('Y-m-d H:i:s');
                $deliveryEnd ??= $now->setTime(18, 0)->format('Y-m-d H:i:s');
                break;
            case ServiceCode::NextDay:
                $next = $now->modify('+1 day');
                while ((int) $next->format('N') >= 6) {
                    $next = $next->modify('+1 day');
                }
                $pickupStart ??= $next->setTime(9, 0)->format('Y-m-d H:i:s');
                $deliveryEnd ??= $next->setTime(18, 0)->format('Y-m-d H:i:s');
                break;
            case ServiceCode::Scheduled:
                if ($pickupStart === null) {
                    return new WP_Error('window_required', 'Kies een datum en tijdstip voor de ophaling.', ['field' => 'pickup_window']);
                }
                if (new \DateTimeImmutable($pickupStart) < $now->modify('-5 minutes')) {
                    return new WP_Error('window_past', 'Het ophaalmoment ligt in het verleden.', ['field' => 'pickup_window']);
                }
                $deliveryEnd ??= (new \DateTimeImmutable($pickupStart))->modify('+4 hours')->format('Y-m-d H:i:s');
                break;
        }
        if ($pickupEnd !== null && $pickupStart !== null && $pickupEnd < $pickupStart) {
            return new WP_Error('window_invalid', 'Einde van het ophaalvenster ligt voor het begin.', ['field' => 'pickup_window']);
        }
        return ['pickup_start' => $pickupStart, 'pickup_end' => $pickupEnd, 'delivery_start' => $deliveryStart, 'delivery_end' => $deliveryEnd];
    }

    /** Parse an ISO-ish local datetime into "Y-m-d H:i:s", or null. */
    public static function dt(mixed $raw): ?string
    {
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($s))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    private function resolveCustomerForRead(array $input, Actor $actor): ?array
    {
        $id = $actor->role === Role::Customer ? $actor->customerId : ($actor->isStaff() ? (int) ($input['customer_id'] ?? 0) : null);
        $row = $id ? $this->customers->find((int) $id) : null;
        return $row ? CustomerRepository::toArray($row) : null;
    }

    /**
     * Customer users book for their customer. Staff may pick any customer. Guests are matched by
     * e-mail to an existing customer or get an "occasional" customer record, so the second booking
     * from the same shop already has history.
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    private function resolveCustomerForWrite(array $input, Actor $actor, Address $pickup): array|WP_Error
    {
        if ($actor->role === Role::Customer) {
            $row = $actor->customerId ? $this->customers->find($actor->customerId) : null;
            return $row ? CustomerRepository::toArray($row) : new WP_Error('no_customer', 'Je account is nog niet aan een klant gekoppeld. Contacteer Cargo Velo.', ['status' => 403]);
        }
        if ($actor->isStaff() && (int) ($input['customer_id'] ?? 0) > 0) {
            $row = $this->customers->find((int) $input['customer_id']);
            return $row ? CustomerRepository::toArray($row) : new WP_Error('invalid_customer', 'Klant niet gevonden.', ['field' => 'customer_id']);
        }
        $email = strtolower(trim((string) ($input['contact_email'] ?? $pickup->email)));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new WP_Error('contact_required', 'Een geldig e-mailadres is verplicht voor de bevestiging.', ['field' => 'contact_email']);
        }
        $existing = $this->customers->findByEmail($email);
        if ($existing) {
            return CustomerRepository::toArray($existing);
        }
        $name = trim((string) ($input['contact_company'] ?? '')) ?: ($pickup->company ?: (trim((string) ($input['contact_name'] ?? '')) ?: $pickup->name));
        $id = $this->customers->create([
            'name' => $name,
            'type' => 'occasional',
            'email' => $email,
            'phone' => $pickup->phone,
            'hub' => $this->settings->hubForPostcode($pickup->postcode) ?? '',
        ]);
        if ($id instanceof WP_Error) {
            return $id;
        }
        $row = $this->customers->find($id);
        return $row ? CustomerRepository::toArray($row) : new WP_Error('db_error', 'Klant aanmaken mislukt.');
    }
}
