<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Pricing;

use CargoVelo\Domain\Parcels;
use CargoVelo\Domain\ServiceCode;

/**
 * The only place that computes a price. The result is frozen onto the shipment when it is
 * created; later price-list edits never change booked shipments.
 */
final class PricingService
{
    public function __construct(private readonly PriceListRepository $lists)
    {
    }

    /**
     * @return array{service:string, price_cents:int, breakdown: list<array{label:string, cents:int}>, currency:string}
     */
    public function quote(ServiceCode $service, Parcels $parcels, ?int $priceListId = null): array
    {
        $list = ($priceListId ? $this->lists->find($priceListId) : null) ?? $this->lists->defaultList();
        $rule = null;
        foreach ($list['rules'] as $r) {
            if ($r['service'] === $service->value) {
                $rule = $r;
                break;
            }
        }
        $rule ??= ['base_cents' => 0, 'extra_parcel_cents' => 0, 'surcharges' => []];

        return self::compute($service, $parcels, $rule);
    }

    /**
     * Pure calculation, unit-testable without a database.
     * @param array{base_cents:int, extra_parcel_cents:int, surcharges: array<string,int>} $rule
     * @return array{service:string, price_cents:int, breakdown: list<array{label:string, cents:int}>, currency:string}
     */
    public static function compute(ServiceCode $service, Parcels $parcels, array $rule): array
    {
        $breakdown = [['label' => $service->label(), 'cents' => (int) $rule['base_cents']]];
        $total = (int) $rule['base_cents'];

        $extra = max(0, $parcels->totalCount() - 1);
        if ($extra > 0) {
            $cents = $extra * (int) $rule['extra_parcel_cents'];
            $breakdown[] = ['label' => sprintf('%d extra %s', $extra, $extra === 1 ? 'pakket' : 'pakketten'), 'cents' => $cents];
            $total += $cents;
        }

        $surcharges = $rule['surcharges'] ?? [];
        $weight = $parcels->heaviest()->value;
        if (($surcharges[$weight] ?? 0) > 0) {
            $breakdown[] = ['label' => 'Gewichtsklasse ' . strtoupper($weight), 'cents' => (int) $surcharges[$weight]];
            $total += (int) $surcharges[$weight];
        }
        if ($parcels->hasFragile() && ($surcharges['fragile'] ?? 0) > 0) {
            $breakdown[] = ['label' => 'Breekbaar', 'cents' => (int) $surcharges['fragile']];
            $total += (int) $surcharges['fragile'];
        }
        if ($parcels->hasCooled() && ($surcharges['cooled'] ?? 0) > 0) {
            $breakdown[] = ['label' => 'Gekoeld transport', 'cents' => (int) $surcharges['cooled']];
            $total += (int) $surcharges['cooled'];
        }

        return ['service' => $service->value, 'price_cents' => $total, 'breakdown' => $breakdown, 'currency' => 'EUR'];
    }
}
