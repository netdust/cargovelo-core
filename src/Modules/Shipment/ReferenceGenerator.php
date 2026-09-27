<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Shipment;

/** Human-readable reference (CV-260927-0412) and an unguessable tracking token. */
final class ReferenceGenerator
{
    public function reference(?\DateTimeInterface $at = null): string
    {
        $at ??= new \DateTimeImmutable('now', self::tz());
        return sprintf('CV-%s-%04d', $at->format('ymd'), random_int(0, 9999));
    }

    public function token(): string
    {
        return bin2hex(random_bytes(12));
    }

    private static function tz(): \DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }
        return new \DateTimeZone('Europe/Brussels');
    }
}
