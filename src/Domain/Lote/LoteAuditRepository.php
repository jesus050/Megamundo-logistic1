<?php
namespace MegaMundo\Logistica\Domain\Lote;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LoteAuditRepository {

    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'mm_lote_audit_logs';
    }

    public function add_log( $lote_id, $action, $message = '', $item_id = null, $producto_id = null, $sku = null, $old_value = null, $new_value = null, $user_id = null ) {
        global $wpdb;
        $table = $this->get_table_name();

        if ( $user_id === null ) {
            $user_id = get_current_user_id();
        }
        $ip_address = $this->get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';

        return $wpdb->insert(
            $table,
            array(
                'lote_id'     => intval( $lote_id ),
                'item_id'     => $item_id !== null ? intval( $item_id ) : null,
                'producto_id' => $producto_id !== null ? intval( $producto_id ) : null,
                'sku'         => $sku !== null ? sanitize_text_field( $sku ) : null,
                'user_id'     => intval( $user_id ),
                'action'      => sanitize_text_field( $action ),
                'message'     => sanitize_textarea_field( $message ),
                'old_value'   => $old_value !== null ? ( is_scalar( $old_value ) ? (string) $old_value : maybe_serialize( $old_value ) ) : null,
                'new_value'   => $new_value !== null ? ( is_scalar( $new_value ) ? (string) $new_value : maybe_serialize( $new_value ) ) : null,
                'ip_address'  => sanitize_text_field( $ip_address ),
                'user_agent'  => $user_agent,
                'created_at'  => current_time( 'mysql' )
            ),
            array( '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public function get_logs( $lote_id, $limit = 100, $offset = 0 ) {
        global $wpdb;
        $table = $this->get_table_name();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE lote_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $lote_id,
            $limit,
            $offset
        ) );
    }

    private function get_client_ip() {
        $ip = '0.0.0.0';
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }
}
