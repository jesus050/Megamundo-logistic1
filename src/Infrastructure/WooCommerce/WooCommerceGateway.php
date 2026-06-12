<?php
namespace MegaMundo\Logistica\Infrastructure\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WooCommerceGateway {

    public function is_active() {
        return class_exists( 'WooCommerce' );
    }

    public function find_product_id_by_sku( $sku ) {
        if ( ! $this->is_active() ) {
            return 0;
        }
        return wc_get_product_id_by_sku( $sku );
    }

    public function create_draft_product( $sku, $name ) {
        if ( ! $this->is_active() ) {
            return false;
        }

        $post_id = wp_insert_post( array(
            'post_title'  => sanitize_text_field( $name ),
            'post_status' => 'draft',
            'post_type'   => 'product',
        ) );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            return false;
        }

        // Configurar metadatos del producto simple por defecto
        update_post_meta( $post_id, '_sku', sanitize_text_field( $sku ) );
        update_post_meta( $post_id, '_manage_stock', 'yes' );
        update_post_meta( $post_id, '_stock_status', 'instock' );
        update_post_meta( $post_id, '_visibility', 'visible' );
        
        // Inicializar stock en 0 antes del ingreso
        wc_update_product_stock( $post_id, 0, 'set' );

        return $post_id;
    }

    public function update_stock( $product_id, $qty, $movement_type = 'sumar' ) {
        if ( ! $this->is_active() ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        // Asegurar que gestione stock
        if ( ! $product->get_manage_stock() ) {
            $product->set_manage_stock( true );
            $product->save();
        }

        if ( 'reemplazar' === $movement_type ) {
            // Reemplazar stock
            $new_stock = wc_update_product_stock( $product, $qty, 'set' );
        } else {
            // Sumar stock (default)
            $new_stock = wc_update_product_stock( $product, $qty, 'increase' );
        }

        return $new_stock !== false;
    }

    public function update_price( $product_id, $price ) {
        if ( ! $this->is_active() ) {
            return false;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return false;
        }

        $price = floatval( $price );
        $product->set_regular_price( $price );
        $product->set_price( $price );
        
        // Si el estado es draft, lo publicamos
        if ( 'draft' === $product->get_status() ) {
            $product->set_status( 'publish' );
        }

        $product->save();
        return true;
    }

    public function clear_transients( $product_id ) {
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $product_id );
        }
    }
}
