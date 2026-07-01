<?php
namespace MegaMundo\Logistica\Database;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DatabaseInstaller {

    private $db_version = '3.4.0';

    public function get_db_version() {
        return $this->db_version;
    }

    public function install() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mm_lote_items';
        $table_audit    = $wpdb->prefix . 'mm_lote_audit_logs';
        $table_comments = $wpdb->prefix . 'mm_lote_comments';
        $table_inv      = $wpdb->prefix . 'mm_inventario';
        $table_ventas   = $wpdb->prefix . 'mm_ventas';
        $table_ubic     = $wpdb->prefix . 'mm_ubicaciones';
        $table_fisico   = $wpdb->prefix . 'mm_inventario_fisico';
        $table_equiv    = $wpdb->prefix . 'mm_equivalencias';
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

        // Inventario importado desde Mekano: base para el análisis de rotación.
        // Una fila por SKU; reimportar actualiza (UNIQUE en sku).
        // viene     = saldo inicial del periodo (lo que había antes)
        // entradas  = unidades que ingresaron al bodegaje en el periodo
        // salidas   = unidades vendidas / despachadas en el periodo
        // stock     = EXISTENCIA actual = viene + entradas − salidas
        $sql_inv = "CREATE TABLE $table_inv (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sku varchar(191) DEFAULT '' NOT NULL,
            nombre varchar(255) DEFAULT '' NOT NULL,
            linea varchar(191) DEFAULT '' NOT NULL,
            viene int(11) DEFAULT 0 NOT NULL,
            entradas int(11) DEFAULT 0 NOT NULL,
            salidas int(11) DEFAULT 0 NOT NULL,
            stock int(11) DEFAULT 0 NOT NULL,
            costo decimal(12,2) DEFAULT 0.00 NOT NULL,
            ultima_venta date DEFAULT NULL,
            vendido_periodo int(11) DEFAULT NULL,
            periodo_dias int(11) DEFAULT NULL,
            importado_en datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sku (sku),
            KEY linea (linea),
            KEY ultima_venta (ultima_venta)
        ) $charset_collate;";

        // Movimientos de venta importados de los reportes de Mekano.
        // UNIQUE (sku, fecha): reimportar la misma fecha reemplaza, no duplica.
        $sql_ventas = "CREATE TABLE $table_ventas (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sku varchar(191) DEFAULT '' NOT NULL,
            nombre varchar(255) DEFAULT '' NOT NULL,
            fecha date NOT NULL,
            unidades int(11) DEFAULT 0 NOT NULL,
            valor_neto decimal(14,2) DEFAULT 0.00 NOT NULL,
            importado_en datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sku_fecha (sku, fecha),
            KEY sku (sku),
            KEY fecha (fecha)
        ) $charset_collate;";

        // Módulo Ubicaciones e Inventario Físico (apoyo operativo, NO reemplaza a Mekano).
        // Ubicaciones físicas de bodega: una fila por código de ubicación (UNIQUE en codigo).
        $sql_ubic = "CREATE TABLE $table_ubic (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            codigo varchar(191) DEFAULT '' NOT NULL,
            nombre varchar(255) DEFAULT '' NOT NULL,
            bodega varchar(100) DEFAULT '' NOT NULL,
            zona varchar(100) DEFAULT '' NOT NULL,
            pasillo varchar(100) DEFAULT '' NOT NULL,
            estante varchar(100) DEFAULT '' NOT NULL,
            nivel varchar(100) DEFAULT '' NOT NULL,
            posicion varchar(100) DEFAULT '' NOT NULL,
            categoria varchar(191) DEFAULT '' NOT NULL,
            estado varchar(20) DEFAULT 'activa' NOT NULL,
            observacion text DEFAULT NULL,
            created_by bigint(20) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY codigo (codigo),
            KEY estado (estado),
            KEY categoria (categoria)
        ) $charset_collate;";

        // Conteo físico: una fila por conteo de producto en una ubicación.
        // cantidad_unidades = cantidad * equivalencia (calculado al guardar).
        $sql_fisico = "CREATE TABLE $table_fisico (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sku varchar(191) DEFAULT '' NOT NULL,
            nombre_producto varchar(255) DEFAULT '' NOT NULL,
            categoria varchar(191) DEFAULT '' NOT NULL,
            ubicacion_id bigint(20) DEFAULT 0 NOT NULL,
            cantidad int(11) DEFAULT 0 NOT NULL,
            presentacion varchar(100) DEFAULT 'unidad' NOT NULL,
            equivalencia int(11) DEFAULT 1 NOT NULL,
            cantidad_unidades int(11) DEFAULT 0 NOT NULL,
            observacion text DEFAULT NULL,
            estado varchar(20) DEFAULT 'contado' NOT NULL,
            counted_by bigint(20) DEFAULT 0 NOT NULL,
            counted_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            KEY sku (sku),
            KEY ubicacion_id (ubicacion_id),
            KEY estado (estado),
            KEY counted_at (counted_at)
        ) $charset_collate;";

        // Equivalencias de presentación: unidad, docena, paca, caja, etc.
        $sql_equiv = "CREATE TABLE $table_equiv (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            nombre varchar(100) DEFAULT '' NOT NULL,
            unidades int(11) DEFAULT 1 NOT NULL,
            estado varchar(20) DEFAULT 'activo' NOT NULL,
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY nombre (nombre),
            KEY estado (estado)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        dbDelta( $sql_audit );
        dbDelta( $sql_comments );
        dbDelta( $sql_inv );
        dbDelta( $sql_ventas );
        dbDelta( $sql_ubic );
        dbDelta( $sql_fisico );
        dbDelta( $sql_equiv );

        $this->seed_equivalencias( $table_equiv );

        update_option( 'mm_logistica_db_version', $this->db_version );
    }

    /**
     * Semilla de equivalencias base solo si la tabla está vacía.
     * No sobrescribe valores que el usuario ya haya ajustado.
     */
    private function seed_equivalencias( $table_equiv ) {
        global $wpdb;

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_equiv" );
        if ( $count > 0 ) {
            return;
        }

        $now = current_time( 'mysql' );
        $defaults = array(
            array( 'unidad', 1 ),
            array( 'docena', 12 ),
            array( 'paca', 50 ),
            array( 'caja', 144 ),
        );

        foreach ( $defaults as $row ) {
            $wpdb->insert(
                $table_equiv,
                array(
                    'nombre'     => $row[0],
                    'unidades'   => $row[1],
                    'estado'     => 'activo',
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array( '%s', '%d', '%s', '%s', '%s' )
            );
        }
    }

    public function maybe_update() {
        $current_version = get_option( 'mm_logistica_db_version' );
        if ( $current_version !== $this->db_version ) {
            $this->install();
        }
    }
}
