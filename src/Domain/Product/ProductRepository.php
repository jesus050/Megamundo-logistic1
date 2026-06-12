<?php
namespace MegaMundo\Logistica\Domain\Product;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ProductRepository {

    public function find_by_sku( $sku ) {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return null;
        }
        $product_id = wc_get_product_id_by_sku( $sku );
        if ( $product_id ) {
            return wc_get_product( $product_id );
        }
        return null;
    }

    public function find_id_by_sku( $sku ) {
        if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
            return 0;
        }
        return wc_get_product_id_by_sku( $sku );
    }

    public function find( $product_id ) {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return null;
        }
        return wc_get_product( $product_id );
    }

    public function exists( $product_id ) {
        $post_type = get_post_type( $product_id );
        return ( 'product' === $post_type || 'product_variation' === $post_type );
    }
}
