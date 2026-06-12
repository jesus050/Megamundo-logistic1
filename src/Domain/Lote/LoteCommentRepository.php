<?php
namespace MegaMundo\Logistica\Domain\Lote;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LoteCommentRepository {

    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'mm_lote_comments';
    }

    /**
     * Inserta un comentario interno en la base de datos.
     *
     * @param int    $lote_id
     * @param int    $user_id
     * @param string $comment    Texto del comentario (ya sanitizado).
     * @param string $visibility 'internal' por defecto.
     * @return int|false         ID insertado o false si falló.
     */
    public function add_comment( $lote_id, $user_id, $comment, $visibility = 'internal' ) {
        global $wpdb;
        $table = $this->get_table_name();

        $inserted = $wpdb->insert(
            $table,
            array(
                'lote_id'    => intval( $lote_id ),
                'user_id'    => intval( $user_id ),
                'comment'    => sanitize_textarea_field( $comment ),
                'visibility' => sanitize_text_field( $visibility ),
                'created_at' => current_time( 'mysql' ),
                'updated_at' => null,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s' )
        );

        if ( $inserted ) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Obtiene los comentarios de un lote ordenados de más reciente a más antiguo.
     *
     * @param int $lote_id
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function get_comments( $lote_id, $limit = 50, $offset = 0 ) {
        global $wpdb;
        $table = $this->get_table_name();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE lote_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            intval( $lote_id ),
            intval( $limit ),
            intval( $offset )
        ) );
    }

    /**
     * Cuenta comentarios totales de un lote.
     *
     * @param int $lote_id
     * @return int
     */
    public function count_comments( $lote_id ) {
        global $wpdb;
        $table = $this->get_table_name();

        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE lote_id = %d",
            intval( $lote_id )
        ) ) );
    }
}
