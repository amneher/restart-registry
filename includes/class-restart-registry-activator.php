<?php

/**
 * Fired during plugin activation
 *
 * @link       https://the-restart.co
 * @since      1.0.0
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Activator {

    public static function activate() {
        self::create_tables();
        self::add_capabilities();
        flush_rewrite_rules();
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $registries_table = $wpdb->prefix . 'restart_registries';
        $items_table = $wpdb->prefix . 'restart_registry_items';
        $invites_table = $wpdb->prefix . 'restart_registry_invites';
        $purchases_table = $wpdb->prefix . 'restart_registry_purchases';

        $sql = "CREATE TABLE $registries_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            title varchar(255) NOT NULL,
            description text,
            share_key varchar(64) NOT NULL,
            is_public tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY share_key (share_key),
            KEY user_id (user_id)
        ) $charset_collate;

        CREATE TABLE $items_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            registry_id bigint(20) UNSIGNED NOT NULL,
            name varchar(255) NOT NULL,
            description text,
            original_url text NOT NULL,
            affiliate_url text,
            image_url text,
            price decimal(10,2),
            retailer varchar(100),
            quantity_needed int(11) DEFAULT 1,
            quantity_purchased int(11) DEFAULT 0,
            priority enum('low','medium','high') DEFAULT 'medium',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY registry_id (registry_id)
        ) $charset_collate;

        CREATE TABLE $invites_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            registry_id bigint(20) UNSIGNED NOT NULL,
            email varchar(255) NOT NULL,
            invite_token varchar(64) NOT NULL,
            status enum('pending','accepted','declined') DEFAULT 'pending',
            sent_at datetime DEFAULT CURRENT_TIMESTAMP,
            accepted_at datetime,
            PRIMARY KEY (id),
            UNIQUE KEY invite_token (invite_token),
            KEY registry_id (registry_id),
            KEY email (email)
        ) $charset_collate;

        CREATE TABLE $purchases_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id bigint(20) UNSIGNED NOT NULL,
            registry_id bigint(20) UNSIGNED NOT NULL,
            purchaser_name varchar(255),
            purchaser_email varchar(255),
            quantity int(11) DEFAULT 1,
            is_anonymous tinyint(1) DEFAULT 0,
            purchased_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY item_id (item_id),
            KEY registry_id (registry_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        add_option('restart_registry_db_version', '1.0.0');
    }

    private static function add_capabilities() {
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_restart_registry');
        }
    }
}
