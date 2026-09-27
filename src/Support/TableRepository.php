<?php
declare(strict_types=1);

namespace CargoVelo\Support;

use CargoVelo\Install\Schema;
use WP_Error;

/**
 * Base for custom-table repositories. All SQL goes through $wpdb->prepare; callers never see $wpdb.
 * Timestamps are site-local "Y-m-d H:i:s" (cargovelo_now()).
 */
abstract class TableRepository
{
    abstract protected function tableName(): string;

    protected function table(): string
    {
        return Schema::table($this->tableName());
    }

    protected function db(): object
    {
        global $wpdb;
        return $wpdb;
    }

    /** @param array<string, mixed> $data */
    protected function insertRow(array $data): int|WP_Error
    {
        $db = $this->db();
        $ok = $db->insert($this->table(), $data);
        if ($ok === false) {
            cargovelo_log('db')->error('insert failed', ['table' => $this->tableName(), 'error' => $db->last_error ?? '']);
            return new WP_Error('db_error', 'Opslaan mislukt.');
        }
        return (int) $db->insert_id;
    }

    /** @param array<string, mixed> $data */
    protected function updateRow(int $id, array $data): true|WP_Error
    {
        $db = $this->db();
        $ok = $db->update($this->table(), $data, ['id' => $id]);
        if ($ok === false) {
            cargovelo_log('db')->error('update failed', ['table' => $this->tableName(), 'id' => $id, 'error' => $db->last_error ?? '']);
            return new WP_Error('db_error', 'Opslaan mislukt.');
        }
        return true;
    }

    protected function deleteRow(int $id): bool
    {
        return (bool) $this->db()->delete($this->table(), ['id' => $id]);
    }

    protected function rowById(int $id): ?object
    {
        $db = $this->db();
        $row = $db->get_row($db->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id));
        return $row ?: null;
    }

    /** @return list<string> Placeholders for an IN() list. */
    protected function placeholders(array $values, string $type = '%d'): string
    {
        return implode(',', array_fill(0, max(1, count($values)), $type));
    }

    protected static function decodeJson(mixed $json, mixed $default = []): mixed
    {
        if (!is_string($json) || $json === '') {
            return $default;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : $default;
    }

    protected static function encodeJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
