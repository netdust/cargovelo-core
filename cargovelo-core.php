<?php
/**
 * Plugin Name: Cargo Velo Core
 * Description: Shipments system for Cargo Velo: website booking, customer portal, dispatch desk, courier app, proof of delivery, priced export.
 * Version: 0.1.0
 * Author: netdust
 * Requires PHP: 8.2
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Bedrock's autoloader loads this file before ntdst-coreloader.php ('c' < 'n'). Nothing below may
// call an ntdst_* function at load time; everything waits for the NTDST lifecycle hooks.

define('CARGOVELO_DIR', __DIR__);
define('CARGOVELO_FILE', __FILE__);
define('CARGOVELO_VERSION', '0.1.0');

if (!class_exists(\CargoVelo\Domain\ShipmentStatus::class)) {
    // Standalone install (no root composer autoload covering this package): use the package's own vendor.
    $vendorAutoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($vendorAutoload)) {
        require_once $vendorAutoload;
    } else {
        require_once __DIR__ . '/autoload.php';
    }
}

$cargoveloConfig = require __DIR__ . '/plugin-config.php';

$cargoveloRegisterTemplates = static function (): void {
    if (class_exists('NTDST_Template_Loader')) {
        \NTDST_Template_Loader::addPath(CARGOVELO_DIR . '/templates');
    }
};
if (class_exists('NTDST_Template_Loader')) {
    $cargoveloRegisterTemplates();
} else {
    add_action('muplugins_loaded', $cargoveloRegisterTemplates, 0);
}

// Schema + roles: idempotent, version-gated, early on init.
add_action('init', static function (): void {
    \CargoVelo\Install\Schema::ensure();
    \CargoVelo\Modules\Access\Roles::ensure();
}, 1);

// DI bindings (interfaces + repositories) before any service is resolved.
add_action('ntdst/core_ready', static function () use ($cargoveloConfig): void {
    foreach ($cargoveloConfig['bindings'] as $abstract => $concrete) {
        ntdst_set($abstract, $concrete);
    }
});

// Boot services and handlers once, in array order (INV-19: THE instance).
$cargoveloBoot = static function () use ($cargoveloConfig): void {
    static $booted = false;
    if ($booted || !function_exists('ntdst_get')) {
        return;
    }
    $booted = true;
    foreach ($cargoveloConfig['services'] as $class) {
        if (class_exists($class)) {
            ntdst_get($class);
        }
    }
    foreach ($cargoveloConfig['handlers'] as $class) {
        if (class_exists($class)) {
            ntdst_set($class);
            ntdst_get($class);
        }
    }
};
add_action('ntdst/features_ready', $cargoveloBoot);

// The NTDST lifecycle is driven by an NTDST theme. On a site whose theme does not boot NTDST_Bootstrap
// the hooks never fire; boot from init instead so the REST API and admin still work.
add_action('init', static function () use ($cargoveloBoot): void {
    if (!did_action('ntdst/features_ready')) {
        $cargoveloBoot();
    }
}, 5);

if (!function_exists('ntdst_get')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>Cargo Velo Core requires NTDST Core (netdust/ntdst-core).</p></div>';
    });
}
