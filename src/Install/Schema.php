<?php
declare(strict_types=1);

namespace CargoVelo\Install;

/**
 * Custom tables. Version-gated and idempotent: ensure() runs on every init but only touches the
 * database when the stored schema version is behind. dbDelta diffs columns, so never write
 * CREATE TABLE IF NOT EXISTS (its regex then captures "IF" and stops diffing).
 */
final class Schema
{
    public const VERSION = 1;
    public const OPTION = 'cargovelo_schema_version';

    public static function ensure(): void
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return;
        }
        self::install();
        update_option(self::OPTION, self::VERSION);
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'cv_' . $name;
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        foreach (self::statements($charset) as $sql) {
            dbDelta($sql);
        }
    }

    /** @return list<string> */
    public static function statements(string $charset = ''): array
    {
        $t = static fn(string $n): string => self::table($n);

        return [
            "CREATE TABLE {$t('customers')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(160) NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'occasional',
                email VARCHAR(160) NOT NULL DEFAULT '',
                phone VARCHAR(40) NOT NULL DEFAULT '',
                hub VARCHAR(20) NOT NULL DEFAULT '',
                price_list_id BIGINT UNSIGNED NULL,
                vat VARCHAR(30) NOT NULL DEFAULT '',
                billing_address TEXT NULL,
                notes TEXT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_email (email),
                KEY idx_type (type)
            ) {$charset};",

            "CREATE TABLE {$t('addresses')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL,
                label VARCHAR(80) NOT NULL DEFAULT '',
                name VARCHAR(120) NOT NULL DEFAULT '',
                company VARCHAR(120) NOT NULL DEFAULT '',
                street VARCHAR(160) NOT NULL DEFAULT '',
                number VARCHAR(20) NOT NULL DEFAULT '',
                box VARCHAR(20) NOT NULL DEFAULT '',
                postcode VARCHAR(10) NOT NULL DEFAULT '',
                city VARCHAR(80) NOT NULL DEFAULT '',
                phone VARCHAR(40) NOT NULL DEFAULT '',
                email VARCHAR(160) NOT NULL DEFAULT '',
                instructions VARCHAR(500) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_customer (customer_id)
            ) {$charset};",

            "CREATE TABLE {$t('couriers')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NULL,
                name VARCHAR(120) NOT NULL,
                hub VARCHAR(20) NOT NULL DEFAULT '',
                phone VARCHAR(40) NOT NULL DEFAULT '',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_user (user_id),
                KEY idx_hub (hub)
            ) {$charset};",

            "CREATE TABLE {$t('price_lists')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(120) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY  (id)
            ) {$charset};",

            "CREATE TABLE {$t('price_rules')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                price_list_id BIGINT UNSIGNED NOT NULL,
                service VARCHAR(20) NOT NULL,
                base_cents INT NOT NULL DEFAULT 0,
                extra_parcel_cents INT NOT NULL DEFAULT 0,
                surcharges_json TEXT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uniq_list_service (price_list_id, service)
            ) {$charset};",

            "CREATE TABLE {$t('shipments')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference VARCHAR(24) NOT NULL,
                customer_id BIGINT UNSIGNED NULL,
                customer_name VARCHAR(160) NOT NULL DEFAULT '',
                contact_email VARCHAR(160) NOT NULL DEFAULT '',
                service VARCHAR(20) NOT NULL,
                hub VARCHAR(20) NOT NULL DEFAULT '',
                status VARCHAR(20) NOT NULL,
                exception VARCHAR(40) NULL,
                pickup_json TEXT NOT NULL,
                delivery_json TEXT NOT NULL,
                pickup_start DATETIME NULL,
                pickup_end DATETIME NULL,
                delivery_start DATETIME NULL,
                delivery_end DATETIME NULL,
                parcels_json TEXT NOT NULL,
                price_cents INT NULL,
                price_override_reason VARCHAR(200) NULL,
                courier_id BIGINT UNSIGNED NULL,
                stop_sequence INT NULL,
                channel VARCHAR(20) NOT NULL DEFAULT 'web',
                tracking_token VARCHAR(40) NOT NULL,
                remarks TEXT NULL,
                created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uniq_reference (reference),
                UNIQUE KEY uniq_token (tracking_token),
                KEY idx_status (status),
                KEY idx_hub_pickup (hub, pickup_start),
                KEY idx_customer (customer_id, created_at),
                KEY idx_courier (courier_id, pickup_start)
            ) {$charset};",

            "CREATE TABLE {$t('shipment_events')} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                shipment_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(20) NOT NULL,
                from_status VARCHAR(20) NULL,
                to_status VARCHAR(20) NULL,
                actor_id BIGINT UNSIGNED NULL,
                actor_name VARCHAR(120) NOT NULL DEFAULT '',
                actor_role VARCHAR(20) NOT NULL DEFAULT '',
                payload_json MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY idx_shipment (shipment_id, id)
            ) {$charset};",
        ];
    }
}
