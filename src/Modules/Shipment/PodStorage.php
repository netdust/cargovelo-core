<?php
declare(strict_types=1);

namespace CargoVelo\Modules\Shipment;

use WP_Error;

/**
 * Proof-of-delivery images (photo, signature) as files under uploads/cargovelo/pod/{shipment}/.
 * Never served by URL: the ops/portal routes stream them after the scoping check.
 */
final class PodStorage
{
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function store(int $shipmentId, string $kind, string $dataUrl): string|WP_Error
    {
        if (!preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#s', $dataUrl, $m)) {
            return new WP_Error('invalid_image', 'Alleen JPEG, PNG of WebP afbeeldingen.');
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
            return new WP_Error('invalid_image', 'Afbeelding ontbreekt of is groter dan 5 MB.');
        }
        $ext = self::MIME[$m[1]];
        $dir = $this->baseDir() . '/' . $shipmentId;
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('storage_error', 'Kan bewijs niet opslaan.');
        }
        $name = sprintf('%s-%s.%s', preg_replace('/[^a-z]/', '', $kind) ?: 'pod', date('YmdHis'), $ext);
        if (file_put_contents($dir . '/' . $name, $bytes) === false) {
            return new WP_Error('storage_error', 'Kan bewijs niet opslaan.');
        }
        return $shipmentId . '/' . $name;
    }

    /** @return array{bytes:string, mime:string}|null */
    public function read(string $relative): ?array
    {
        if (!preg_match('#^\d+/[a-z]+-\d{14}\.(jpg|png|webp)$#', $relative, $m)) {
            return null;
        }
        $path = $this->baseDir() . '/' . $relative;
        if (!is_file($path)) {
            return null;
        }
        $mime = array_search($m[1], self::MIME, true) ?: 'application/octet-stream';
        return ['bytes' => (string) file_get_contents($path), 'mime' => $mime];
    }

    private function baseDir(): string
    {
        $upload = wp_upload_dir();
        $base = rtrim((string) ($upload['basedir'] ?? sys_get_temp_dir()), '/') . '/cargovelo/pod';
        if (!is_dir($base)) {
            wp_mkdir_p($base);
            @file_put_contents(dirname($base) . '/.htaccess', "Deny from all\n");
            @file_put_contents(dirname($base) . '/index.php', "<?php // silence\n");
        }
        return $base;
    }
}
