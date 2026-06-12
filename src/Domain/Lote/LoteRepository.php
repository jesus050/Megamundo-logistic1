<?php
namespace MegaMundo\Logistica\Domain\Lote;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LoteRepository {

    public function find( $lote_id ) {
        $post = get_post( $lote_id );
        if ( ! $post || $post->post_type !== 'lotes_ingreso' ) {
            return null;
        }
        return $post;
    }

    public function find_by_status( $status ) {
        return get_posts( array(
            'post_type'   => 'lotes_ingreso',
            'post_status' => $status,
            'numberposts' => -1
        ) );
    }

    public function get_status( $lote_id ) {
        return get_post_status( $lote_id );
    }

    public function update_status( $lote_id, $status ) {
        if ( ! in_array( $status, LoteStatuses::get_all(), true ) ) {
            return false;
        }
        $updated = wp_update_post( array(
            'ID'          => $lote_id,
            'post_status' => $status
        ) );
        return ! is_wp_error( $updated );
    }

    public function is_synchronized( $lote_id ) {
        return (bool) get_post_meta( $lote_id, '_sincronizado_wc', true );
    }

    public function mark_as_synchronized( $lote_id ) {
        return update_post_meta( $lote_id, '_sincronizado_wc', '1' );
    }

    public function get_movement_type( $lote_id ) {
        return get_post_meta( $lote_id, '_mm_tipo_movimiento', true ) ?: 'sumar';
    }

    public function update_movement_type( $lote_id, $type ) {
        if ( ! in_array( $type, array( 'sumar', 'reemplazar' ), true ) ) {
            return false;
        }
        return update_post_meta( $lote_id, '_mm_tipo_movimiento', $type );
    }

    // Métodos para el estado de sincronización (Action Scheduler)
    public function get_sync_meta( $lote_id ) {
        return array(
            'status'             => $this->get_sync_status( $lote_id ),
            'total_items'        => $this->get_sync_total_items( $lote_id ),
            'processed_items'    => $this->get_sync_processed_items( $lote_id ),
            'started_at'         => get_post_meta( $lote_id, '_mm_sync_started_at', true ) ?: '-',
            'completed_at'       => get_post_meta( $lote_id, '_mm_sync_completed_at', true ) ?: '-',
            'fallback'           => (bool) get_post_meta( $lote_id, '_mm_sync_scheduler_fallback', true ),
            'scheduler_fallback' => (bool) get_post_meta( $lote_id, '_mm_sync_scheduler_fallback', true )
        );
    }

    public function get_sync_status( $lote_id ) {
        return get_post_meta( $lote_id, '_mm_sync_status', true ) ?: 'pendiente';
    }

    public function update_sync_status( $lote_id, $status ) {
        return update_post_meta( $lote_id, '_mm_sync_status', $status );
    }

    public function update_sync_total_items( $lote_id, $total ) {
        return update_post_meta( $lote_id, '_mm_sync_total_items', intval( $total ) );
    }

    public function get_sync_total_items( $lote_id ) {
        return intval( get_post_meta( $lote_id, '_mm_sync_total_items', true ) ?: 0 );
    }

    public function update_sync_processed_items( $lote_id, $processed ) {
        return update_post_meta( $lote_id, '_mm_sync_processed_items', intval( $processed ) );
    }

    public function get_sync_processed_items( $lote_id ) {
        return intval( get_post_meta( $lote_id, '_mm_sync_processed_items', true ) ?: 0 );
    }

    public function increment_sync_processed_items( $lote_id, $count ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE post_id = %d AND meta_key = '_mm_sync_processed_items'",
            $count,
            $lote_id
        ) );
        wp_cache_delete( $lote_id, 'post_meta' );
    }

    public function update_sync_started_time( $lote_id ) {
        return update_post_meta( $lote_id, '_mm_sync_started_at', current_time( 'mysql' ) );
    }

    public function update_sync_completed_time( $lote_id ) {
        return update_post_meta( $lote_id, '_mm_sync_completed_at', current_time( 'mysql' ) );
    }

    public function clear_scheduler_fallback( $lote_id ) {
        return delete_post_meta( $lote_id, '_mm_sync_scheduler_fallback' );
    }

    public function set_scheduler_fallback( $lote_id ) {
        return update_post_meta( $lote_id, '_mm_sync_scheduler_fallback', '1' );
    }

    public function mark_synchronized( $lote_id ) {
        return update_post_meta( $lote_id, '_sincronizado_wc', current_time( 'mysql' ) );
    }

    public function update_sync_meta( $lote_id, $key, $value ) {
        return update_post_meta( $lote_id, '_mm_sync_' . $key, $value );
    }

    public function get_sync_errors( $lote_id ) {
        $errors = get_post_meta( $lote_id, '_sync_errors', false );
        return is_array( $errors ) ? $errors : array();
    }

    public function add_sync_error( $lote_id, $product_id_or_array, $sku = '', $message = '' ) {
        if ( is_array( $product_id_or_array ) ) {
            $error = $product_id_or_array;
        } else {
            $error = array(
                'producto_id' => $product_id_or_array,
                'sku'         => $sku,
                'mensaje'     => $message,
                'fecha'       => current_time( 'mysql' )
            );
        }
        return add_post_meta( $lote_id, '_sync_errors', $error );
    }

    public function clear_sync_errors( $lote_id ) {
        return delete_post_meta( $lote_id, '_sync_errors' );
    }
}

