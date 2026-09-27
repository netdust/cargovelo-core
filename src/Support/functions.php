<?php
/**
 * Global helpers for cargovelo-core. Loaded by composer "files" or the fallback autoloader.
 */
declare(strict_types=1);

if (!function_exists('cargovelo_rest_error')) {
    /**
     * The one place that stamps an HTTP status onto a WP_Error. A status-less WP_Error is a 500.
     */
    function cargovelo_rest_error(\WP_Error $error, int $status = 400): \WP_Error
    {
        $data = $error->get_error_data();
        $data = is_array($data) ? $data : [];
        if (!isset($data['status'])) {
            $error->add_data($data + ['status' => $status]);
        }
        return $error;
    }
}

if (!function_exists('cargovelo_log')) {
    /**
     * Logger facade: ntdst_log() when NTDST Core is present, error_log otherwise.
     * Returns an object with info/warning/error/debug(string, array).
     */
    function cargovelo_log(string $channel = 'cargovelo'): object
    {
        if (function_exists('ntdst_log')) {
            return ntdst_log($channel);
        }
        return new class($channel) {
            public function __construct(private readonly string $channel)
            {
            }

            public function __call(string $level, array $args): void
            {
                $message = (string) ($args[0] ?? '');
                $context = is_array($args[1] ?? null) ? $args[1] : [];
                error_log(sprintf('[%s.%s] %s %s', $this->channel, $level, $message, $context ? json_encode($context) : ''));
            }
        };
    }
}

if (!function_exists('cargovelo_now')) {
    /** Current local time as "Y-m-d H:i:s" in the site timezone. */
    function cargovelo_now(): string
    {
        return function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
    }
}
