<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_Database {

    public static function crear_tabla_lotes() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mm_lote_items';
        $charset_collate = $wpdb->get_charset_collate();

        // Estructura optimizada para auditoría y trazabilidad
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

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
