<?php
declare(strict_types=1);

namespace CargoVelo\Support;

use CargoVelo\Domain\Actor;
use CargoVelo\Modules\Access\AccessContext;
use WP_Error;
use WP_REST_Request;

/**
 * Shared handler plumbing. Handlers have no constructor DI (thin handler pattern): they resolve
 * services through ntdst_get() inside methods.
 */
trait RestSupport
{
    public const REST_NAMESPACE = 'cargovelo/v1';

    protected function actor(): Actor
    {
        return ntdst_get(AccessContext::class)->current();
    }

    /** Stamp a status on a WP_Error (400 unless the error already carries one). */
    protected function fail(WP_Error $error, int $default = 400): WP_Error
    {
        return cargovelo_rest_error($error, $default);
    }

    /** @return array<string, mixed> */
    protected function params(WP_REST_Request $request): array
    {
        $params = $request->get_params();
        return is_array($params) ? $params : [];
    }

    protected function intArg(WP_REST_Request $request, string $key): int
    {
        return (int) ($request->get_param($key) ?? 0);
    }

    /** Stream a file response. Uses the NTDST response object when present, plain headers otherwise. */
    protected function download(string $bytes, string $filename, string $mime, bool $inline = false): never
    {
        if (function_exists('ntdst_response')) {
            $inline ? ntdst_response()->inline($bytes, $filename, $mime) : ntdst_response()->download($bytes, $filename, $mime);
        }
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header(sprintf('Content-Disposition: %s; filename="%s"', $inline ? 'inline' : 'attachment', $filename));
        echo $bytes;
        exit;
    }
}
