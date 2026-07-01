<?php
namespace MegaMundo\Logistica\Domain\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Ubicaciones físicas de bodega (Bodega > Zona > Pasillo > Estante > Nivel > Posición).
 * Identificadas por un código único (ej: BOD-01-A-03-02-D).
 */
class UbicacionRepository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'mm_ubicaciones';
    }

    public function all( $solo_activas = false ) {
        global $wpdb;
        $table = $this->table();
        if ( $solo_activas ) {
            return $wpdb->get_results( "SELECT * FROM $table WHERE estado = 'activa' ORDER BY codigo ASC" );
        }
        return $wpdb->get_results( "SELECT * FROM $table ORDER BY codigo ASC" );
    }

    public function find( $id ) {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", intval( $id ) ) );
    }

    public function find_by_codigo( $codigo ) {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE codigo = %s LIMIT 1", $codigo ) );
    }

    public function count() {
        global $wpdb;
        $table = $this->table();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    }

    /**
     * Crea o actualiza (por id) una ubicación. Devuelve el id o false.
     */
    public function save( $data, $id = 0 ) {
        global $wpdb;
        $table = $this->table();
        $now   = current_time( 'mysql' );

        $fields = array(
            'codigo'      => sanitize_text_field( $data['codigo'] ?? '' ),
            'nombre'      => sanitize_text_field( $data['nombre'] ?? '' ),
            'bodega'      => sanitize_text_field( $data['bodega'] ?? '' ),
            'zona'        => sanitize_text_field( $data['zona'] ?? '' ),
            'pasillo'     => sanitize_text_field( $data['pasillo'] ?? '' ),
            'estante'     => sanitize_text_field( $data['estante'] ?? '' ),
            'nivel'       => sanitize_text_field( $data['nivel'] ?? '' ),
            'posicion'    => sanitize_text_field( $data['posicion'] ?? '' ),
            'categoria'   => sanitize_text_field( $data['categoria'] ?? '' ),
            'estado'      => ( isset( $data['estado'] ) && 'inactiva' === $data['estado'] ) ? 'inactiva' : 'activa',
            'observacion' => sanitize_textarea_field( $data['observacion'] ?? '' ),
            'updated_at'  => $now,
        );
        $formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        if ( $id > 0 ) {
            $wpdb->update( $table, $fields, array( 'id' => intval( $id ) ), $formats, array( '%d' ) );
            return intval( $id );
        }

        $fields['created_by'] = get_current_user_id();
        $fields['created_at'] = $now;
        $formats[] = '%d';
        $formats[] = '%s';
        $inserted = $wpdb->insert( $table, $fields, $formats );
        return $inserted ? intval( $wpdb->insert_id ) : false;
    }

    public function delete( $id ) {
        global $wpdb;
        return (bool) $wpdb->delete( $this->table(), array( 'id' => intval( $id ) ), array( '%d' ) );
    }
}
