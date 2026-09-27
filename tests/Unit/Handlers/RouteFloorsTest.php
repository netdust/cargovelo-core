<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Handlers;

use CargoVelo\Handlers\AppHandler;
use CargoVelo\Handlers\CourierHandler;
use CargoVelo\Handlers\OpsHandler;
use CargoVelo\Handlers\PortalHandler;
use CargoVelo\Handlers\PublicHandler;
use CargoVelo\Modules\Access\Roles;
use CargoVelo\Tests\TestCase;

/**
 * Authorization is decided on the route. This pins the floor of every route so a refactor cannot
 * silently open the ops desk to customers or the portal to guests.
 */
final class RouteFloorsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function routes(): array
    {
        new PublicHandler();
        new AppHandler();
        new OpsHandler();
        new PortalHandler();
        new CourierHandler();
        return $GLOBALS['cv_ntdst']['routes'];
    }

    public function testEveryRouteLivesInTheNamespaceAndHasARateLimit(): void
    {
        foreach ($this->routes() as $r) {
            self::assertSame('cargovelo/v1', $r['ns'], $r['route']);
            self::assertArrayHasKey('rate_limit', $r['options'], $r['route']);
        }
    }

    public function testAnonymousSurfaceIsExactlyFourRoutes(): void
    {
        $anon = array_values(array_filter($this->routes(), static fn(array $r): bool => $r['public'] || (($r['options']['permission'] ?? null) instanceof \Closure)));
        self::assertSame(
            ['POST /public/quote', 'POST /public/bookings', 'GET /public/tracking/(?P<token>[a-f0-9]{24})', 'POST /public/tracking/(?P<token>[a-f0-9]{24})/instructions'],
            array_map(static fn(array $r): string => $r['verb'] . ' ' . $r['route'], $anon)
        );
        foreach ($anon as $r) {
            self::assertLessThanOrEqual(60, $r['options']['rate_limit'], $r['route']);
        }
    }

    public function testPrefixesMapToCapabilities(): void
    {
        $expected = ['/ops/' => [Roles::CAP_DISPATCH, Roles::CAP_MANAGE], '/portal/' => [Roles::CAP_BOOK], '/courier/' => [Roles::CAP_COURIER], '/me' => ['read']];
        foreach ($this->routes() as $r) {
            if (str_starts_with($r['route'], '/public/')) {
                continue;
            }
            $matched = false;
            foreach ($expected as $prefix => $caps) {
                if (str_starts_with($r['route'], $prefix)) {
                    $matched = true;
                    self::assertContains($r['options']['permission'], $caps, $r['verb'] . ' ' . $r['route']);
                }
            }
            self::assertTrue($matched, 'unexpected route prefix: ' . $r['route']);
        }
    }

    public function testMasterDataWritesRequireManage(): void
    {
        foreach ($this->routes() as $r) {
            $isWrite = in_array($r['verb'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
            $isMasterData = (bool) preg_match('#^/ops/(customers|couriers|price-lists|settings)#', $r['route']) && !str_ends_with($r['route'], '/reorder');
            if ($isWrite && $isMasterData) {
                self::assertSame(Roles::CAP_MANAGE, $r['options']['permission'], $r['verb'] . ' ' . $r['route']);
            }
        }
    }
}
