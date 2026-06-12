<?php
namespace MegaMundo\Logistica\Application\Sync;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use Exception;
use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Domain\Lote\LoteAuditRepository;
use MegaMundo\Logistica\Infrastructure\WooCommerce\WooCommerceGateway;

class SyncProcessor {

    private $lote_repo;
    private $item_repo;
    private $wc_gateway;
    private $audit_repo;

    public function __construct( LoteRepository $lote_repo, LoteItemRepository $item_repo, WooCommerceGateway $wc_gateway, LoteAuditRepository $audit_repo ) {
        $this->lote_repo  = $lote_repo;
        $this->item_repo  = $item_repo;
        $this->wc_gateway = $wc_gateway;
        $this->audit_repo = $audit_repo;
    }

    public function hook() {
        add_action( 'mm_logistica_sync_lote_chunk', array( $this, 'ejecutar_sync_chunk' ), 10, 1 );
        add_action( 'mm_logistica_finish_lote_sync', array( $this, 'finalizar_sincronizacion' ), 10, 1 );
    }

    public function ejecutar_sync_chunk( $args ) {
        $lote_id      = isset( $args['lote_id'] ) ? intval( $args['lote_id'] ) : 0;
        $limit        = isset( $args['limit'] ) ? intval( $args['limit'] ) : 50;
        $offset       = isset( $args['offset'] ) ? intval( $args['offset'] ) : 0;
        $chunk_index  = isset( $args['chunk_index'] ) ? intval( $args['chunk_index'] ) : 0;
        $total_chunks = isset( $args['total_chunks'] ) ? intval( $args['total_chunks'] ) : 1;

        if ( ! $lote_id ) {
            return;
        }

        $sync_meta = $this->lote_repo->get_sync_meta( $lote_id );
        $status = $sync_meta['status'];
        if ( $status === 'completed' || $status === 'completed_with_errors' ) {
            return;
        }

        if ( ! $this->wc_gateway->is_active() ) {
            return;
        }

        // Cambiar estado a procesando
        $this->lote_repo->update_sync_meta( $lote_id, 'status', 'processing' );

        global $wpdb;
        $tabla = $wpdb->prefix . 'mm_lote_items';
        $items = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $tabla WHERE lote_id = %d LIMIT %d OFFSET %d",
            $lote_id,
            $limit,
            $offset
        ) );

        if ( ! $items ) {
            $this->incrementar_y_comprobar_cierre( $lote_id, 0 );
            return;
        }

        $processed_count = 0;
        $tipo_movimiento = $this->lote_repo->get_movement_type( $lote_id );

        foreach ( $items as $item ) {
            $producto_id = intval( $item->producto_id );
            $sku         = $item->sku;

            if ( ! get_post( $producto_id ) || ! in_array( get_post_type( $producto_id ), array( 'product', 'product_variation' ), true ) ) {
                $this->lote_repo->add_sync_error(
                    $lote_id,
                    $producto_id,
                    $sku,
                    sprintf( 'El producto ID %d no existe o no es válido.', $producto_id )
                );
                $processed_count++;
                continue;
            }

            $cantidad  = intval( $item->cantidad_contada );
            $precio    = floatval( $item->precio_propuesto );
            $has_error = false;

            // A) Actualizar el Inventario
            if ( $cantidad > 0 ) {
                try {
                    $stock_updated = $this->wc_gateway->update_stock( $producto_id, $cantidad, $tipo_movimiento );
                    if ( ! $stock_updated ) {
                        $has_error = true;
                        $this->lote_repo->add_sync_error(
                            $lote_id,
                            $producto_id,
                            $sku,
                            'Error actualizando stock (retorno false).'
                        );
                    }
                } catch ( Exception $e ) {
                    $has_error = true;
                    $this->lote_repo->add_sync_error(
                        $lote_id,
                        $producto_id,
                        $sku,
                        sprintf( 'Excepción actualizando stock: %s', $e->getMessage() )
                    );
                }
            }

            // B) Actualizar el Precio
            if ( $precio > 0 ) {
                try {
                    $price_updated = $this->wc_gateway->update_price( $producto_id, $precio );
                    if ( ! $price_updated ) {
                        $has_error = true;
                        $this->lote_repo->add_sync_error(
                            $lote_id,
                            $producto_id,
                            $sku,
                            'Error actualizando precio.'
                        );
                    } else {
                        $this->wc_gateway->clear_transients( $producto_id );
                    }
                } catch ( Exception $e ) {
                    $has_error = true;
                    $this->lote_repo->add_sync_error(
                        $lote_id,
                        $producto_id,
                        $sku,
                        sprintf( 'Excepción actualizando precio: %s', $e->getMessage() )
                    );
                }
            }

            // C) Publicar si es borrador
            $estado_actual = get_post_status( $producto_id );
            if ( $estado_actual === 'draft' ) {
                $update_result = wp_update_post( array(
                    'ID'          => $producto_id,
                    'post_status' => 'publish'
                ) );
                if ( is_wp_error( $update_result ) ) {
                    $has_error = true;
                    $this->lote_repo->add_sync_error(
                        $lote_id,
                        $producto_id,
                        $sku,
                        sprintf( 'Error publicando producto: %s', $update_result->get_error_message() )
                    );
                }
            }

            // D) Si no hubo errores, marcar item como sincronizado en la base de datos e informar en auditoría
            if ( ! $has_error ) {
                $this->item_repo->mark_as_synced( $item->id, $lote_id );
                $this->audit_repo->add_log(
                    $lote_id,
                    'sync_producto_ok',
                    sprintf( 'Producto SKU %s sincronizado correctamente en WooCommerce.', $sku ),
                    $item->id,
                    $producto_id,
                    $sku
                );
            } else {
                $this->audit_repo->add_log(
                    $lote_id,
                    'sync_producto_error',
                    sprintf( 'Error al sincronizar producto SKU %s.', $sku ),
                    $item->id,
                    $producto_id,
                    $sku
                );
            }

            $processed_count++;
        }

        $this->incrementar_y_comprobar_cierre( $lote_id, $processed_count );
    }

    private function incrementar_y_comprobar_cierre( $lote_id, $count ) {
        global $wpdb;

        // Incremento atómico en base de datos para evitar colisiones
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS SIGNED) + %d WHERE post_id = %d AND meta_key = '_mm_sync_processed_items'",
            $count,
            $lote_id
        ) );
        wp_cache_delete( $lote_id, 'post_meta' );

        // Leer valores actualizados
        $total     = intval( get_post_meta( $lote_id, '_mm_sync_total_items', true ) );
        $processed = intval( get_post_meta( $lote_id, '_mm_sync_processed_items', true ) );

        if ( $processed >= $total ) {
            do_action( 'mm_logistica_finish_lote_sync', $lote_id );
        }
    }

    public function finalizar_sincronizacion( $lote_id ) {
        $sync_meta = $this->lote_repo->get_sync_meta( $lote_id );
        $sync_status = $sync_meta['status'];
        if ( $sync_status === 'completed' || $sync_status === 'completed_with_errors' ) {
            return;
        }

        $sync_errors = $this->lote_repo->get_sync_errors( $lote_id );

        if ( empty( $sync_errors ) ) {
            $this->lote_repo->update_sync_meta( $lote_id, 'status', 'completed' );
        } else {
            $this->lote_repo->update_sync_meta( $lote_id, 'status', 'completed_with_errors' );
        }
        
        $this->lote_repo->mark_as_synchronized( $lote_id );
        $this->lote_repo->update_sync_meta( $lote_id, 'completed_at', current_time( 'mysql' ) );
    }
}
