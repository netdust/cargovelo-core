<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Shipment;

use CargoVelo\Domain\ServiceCode;
use CargoVelo\Domain\ShipmentStatus;

/** Sanitised list filter. Built from request params by the handler, scoped by the service. */
final class ShipmentFilter
{
    /** @var list<string> */
    public array $status = [];
    /** @var list<string> */
    public array $service = [];
    public ?string $hub = null;
    public ?int $customerId = null;
    public ?int $courierId = null;
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public string $search = '';
    public ?bool $exception = null;
    public bool $openOnly = false;
    public int $page = 1;
    public int $perPage = 25;
    public string $order = 'desc';

    /** @param array<string, mixed> $params */
    public static function fromParams(array $params): self
    {
        $f = new self();
        $f->status = self::enumList($params['status'] ?? [], ShipmentStatus::values());
        $f->service = self::enumList($params['service'] ?? [], ServiceCode::values());
        $f->hub = isset($params['hub']) && $params['hub'] !== '' ? mb_substr((string) $params['hub'], 0, 20) : null;
        $f->customerId = isset($params['customer_id']) && (int) $params['customer_id'] > 0 ? (int) $params['customer_id'] : null;
        $f->courierId = isset($params['courier_id']) && (int) $params['courier_id'] > 0 ? (int) $params['courier_id'] : null;
        $f->dateFrom = self::date($params['date_from'] ?? null);
        $f->dateTo = self::date($params['date_to'] ?? null);
        $f->search = mb_substr(trim((string) ($params['search'] ?? '')), 0, 80);
        if (isset($params['exception']) && $params['exception'] !== '') {
            $f->exception = in_array((string) $params['exception'], ['1', 'true'], true);
        }
        $f->openOnly = in_array((string) ($params['open'] ?? ''), ['1', 'true'], true);
        $f->page = max(1, (int) ($params['page'] ?? 1));
        $f->perPage = min(200, max(1, (int) ($params['per_page'] ?? 25)));
        $f->order = strtolower((string) ($params['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        return $f;
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    private static function enumList(mixed $raw, array $allowed): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_intersect(array_map('strval', $raw), $allowed));
    }

    private static function date(mixed $raw): ?string
    {
        $s = trim((string) $raw);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
    }
}
