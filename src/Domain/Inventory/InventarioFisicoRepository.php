<?php
namespace MegaMundo\Logistica\Domain\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Conteos físicos: qué producto hay, cuánto hay y dónde está.
 * cantidad_unidades = cantidad * equivalencia.
 */
class InventarioFisicoRepository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'mm_inventario_fisico';
    }

    /**
     * Lista conteos con el código/nombre de la ubicación resuelto (LEFT JOIN).
     * $filters admite: ubicacion_id, sku, estado.
     */
    public function all( $filters = array() ) {
        global $wpdb;
        $table = $this->table();
        $ubic  = $wpdb->prefix . 'mm_ubicaciones';

        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $filters['ubicacion_id'] ) ) {
            $where[]  = 'f.ubicacion_id = %d';
            $params[] = intval( $filters['ubicacion_id'] );
        }
        if ( ! empty( $filters['sku'] ) ) {
            $where[]  = 'f.sku = %s';
            $params[] = sanitize_text_field( $filters['sku'] );
        }
        if ( ! empty( $filters['estado'] ) ) {
            $where[]  = 'f.estado = %s';
            $params[] = sanitize_text_field( $filters['estado'] );
        }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT f.*, u.codigo AS ubicacion_codigo, u.nombre AS ubicacion_nombre
                FROM $table f
                LEFT JOIN $ubic u ON u.id = f.ubicacion_id
                WHERE $where_sql
                ORDER BY f.counted_at DESC";

        if ( ! empty( $params ) ) {
            return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        }
        return $wpdb->get_results( $sql );
    }

    public function find( $id ) {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", intval( $id ) ) );
    }

    public function total_unidades( $filters = array() ) {
        $rows = $this->all( $filters );
        $total = 0;
        foreach ( $rows as $r ) {
            $total += intval( $r->cantidad_unidades );
        }
        return $total;
    }

    /**
     * Registra un conteo. cantidad_unidades se calcula aquí.
     * Devuelve el id insertado o false.
     */
    public function insert( $data ) {
        global $wpdb;
        $table = $this->table();
        $now   = current_time( 'mysql' );

        $cantidad     = max( 0, intval( $data['cantidad'] ?? 0 ) );
        $equivalencia = max( 1, intval( $data['equivalencia'] ?? 1 ) );

        $inserted = $wpdb->insert(
            $table,
            array(
                'sku'               => sanitize_text_field( $data['sku'] ?? '' ),
                'nombre_producto'   => sanitize_text_field( $data['nombre_producto'] ?? '' ),
                'categoria'         => sanitize_text_field( $data['categoria'] ?? '' ),
                'ubicacion_id'      => intval( $data['ubicacion_id'] ?? 0 ),
                'cantidad'          => $cantidad,
                'presentacion'      => sanitize_text_field( $data['presentacion'] ?? 'unidad' ),
                'equivalencia'      => $equivalencia,
                'cantidad_unidades' => $cantidad * $equivalencia,
                'observacion'       => sanitize_textarea_field( $data['observacion'] ?? '' ),
                'estado'            => sanitize_text_field( $data['estado'] ?? 'contado' ),
                'counted_by'        => get_current_user_id(),
                'counted_at'        => $now,
                'updated_at'        => $now,
            ),
            array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        return $inserted ? intval( $wpdb->insert_id ) : false;
    }

    public function delete( $id ) {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table(), array( 'id' => intval( $id ) ), array( '%d' ) );
    }
}
