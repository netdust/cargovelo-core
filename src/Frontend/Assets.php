<?php
declare(strict_types=1);

namespace CargoVelo\Frontend;

use CargoVelo\Handlers\AppHandler;
use CargoVelo\Modules\Settings\SettingsService;

/**
 * Enqueues the built SPA (assets/app, Vite manifest) with wp-api-fetch as dependency and injects
 * window.cargoveloConfig. In WP_DEBUG without a build, it loads from the Vite dev server instead.
 */
final class Assets
{
    public const HANDLE = 'cargovelo-app';
    private const ENTRY = 'src/main.tsx';
    private const DEV_SERVER = 'http://localhost:5174';

    private static bool $filtered = false;

    /** @param array<string, mixed> $extraConfig */
    public static function enqueue(array $extraConfig = []): void
    {
        $manifestPath = CARGOVELO_DIR . '/assets/app/.vite/manifest.json';
        $manifest = file_exists($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        $entry = is_array($manifest) ? ($manifest[self::ENTRY] ?? null) : null;

        wp_enqueue_script('wp-api-fetch');

        if (is_array($entry)) {
            $base = plugins_url('assets/app/', CARGOVELO_FILE);
            foreach ($entry['css'] ?? [] as $i => $css) {
                wp_enqueue_style(self::HANDLE . ($i ? "-{$i}" : ''), $base . $css, [], CARGOVELO_VERSION);
            }
            wp_enqueue_script(self::HANDLE, $base . $entry['file'], ['wp-api-fetch'], CARGOVELO_VERSION, true);
        } elseif (defined('WP_DEBUG') && WP_DEBUG) {
            wp_enqueue_script(self::HANDLE, self::DEV_SERVER . '/' . self::ENTRY, ['wp-api-fetch'], null, true);
            wp_enqueue_script(self::HANDLE . '-vite-client', self::DEV_SERVER . '/@vite/client', [], null, false);
        } else {
            wp_register_script(self::HANDLE, '', ['wp-api-fetch'], CARGOVELO_VERSION, true);
            wp_enqueue_script(self::HANDLE);
            cargovelo_log('assets')->error('SPA build missing: run npm run build in cargovelo/app');
        }

        $config = self::config() + $extraConfig;
        wp_add_inline_script(self::HANDLE, 'window.cargoveloConfig = ' . wp_json_encode($config) . ';', 'before');

        if (!self::$filtered) {
            self::$filtered = true;
            add_filter('script_loader_tag', static function (string $tag, string $handle): string {
                if (str_starts_with($handle, self::HANDLE)) {
                    $tag = str_replace('<script ', '<script type="module" ', $tag);
                }
                return $tag;
            }, 10, 2);
        }
    }

    /** @return array<string, mixed> */
    public static function config(): array
    {
        $context = function_exists('ntdst_get') ? AppHandler::context() : ['user' => null, 'hubs' => [], 'services' => []];
        return [
            'mode' => 'wp',
            'restNamespace' => 'cargovelo/v1',
            'context' => $context,
            'loginUrl' => wp_login_url(),
            'trackingPage' => (string) (function_exists('ntdst_get') ? (ntdst_get(SettingsService::class)->all()['tracking_page'] ?? '') : ''),
        ];
    }
}
