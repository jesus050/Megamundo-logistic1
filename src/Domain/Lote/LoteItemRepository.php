<?php
namespace MegaMundo\Logistica\Domain\Lote;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LoteItemRepository {

    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'mm_lote_items';
    }

    public function get_items( $lote_id ) {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE lote_id = %d", $lote_id ) );
    }

    public function find_item( $lote_id, $producto_id ) {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE lote_id = %d AND producto_id = %d LIMIT 1",
            $lote_id,
            $producto_id
        ) );
    }

    public function find_by_sku( $lote_id, $sku ) {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE lote_id = %d AND sku = %s LIMIT 1",
            $lote_id,
            $sku
        ) );
    }

    public function insert_item( $data ) {
        global $wpdb;
        $table = $this->get_table_name();
        
        $defaults = array(
            'cantidad_contada' => 0,
            'costo_ia'         => 0.00,
            'precio_propuesto' => 0.00,
            'created_at'       => current_time( 'mysql' ),
            'updated_at'       => current_time( 'mysql' ),
            'synced_at'        => null
        );
        $data = wp_parse_args( $data, $defaults );

        $inserted = $wpdb->insert(
            $table,
            array(
                'lote_id'          => intval( $data['lote_id'] ),
                'producto_id'      => intval( $data['producto_id'] ),
                'sku'              => sanitize_text_field( $data['sku'] ),
                'cantidad_contada' => intval( $data['cantidad_contada'] ),
                'costo_ia'         => floatval( $data['costo_ia'] ),
                'precio_propuesto' => floatval( $data['precio_propuesto'] ),
                'created_by'       => intval( $data['created_by'] ),
                'created_at'       => $data['created_at'],
                'updated_at'       => $data['updated_at'],
                'synced_at'        => $data['synced_at']
            ),
            array( '%d', '%d', '%s', '%d', '%f', '%f', '%d', '%s', '%s', $data['synced_at'] === null ? '%s' : '%s' )
        );

        if ( $inserted ) {
            return $wpdb->insert_id;
        }
        return false;
    }

    public function update_item( $item_id, $lote_id, $data ) {
        global $wpdb;
        $table = $this->get_table_name();
        
        $data['updated_at'] = current_time( 'mysql' );

        // Dinamicamente construimos los formatos
        $fields = array();
        $formats = array();
        
        foreach ( $data as $key => $val ) {
            if ( in_array( $key, array( 'cantidad_contada', 'producto_id', 'created_by' ), true ) ) {
                $fields[ $key ] = intval( $val );
                $formats[] = '%d';
            } elseif ( in_array( $key, array( 'costo_ia', 'precio_propuesto' ), true ) ) {
                $fields[ $key ] = floatval( $val );
                $formats[] = '%f';
            } elseif ( in_array( $key, array( 'sku', 'updated_at', 'synced_at' ), true ) ) {
                $fields[ $key ] = $val === null ? null : sanitize_text_field( $val );
                $formats[] = $val === null ? '%s' : '%s'; // wpdb handles null with %s
            }
        }

        if ( empty( $fields ) ) {
            return false;
        }

        return $wpdb->update(
            $table,
            $fields,
            array( 'id' => intval( $item_id ), 'lote_id' => intval( $lote_id ) ),
            $formats,
            array( '%d', '%d' )
        );
    }

    public function count_distinct_items( $lote_id ) {
        global $wpdb;
        $table = $this->get_table_name();
        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT producto_id) FROM $table WHERE lote_id = %d",
            $lote_id
        ) ) );
    }

    public function sum_total_units( $lote_id ) {
        global $wpdb;
        $table = $this->get_table_name();
        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(cantidad_contada) FROM $table WHERE lote_id = %d",
            $lote_id
        ) ) );
    }

    public function get_financial_totals( $lote_id ) {
        global $wpdb;
        $table = $this->get_table_name();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT 
                SUM(cantidad_contada * costo_ia) as costo_total,
                SUM(cantidad_contada * precio_propuesto) as venta_total
             FROM $table WHERE lote_id = %d",
            $lote_id
        ) );

        $costo_total = $row ? floatval( $row->costo_total ) : 0.0;
        $venta_total = $row ? floatval( $row->venta_total ) : 0.0;
        $margen_total = $venta_total - $costo_total;
        $margen_pct = ($venta_total > 0) ? ($margen_total / $venta_total) * 100 : 0.0;

        return array(
            'costo_total'  => $costo_total,
            'venta_total'  => $venta_total,
            'margen_total' => $margen_total,
            'margen_pct'   => $margen_pct
        );
    }

    public function get_by_lote( $lote_id ) {
        return $this->get_items( $lote_id );
    }

    public function update_prices( $item_id, $lote_id, $costo, $precio ) {
        return $this->update_item( $item_id, $lote_id, array(
            'costo_ia'         => floatval( $costo ),
            'precio_propuesto' => floatval( $precio )
        ) );
    }

    public function count_items_without_price( $lote_id ) {
        global $wpdb;
        $table = $this->get_table_name();
        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE lote_id = %d AND (precio_propuesto IS NULL OR precio_propuesto <= 0)",
            $lote_id
        ) ) );
    }

    public function get_chunk( $lote_id, $limit, $offset ) {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE lote_id = %d LIMIT %d OFFSET %d",
            $lote_id,
            $limit,
            $offset
        ) );
    }

    public function mark_synced( $item_id ) {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->update(
            $table,
            array( 'synced_at' => current_time( 'mysql' ) ),
            array( 'id' => intval( $item_id ) ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public function mark_as_synced( $item_id, $lote_id ) {
        return $this->update_item( $item_id, $lote_id, array(
            'synced_at' => current_time( 'mysql' )
        ) );
    }

    public function count_unsynced_items( $lote_id ) {
        global $wpdb;
        $table = $this->get_table_name();
        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE lote_id = %d AND (synced_at IS NULL OR synced_at = '0000-00-00 00:00:00')",
            $lote_id
        ) ) );
    }
}

