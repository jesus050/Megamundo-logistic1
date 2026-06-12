<?php
namespace MegaMundo\Logistica\Application\Sync;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use Exception;

class WooCommerceSyncService {

    private $lote_repo;
    private $item_repo;

    public function __construct( LoteRepository $lote_repo, LoteItemRepository $item_repo ) {
        $this->lote_repo = $lote_repo;
        $this->item_repo = $item_repo;
    }

    public function encolar_sincronizacion( $lote_id ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            error_log( 'MegaMundo Logística: Sincronización abortada. WooCommerce no está activo.' );
            return;
        }

        $sync_status = $this->lote_repo->get_sync_status( $lote_id );
        if ( $this->lote_repo->is_synchronized( $lote_id ) || $sync_status === 'queued' || $sync_status === 'processing' ) {
            return;
        }

        $total_items = $this->item_repo->count_distinct_items( $lote_id );
        if ( $total_items === 0 ) {
            // Lote vacío: marcar completado de inmediato
            $this->lote_repo->update_sync_status( $lote_id, 'completed' );
            $this->lote_repo->mark_synchronized( $lote_id );
            $this->lote_repo->update_sync_completed_time( $lote_id );
            return;
        }

        // Inicializar metadatos de sincronización
        $this->lote_repo->update_sync_status( $lote_id, 'queued' );
        $this->lote_repo->update_sync_total_items( $lote_id, $total_items );
        $this->lote_repo->update_sync_processed_items( $lote_id, 0 );
        $this->lote_repo->update_sync_started_time( $lote_id );
        $this->lote_repo->clear_sync_errors( $lote_id );
        $this->lote_repo->clear_scheduler_fallback( $lote_id );

        $chunk_size = 50;
        $total_chunks = ceil( $total_items / $chunk_size );

        $use_action_scheduler = function_exists( 'as_enqueue_async_action' );
        if ( ! $use_action_scheduler ) {
            $this->lote_repo->set_scheduler_fallback( $lote_id );
        }

        for ( $chunk_index = 0; $chunk_index < $total_chunks; $chunk_index++ ) {
            $offset = $chunk_index * $chunk_size;
            $args = array(
                'lote_id'      => $lote_id,
                'limit'        => $chunk_size,
                'offset'       => $offset,
                'chunk_index'  => $chunk_index,
                'total_chunks' => $total_chunks
            );

            if ( $use_action_scheduler ) {
                as_enqueue_async_action( 'mm_logistica_sync_lote_chunk', array( $args ), 'mm_logistica_sync' );
            } else {
                // Fallback: usar WP-Cron con un retardo escalonado de 10 segundos por chunk
                wp_schedule_single_event( time() + ( $chunk_index * 10 ), 'mm_logistica_sync_lote_chunk', array( $args ) );
            }
        }
    }

    public function ejecutar_sync_chunk( array $args ) {
        $lote_id      = isset( $args['lote_id'] ) ? intval( $args['lote_id'] ) : 0;
        $limit        = isset( $args['limit'] ) ? intval( $args['limit'] ) : 50;
        $offset       = isset( $args['offset'] ) ? intval( $args['offset'] ) : 0;

        if ( ! $lote_id ) {
            return;
        }

        // GUARDADO DE SEGURIDAD: Si el lote ya fue completado por otro proceso, no procesar más
        $status = $this->lote_repo->get_sync_status( $lote_id );
        if ( $status === 'completed' || $status === 'completed_with_errors' ) {
            return;
        }

        // CONTROL DE INTEGRACIÓN: Asegurar que WooCommerce esté activo
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_update_product_stock' ) ) {
            return;
        }

        // Cambiar estado a procesando
        $this->lote_repo->update_sync_status( $lote_id, 'processing' );

        $items = $this->item_repo->get_chunk( $lote_id, $limit, $offset );
        if ( ! $items ) {
            $this->incrementar_y_comprobar_cierre( $lote_id, 0 );
            return;
        }

        $processed_count = 0;

        foreach ( $items as $item ) {
            $producto_id = intval( $item->producto_id );
            $sku         = $item->sku;

            if ( ! get_post( $producto_id ) || get_post_type( $producto_id ) !== 'product' ) {
                $error_item = array(
                    'producto_id' => $producto_id,
                    'sku'         => $sku,
                    'mensaje'     => sprintf( 'El producto ID %d no existe o no es válido.', $producto_id ),
                    'fecha'       => current_time( 'mysql' )
                );
                $this->lote_repo->add_sync_error( $lote_id, $error_item );
                $processed_count++;
                continue;
            }

            $cantidad = intval( $item->cantidad_contada );
            $precio   = floatval( $item->precio_propuesto );
            $has_error = false;

            // A) Actualizar el Inventario
            if ( $cantidad > 0 ) {
                $tipo_movimiento = $this->lote_repo->get_movement_type( $lote_id );
                $operacion_wc    = ( $tipo_movimiento === 'reemplazar' ) ? 'set' : 'increase';

                try {
                    $result = wc_update_product_stock( $producto_id, $cantidad, $operacion_wc );
                    if ( is_wp_error( $result ) ) {
                        $has_error = true;
                        $error_item = array(
                            'producto_id' => $producto_id,
                            'sku'         => $sku,
                            'mensaje'     => sprintf( 'Error actualizando stock: %s', $result->get_error_message() ),
                            'fecha'       => current_time( 'mysql' )
                        );
                        $this->lote_repo->add_sync_error( $lote_id, $error_item );
                    }
                } catch ( Exception $e ) {
                    $has_error = true;
                    $error_item = array(
                        'producto_id' => $producto_id,
                        'sku'         => $sku,
                        'mensaje'     => sprintf( 'Excepción actualizando stock: %s', $e->getMessage() ),
                        'fecha'       => current_time( 'mysql' )
                    );
                    $this->lote_repo->add_sync_error( $lote_id, $error_item );
                }
            }

            // B) Actualizar el Precio
            if ( $precio > 0 ) {
                update_post_meta( $producto_id, '_regular_price', $precio );
                update_post_meta( $producto_id, '_price', $precio );

                if ( function_exists( 'wc_delete_product_transients' ) ) {
                    wc_delete_product_transients( $producto_id );
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
                    $error_item = array(
                        'producto_id' => $producto_id,
                        'sku'         => $sku,
                        'mensaje'     => sprintf( 'Error publicando producto: %s', $update_result->get_error_message() ),
                        'fecha'       => current_time( 'mysql' )
                    );
                    $this->lote_repo->add_sync_error( $lote_id, $error_item );
                }
            }

            // Si no hubo errores, guardamos synced_at
            if ( ! $has_error ) {
                $this->item_repo->mark_synced( $item->id );
            }

            $processed_count++;
        }

        $this->incrementar_y_comprobar_cierre( $lote_id, $processed_count );
    }

    private function incrementar_y_comprobar_cierre( $lote_id, $count ) {
        $this->lote_repo->increment_sync_processed_items( $lote_id, $count );

        $total     = $this->lote_repo->get_sync_total_items( $lote_id );
        $processed = $this->lote_repo->get_sync_processed_items( $lote_id );

        if ( $processed >= $total ) {
            do_action( 'mm_logistica_finish_lote_sync', $lote_id );
        }
    }

    public function finalizar_sincronizacion( $lote_id ) {
        $sync_status = $this->lote_repo->get_sync_status( $lote_id );
        if ( $sync_status === 'completed' || $sync_status === 'completed_with_errors' ) {
            return;
        }

        $sync_errors = $this->lote_repo->get_sync_errors( $lote_id );

        if ( empty( $sync_errors ) ) {
            $this->lote_repo->update_sync_status( $lote_id, 'completed' );
        } else {
            $this->lote_repo->update_sync_status( $lote_id, 'completed_with_errors' );
        }
        $this->lote_repo->mark_synchronized( $lote_id );
        $this->lote_repo->update_sync_completed_time( $lote_id );
    }
}
