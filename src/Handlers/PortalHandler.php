<?php
declare(strict_types=1);

namespace CargoVelo\Handlers;

use CargoVelo\Domain\Address;
use CargoVelo\Domain\Channel;
use CargoVelo\Domain\Role;
use CargoVelo\Domain\ShipmentStatus;
use CargoVelo\Modules\Access\Roles;
use CargoVelo\Modules\Customer\AddressRepository;
use CargoVelo\Modules\Import\CsvImportService;
use CargoVelo\Modules\Shipment\ShipmentFilter;
use CargoVelo\Modules\Shipment\ShipmentService;
use CargoVelo\Support\RestSupport;
use WP_Error;
use WP_REST_Request;

/**
 * Customer portal. Floor: cargovelo_book. Every read and write is scoped to the actor's customer
 * by ShipmentService; an administrator testing the portal without a customer link sees nothing.
 */
final class PortalHandler
{
    use RestSupport;

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        $rest = ntdst_rest(self::REST_NAMESPACE);
        $book = ['permission' => Roles::CAP_BOOK, 'rate_limit' => 120, 'rate_window' => 60];
        $id = '(?P<id>\d+)';

        $rest->get('/portal/shipments', [$this, 'handleList'], $book);
        $rest->post('/portal/shipments', [$this, 'handleCreate'], $book);
        $rest->get("/portal/shipments/{$id}", [$this, 'handleGet'], $book);
        $rest->post("/portal/shipments/{$id}/cancel", [$this, 'handleCancel'], $book);
        $rest->post("/portal/shipments/{$id}/note", [$this, 'handleNote'], $book);
        $rest->get("/portal/shipments/{$id}/pod/(?P<kind>photo|signature)", [$this, 'handlePod'], $book);
        $rest->post('/portal/quote', [$this, 'handleQuote'], $book);
        $rest->get('/portal/counts', [$this, 'handleCounts'], $book);
        $rest->get('/portal/addresses', [$this, 'handleAddresses'], $book);
        $rest->post('/portal/addresses', [$this, 'handleAddressCreate'], $book);
        $rest->delete("/portal/addresses/{$id}", [$this, 'handleAddressDelete'], $book);
        $rest->post('/portal/import', [$this, 'handleImport'], ['permission' => Roles::CAP_BOOK, 'rate_limit' => 10, 'rate_window' => 60]);
        $rest->get('/portal/import/template', [$this, 'handleImportTemplate'], $book);
    }

    public function handleList(WP_REST_Request $request): array
    {
        return ntdst_get(ShipmentService::class)->list(ShipmentFilter::fromParams($this->params($request)), $this->portalActor());
    }

    public function handleGet(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->get($this->intArg($request, 'id'), $this->portalActor());
        return $r instanceof WP_Error ? $this->fail($r, 404) : $r;
    }

    public function handleCreate(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->create($this->params($request), $this->portalActor(), Channel::Portal);
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handleCancel(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->transition($this->intArg($request, 'id'), ShipmentStatus::Cancelled, $this->portalActor(), [
            'reason' => (string) ($request->get_param('reason') ?? ''),
        ]);
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handleNote(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->addNote($this->intArg($request, 'id'), (string) ($request->get_param('note') ?? ''), $this->portalActor(), true);
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handlePod(WP_REST_Request $request): WP_Error
    {
        $img = ntdst_get(ShipmentService::class)->podImage($this->intArg($request, 'id'), (string) $request->get_param('kind'), $this->portalActor());
        if ($img instanceof WP_Error) {
            return $this->fail($img, 404);
        }
        $this->download($img['bytes'], 'pod-' . $this->intArg($request, 'id') . '.' . explode('/', $img['mime'])[1], $img['mime'], true);
    }

    public function handleQuote(WP_REST_Request $request): array|WP_Error
    {
        $r = ntdst_get(ShipmentService::class)->quote($this->params($request), $this->portalActor());
        return $r instanceof WP_Error ? $this->fail($r) : $r;
    }

    public function handleCounts(WP_REST_Request $request): array
    {
        return ntdst_get(ShipmentService::class)->counts($this->portalActor());
    }

    public function handleAddresses(WP_REST_Request $request): array|WP_Error
    {
        $actor = $this->portalActor();
        if ($actor->customerId === null) {
            return ['items' => []];
        }
        $rows = ntdst_get(AddressRepository::class)->forCustomer($actor->customerId);
        return ['items' => array_map([AddressRepository::class, 'toArray'], $rows)];
    }

    public function handleAddressCreate(WP_REST_Request $request): array|WP_Error
    {
        $actor = $this->portalActor();
        if ($actor->customerId === null) {
            return $this->fail(new WP_Error('no_customer', 'Je account is niet aan een klant gekoppeld.'), 403);
        }
        $params = $this->params($request);
        $address = Address::fromArray($params, 'Adres');
        if ($address instanceof WP_Error) {
            return $this->fail($address);
        }
        $repo = ntdst_get(AddressRepository::class);
        $id = $repo->create($actor->customerId, (string) ($params['label'] ?? $address->name), $address);
        if ($id instanceof WP_Error) {
            return $this->fail($id, 500);
        }
        return AddressRepository::toArray($repo->find($id));
    }

    public function handleAddressDelete(WP_REST_Request $request): array|WP_Error
    {
        $actor = $this->portalActor();
        if ($actor->customerId === null) {
            return $this->fail(new WP_Error('no_customer', 'Je account is niet aan een klant gekoppeld.'), 403);
        }
        ntdst_get(AddressRepository::class)->delete($this->intArg($request, 'id'), $actor->customerId);
        return ['ok' => true];
    }

    public function handleImport(WP_REST_Request $request): array|WP_Error
    {
        $csv = (string) ($request->get_param('csv') ?? '');
        $files = $request->get_file_params();
        if ($csv === '' && isset($files['file']['tmp_name']) && is_uploaded_file($files['file']['tmp_name'])) {
            if ((int) ($files['file']['size'] ?? 0) > 2 * 1024 * 1024) {
                return $this->fail(new WP_Error('too_large', 'Bestand groter dan 2 MB.'));
            }
            $csv = (string) file_get_contents($files['file']['tmp_name']);
        }
        if ($csv === '') {
            return $this->fail(new WP_Error('no_file', 'Geen CSV ontvangen.'));
        }
        return ntdst_get(CsvImportService::class)->import($csv, $this->portalActor());
    }

    public function handleImportTemplate(WP_REST_Request $request): WP_Error
    {
        $this->download("\xEF\xBB\xBF" . CsvImportService::template(), 'cargovelo-import-sjabloon.csv', 'text/csv; charset=UTF-8');
    }

    /**
     * Staff opening the portal act as a customer for their own linked customer id (if any), never
     * with dispatcher scope: the portal must show exactly what a customer would see.
     */
    private function portalActor(): \CargoVelo\Domain\Actor
    {
        $actor = $this->actor();
        if ($actor->role === Role::Customer) {
            return $actor;
        }
        $customerId = (int) get_user_meta($actor->userId, Roles::META_CUSTOMER_ID, true);
        return new \CargoVelo\Domain\Actor($actor->userId, $actor->name, Role::Customer, $customerId > 0 ? $customerId : null);
    }
}
