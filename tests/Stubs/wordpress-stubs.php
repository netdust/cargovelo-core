<?php
/**
 * Minimal WordPress surface for unit tests. State lives in $GLOBALS['cv_test'] and is reset by TestCase.
 */
declare(strict_types=1);

$GLOBALS['cv_test'] = [
    'options' => [], 'actions' => [], 'filters' => [], 'hooks' => [], 'mails' => [], 'user_id' => 0, 'users' => [], 'caps' => [], 'user_meta' => [],
    'shortcodes' => [], 'scripts' => [], 'inline' => [],
];

function cv_test_reset(): void
{
    $GLOBALS['cv_test'] = [
        'options' => [], 'actions' => [], 'filters' => [], 'hooks' => [], 'mails' => [], 'user_id' => 0, 'users' => [], 'caps' => [], 'user_meta' => [],
        'shortcodes' => [], 'scripts' => [], 'inline' => [],
    ];
    $GLOBALS['cv_ntdst'] = ['container' => [], 'routes' => []];
}

class WP_Error
{
    /** @var array<string, list<string>> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->data[$code] = $data;
            }
        }
    }

    public function get_error_code(): string
    {
        return (string) (array_key_first($this->errors) ?? '');
    }

    public function get_error_message(string $code = ''): string
    {
        $code = $code ?: $this->get_error_code();
        return $this->errors[$code][0] ?? '';
    }

    public function get_error_data(string $code = ''): mixed
    {
        $code = $code ?: $this->get_error_code();
        return $this->data[$code] ?? null;
    }

    public function add_data(mixed $data, string $code = ''): void
    {
        $code = $code ?: $this->get_error_code();
        $this->data[$code] = $data;
    }
}

class WP_REST_Request
{
    /** @param array<string, mixed> $params */
    public function __construct(private array $params = [], private array $files = [])
    {
    }

    public function get_params(): array
    {
        return $this->params;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function get_file_params(): array
    {
        return $this->files;
    }
}

class WP_User
{
    public int $ID = 0;
    public string $display_name = '';
    public string $user_email = '';
    /** @var list<string> */
    public array $roles = [];
    /** @var list<string> */
    public array $added = [];

    public function add_role(string $role): void
    {
        $this->roles[] = $role;
        $this->added[] = $role;
    }
}

function is_wp_error(mixed $v): bool
{
    return $v instanceof WP_Error;
}

function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): void
{
    $GLOBALS['cv_test']['hooks'][$hook][] = $cb;
}

function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): void
{
    $GLOBALS['cv_test']['hooks'][$hook][] = $cb;
}

function do_action(string $hook, mixed ...$args): void
{
    $GLOBALS['cv_test']['actions'][] = ['hook' => $hook, 'args' => $args];
    foreach ($GLOBALS['cv_test']['hooks'][$hook] ?? [] as $cb) {
        $cb(...$args);
    }
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $GLOBALS['cv_test']['filters'][] = $hook;
    foreach ($GLOBALS['cv_test']['hooks'][$hook] ?? [] as $cb) {
        $value = $cb($value, ...$args);
    }
    return $value;
}

function did_action(string $hook): int
{
    return count(array_filter($GLOBALS['cv_test']['actions'], static fn(array $a): bool => $a['hook'] === $hook));
}

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['cv_test']['options'][$key] ?? $default;
}

function update_option(string $key, mixed $value): bool
{
    $GLOBALS['cv_test']['options'][$key] = $value;
    return true;
}

function current_time(string $type): string
{
    return $GLOBALS['cv_test']['now'] ?? date('Y-m-d H:i:s');
}

function wp_date(string $format): string
{
    return (new DateTimeImmutable(current_time('mysql')))->format($format);
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('Europe/Brussels');
}

function esc_html(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function esc_attr(mixed $s): string
{
    return esc_html($s);
}

function esc_url(string $s): string
{
    return $s;
}

function home_url(string $path = ''): string
{
    return 'https://cargovelo.test' . $path;
}

function admin_url(string $path = ''): string
{
    return 'https://cargovelo.test/wp/wp-admin/' . $path;
}

function add_query_arg(string $key, string $value, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . rawurlencode($value);
}

function wp_logout_url(string $redirect = ''): string
{
    return 'https://cargovelo.test/logout';
}

function wp_login_url(string $redirect = ''): string
{
    return 'https://cargovelo.test/login';
}

function plugins_url(string $path, string $file): string
{
    return 'https://cargovelo.test/app/mu-plugins/cargovelo-core/' . ltrim($path, '/');
}

function get_current_user_id(): int
{
    return (int) $GLOBALS['cv_test']['user_id'];
}

function is_user_logged_in(): bool
{
    return get_current_user_id() > 0;
}

function get_userdata(int $id): WP_User|false
{
    return $GLOBALS['cv_test']['users'][$id] ?? false;
}

function user_can(int $id, string $cap): bool
{
    return in_array($cap, $GLOBALS['cv_test']['caps'][$id] ?? [], true);
}

function get_user_meta(int $id, string $key, bool $single = false): mixed
{
    return $GLOBALS['cv_test']['user_meta'][$id][$key] ?? '';
}

function update_user_meta(int $id, string $key, mixed $value): bool
{
    $GLOBALS['cv_test']['user_meta'][$id][$key] = $value;
    return true;
}

function wp_mail(string $to, string $subject, string $message, array $headers = []): bool
{
    $GLOBALS['cv_test']['mails'][] = ['to' => $to, 'subject' => $subject, 'html' => $message];
    return true;
}

function wp_upload_dir(): array
{
    $dir = sys_get_temp_dir() . '/cargovelo-tests/uploads';
    @mkdir($dir, 0777, true);
    return ['basedir' => $dir, 'baseurl' => 'https://cargovelo.test/uploads'];
}

function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || mkdir($dir, 0777, true);
}

function wp_json_encode(mixed $v): string
{
    return (string) json_encode($v);
}

function add_shortcode(string $tag, callable $cb): void
{
    $GLOBALS['cv_test']['shortcodes'][$tag] = $cb;
}

function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $footer = false): void
{
    $GLOBALS['cv_test']['scripts'][$handle] = ['src' => $src, 'deps' => $deps];
}

function wp_register_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $footer = false): void
{
    $GLOBALS['cv_test']['scripts'][$handle] = ['src' => $src, 'deps' => $deps];
}

function wp_enqueue_style(string $handle, string $src = '', array $deps = [], mixed $ver = false): void
{
    $GLOBALS['cv_test']['scripts'][$handle] = ['src' => $src, 'deps' => $deps, 'style' => true];
}

function wp_add_inline_script(string $handle, string $js, string $position = 'after'): void
{
    $GLOBALS['cv_test']['inline'][$handle] = $js;
}

function wp_add_inline_style(string $handle, string $css): void
{
}

function add_menu_page(string ...$args): void
{
    $GLOBALS['cv_test']['menu'][] = $args;
}

function nocache_headers(): void
{
}

function add_role(string $role, string $name, array $caps): void
{
    $GLOBALS['cv_test']['roles'][$role] = $caps;
}

function remove_role(string $role): void
{
    unset($GLOBALS['cv_test']['roles'][$role]);
}

function get_role(string $role): ?object
{
    if ($role !== 'administrator') {
        return null;
    }
    return new class {
        public function add_cap(string $cap): void
        {
            $GLOBALS['cv_test']['roles']['administrator'][$cap] = true;
        }
    };
}

