<?php
declare(strict_types=1);

namespace CargoVelo\Tests\Unit\Handlers;

use CargoVelo\Frontend\Shortcodes;
use CargoVelo\Modules\Access\AccessContext;
use CargoVelo\Modules\Courier\CourierRepository;
use CargoVelo\Modules\Settings\SettingsService;
use CargoVelo\Tests\TestCase;

final class AssetsAndShortcodesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $couriers = $this->createMock(CourierRepository::class);
        $couriers->method('findByUser')->willReturn(null);
        $this->set(AccessContext::class, new AccessContext($couriers));
        $this->set(SettingsService::class, new SettingsService());
    }

    public function testShortcodesRegisterAndPublicMountsCarryTheView(): void
    {
        $sc = new Shortcodes();
        self::assertSame(['cargovelo_booking', 'cargovelo_tracking', 'cargovelo_app'], array_keys($GLOBALS['cv_test']['shortcodes']));
        self::assertStringContainsString('data-cargovelo-view="booking"', $sc->renderBooking());
        $_GET['t'] = 'abc123<script>';
        $tracking = $sc->renderTracking();
        self::assertStringContainsString('data-token="abc123c"', $tracking); // non-hex stripped, nothing escapes
        self::assertStringNotContainsString('<script', $tracking);
        unset($_GET['t']);
    }

    public function testBuiltAssetsAreEnqueuedWithApiFetchAndConfig(): void
    {
        (new Shortcodes())->renderBooking();
        $scripts = $GLOBALS['cv_test']['scripts'];
        self::assertArrayHasKey('wp-api-fetch', $scripts);
        self::assertArrayHasKey('cargovelo-app', $scripts);
        self::assertContains('wp-api-fetch', $scripts['cargovelo-app']['deps']);
        self::assertStringContainsString('assets/app/assets/app-', $scripts['cargovelo-app']['src']);
        $config = json_decode(substr($GLOBALS['cv_test']['inline']['cargovelo-app'], strlen('window.cargoveloConfig = '), -1), true);
        self::assertSame('wp', $config['mode']);
        self::assertSame('https://cargovelo.test/wp-json/cargovelo/v1/', $config['restRoot']);
        self::assertSame('guest', $config['context']['user']['role']);
        self::assertCount(5, $config['context']['hubs']);
    }

    public function testAppShortcodeAsksGuestsToLogIn(): void
    {
        $html = (new Shortcodes())->renderApp();
        self::assertStringContainsString('Aanmelden', $html);
        self::assertStringNotContainsString('data-cargovelo-view', $html);
    }

    public function testAppShortcodeMountsCourierViewForCourierRole(): void
    {
        $GLOBALS['cv_test']['user_id'] = 30;
        $GLOBALS['cv_test']['caps'][30] = ['cargovelo_courier'];
        $user = new \WP_User();
        $user->ID = 30;
        $user->display_name = 'Kris';
        $GLOBALS['cv_test']['users'][30] = $user;
        self::assertStringContainsString('data-cargovelo-view="courier"', (new Shortcodes())->renderApp());
    }
}
