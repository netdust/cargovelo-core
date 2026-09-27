<?php
declare(strict_types=1);

namespace CargoVelo\Admin;

use CargoVelo\Frontend\Assets;
use CargoVelo\Modules\Access\Roles;

/** wp-admin > Cargo Velo: the ops desk SPA, full width. */
final class AdminPage
{
    public const MENU_SLUG = 'cargovelo';

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function registerMenu(): void
    {
        add_menu_page('Cargo Velo', 'Cargo Velo', Roles::CAP_DISPATCH, self::MENU_SLUG, [$this, 'render'], 'dashicons-car', 3);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }
        Assets::enqueue(['view' => 'ops']);
        wp_add_inline_style('common', '#wpcontent{padding-left:0}#wpbody-content{padding-bottom:0}.cargovelo-mount--admin{min-height:calc(100vh - 32px)}#wpfooter{display:none}.update-nag,.notice{display:none}');
    }

    public function render(): void
    {
        echo '<div id="cargovelo-app" class="cargovelo-mount cargovelo-mount--admin" data-cargovelo-view="ops"></div>';
    }
}
