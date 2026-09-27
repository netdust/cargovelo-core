<?php
declare(strict_types=1);

namespace CargoVelo\Frontend;

use CargoVelo\Domain\Role;
use CargoVelo\Modules\Access\AccessContext;

/**
 * Mount points on the public site. Same bundle, three views:
 *  [cargovelo_booking]   public booking form (guests and logged-in customers)
 *  [cargovelo_tracking]  public tracking page, reads ?t=<token>
 *  [cargovelo_app]       customer portal or courier app for logged-in users, login prompt otherwise
 */
final class Shortcodes
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_shortcode('cargovelo_booking', [$this, 'renderBooking']);
        add_shortcode('cargovelo_tracking', [$this, 'renderTracking']);
        add_shortcode('cargovelo_app', [$this, 'renderApp']);
    }

    public function renderBooking(): string
    {
        Assets::enqueue();
        return '<div id="cargovelo-booking" class="cargovelo-mount" data-cargovelo-view="booking"></div>';
    }

    public function renderTracking(): string
    {
        Assets::enqueue();
        $token = isset($_GET['t']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['t']) : '';
        return sprintf('<div id="cargovelo-tracking" class="cargovelo-mount" data-cargovelo-view="tracking" data-token="%s"></div>', esc_attr((string) $token));
    }

    public function renderApp(): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<div class="cargovelo-login"><p>Meld je aan om je zendingen te beheren.</p><p><a class="button" href="%s">Aanmelden</a></p></div>',
                esc_url(wp_login_url((string) (isset($_SERVER['REQUEST_URI']) ? home_url((string) $_SERVER['REQUEST_URI']) : home_url('/'))))
            );
        }
        $actor = ntdst_get(AccessContext::class)->current();
        $view = match ($actor->role) {
            Role::Courier => 'courier',
            Role::Customer => 'portal',
            Role::Admin, Role::Dispatcher => 'portal',
            Role::Guest => 'none',
        };
        if ($view === 'none') {
            return '<div class="cargovelo-login"><p>Je account heeft nog geen toegang tot Cargo Velo zendingen. Contacteer ons.</p></div>';
        }
        Assets::enqueue();
        return sprintf('<div id="cargovelo-app" class="cargovelo-mount cargovelo-mount--full" data-cargovelo-view="%s"></div>', $view);
    }
}
