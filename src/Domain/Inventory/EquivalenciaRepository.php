<?php
namespace MegaMundo\Logistica\Domain\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Equivalencias de presentación (unidad, docena, paca, caja, ...).
 * Cada presentación equivale a un número de unidades base.
 */
class EquivalenciaRepository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'mm_equivalencias';
    }

    public function all( $solo_activas = false ) {
        global $wpdb;
        $table = $this->table();
        if ( $solo_activas ) {
            return $wpdb->get_results( "SELECT * FROM $table WHERE estado = 'activo' ORDER BY unidades ASC" );
        }
        return $wpdb->get_results( "SELECT * FROM $table ORDER BY unidades ASC" );
    }

    public function find( $id ) {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", intval( $id ) ) );
    }

    public function find_by_nombre( $nombre ) {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE nombre = %s LIMIT 1", $nombre ) );
    }

    /**
     * Crea o actualiza (por id) una equivalencia. Devuelve el id o false.
     */
    public function save( $data, $id = 0 ) {
        global $wpdb;
        $table = $this->table();
        $now   = current_time( 'mysql' );

        $fields = array(
            'nombre'     => sanitize_text_field( $data['nombre'] ?? '' ),
            'unidades'   => max( 1, intval( $data['unidades'] ?? 1 ) ),
            'estado'     => ( isset( $data['estado'] ) && 'inactivo' === $data['estado'] ) ? 'inactivo' : 'activo',
            'updated_at' => $now,
        );
        $formats = array( '%s', '%d', '%s', '%s' );

        if ( $id > 0 ) {
            $wpdb->update( $table, $fields, array( 'id' => intval( $id ) ), $formats, array( '%d' ) );
            return intval( $id );
        }

        $fields['created_at'] = $now;
        $formats[] = '%s';
        $inserted = $wpdb->insert( $table, $fields, $formats );
        return $inserted ? intval( $wpdb->insert_id ) : false;
    }

    public function delete( $id ) {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table(), array( 'id' => intval( $id ) ), array( '%d' ) );
    }
}
