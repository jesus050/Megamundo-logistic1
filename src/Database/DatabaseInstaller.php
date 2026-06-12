<?php
namespace MegaMundo\Logistica\Database;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DatabaseInstaller {

    private $db_version = '3.0.0';

    public function get_db_version() {
        return $this->db_version;
    }

    public function install() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mm_lote_items';
        $table_audit    = $wpdb->prefix . 'mm_lote_audit_logs';
        $table_comments = $wpdb->prefix . 'mm_lote_comments';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            lote_id bigint(20) NOT NULL,
            producto_id bigint(20) NOT NULL,
            sku varchar(191) DEFAULT '' NOT NULL,
            cantidad_contada int(11) DEFAULT 0 NOT NULL,
            costo_ia decimal(10,2) DEFAULT 0.00,
            precio_propuesto decimal(10,2) DEFAULT 0.00,
            created_by bigint(20) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY lote_id (lote_id),
            KEY producto_id (producto_id)
        ) $charset_collate;";

        $sql_audit = "CREATE TABLE $table_audit (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            lote_id bigint(20) NOT NULL,
            item_id bigint(20) DEFAULT NULL,
            producto_id bigint(20) DEFAULT NULL,
            sku varchar(191) DEFAULT NULL,
            user_id bigint(20) NOT NULL,
            action varchar(100) NOT NULL,
            message text DEFAULT NULL,
            old_value longtext DEFAULT NULL,
            new_value longtext DEFAULT NULL,
            ip_address varchar(100) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lote_id (lote_id),
            KEY item_id (item_id),
            KEY producto_id (producto_id),
            KEY sku (sku),
            KEY user_id (user_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";

        $sql_comments = "CREATE TABLE $table_comments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            lote_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            comment text NOT NULL,
            visibility varchar(50) NOT NULL DEFAULT 'internal',
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY lote_id (lote_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        dbDelta( $sql_audit );
        dbDelta( $sql_comments );

        update_option( 'mm_logistica_db_version', $this->db_version );
    }

    public function maybe_update() {
        $current_version = get_option( 'mm_logistica_db_version' );
        if ( $current_version !== $this->db_version ) {
            $this->install();
        }
    }
}
