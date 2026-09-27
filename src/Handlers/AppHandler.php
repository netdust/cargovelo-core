<?php
declare(strict_types=1);

namespace CargoVelo\Handlers;

use CargoVelo\Modules\Settings\SettingsService;
use CargoVelo\Support\RestSupport;
use WP_REST_Request;

/** Bootstrap context for the SPA: who am I, which hubs and services exist. */
final class AppHandler
{
    use RestSupport;

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        ntdst_rest(self::REST_NAMESPACE)->get('/me', [$this, 'handleMe'], ['permission' => 'read', 'rate_limit' => 60]);
    }

    public function handleMe(WP_REST_Request $request): array
    {
        return self::context();
    }

    /** @return array<string, mixed> shaped like app/src/domain/types.ts::AppContext */
    public static function context(): array
    {
        $actor = ntdst_get(\CargoVelo\Modules\Access\AccessContext::class)->current();
        $settings = ntdst_get(SettingsService::class);
        $user = $actor->userId > 0 ? get_userdata($actor->userId) : null;
        return [
            'user' => $actor->toArray() + ['email' => $user ? (string) $user->user_email : ''],
            'rest' => null,
            'hubs' => array_map(static fn(array $h): array => [
                'code' => $h['code'], 'name' => $h['name'], 'city' => $h['city'],
                'postcodes' => array_map(static fn(array $r): string => $r[0] . '-' . $r[1], $h['postcodes']),
                'cutoff_sameday' => $h['cutoff_sameday'],
            ], $settings->hubs()),
            'services' => $settings->services(),
            'urls' => [
                'logout' => wp_logout_url(home_url('/')),
                'site' => home_url('/'),
                'admin' => admin_url('admin.php?page=cargovelo'),
            ],
        ];
    }
}
