<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_Sync {

    public function __construct() {
        // Este hook se dispara cada vez que un post cambia de estado
        add_action( 'transition_post_status', array( $this, 'procesar_ingreso_oficial' ), 10, 3 );

        // Registramos el hook para procesar un chunk (Action Scheduler / WP-Cron)
        add_action( 'mm_logistica_sync_lote_chunk', array( $this, 'ejecutar_sync_chunk' ), 10, 1 );

        // Registramos el hook para finalizar la sincronización
        add_action( 'mm_logistica_finish_lote_sync', array( $this, 'finalizar_sincronizacion' ), 10, 1 );
    }

    public function procesar_ingreso_oficial( $new_status, $old_status, $post ) {
        // 1. Filtros de seguridad: Solo actuar si es un Lote de Ingreso
        if ( $post->post_type !== 'lotes_ingreso' ) {
            return;
        }

        // Si ya estaba cargado o el nuevo estado no es "mm_cargado", no hacemos nada
        if ( $new_status !== 'mm_cargado' || $old_status === 'mm_cargado' ) {
            return;
        }

        // CONTROL DE INTEGRACIÓN: Asegurar que WooCommerce esté activo
        if ( ! class_exists( 'WooCommerce' ) ) {
            error_log( 'MegaMundo Logística: Sincronización abortada. WooCommerce no está activo.' );
            return;
        }

        // CONTROL DE DUPLICIDAD Y COLA: Evitar duplicar colas de sincronización
        $sync_status = get_post_meta( $post->ID, '_mm_sync_status', true );
        if ( get_post_meta( $post->ID, '_sincronizado_wc', true ) || $sync_status === 'queued' || $sync_status === 'processing' ) {
            return;
        }

        global $wpdb;
        $tabla = $wpdb->prefix . 'mm_lote_items';
        $total_items = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tabla WHERE lote_id = %d", $post->ID ) ) );

        if ( $total_items === 0 ) {
            // Lote vacío: marcar completado de inmediato
            update_post_meta( $post->ID, '_mm_sync_status', 'completed' );
            update_post_meta( $post->ID, '_sincronizado_wc', current_time( 'mysql' ) );
            update_post_meta( $post->ID, '_mm_sync_completed_at', current_time( 'mysql' ) );
            return;
        }

        // Inicializar metadatos de sincronización
        update_post_meta( $post->ID, '_mm_sync_status', 'queued' );
        update_post_meta( $post->ID, '_mm_sync_total_items', $total_items );
        update_post_meta( $post->ID, '_mm_sync_processed_items', 0 );
        update_post_meta( $post->ID, '_mm_sync_started_at', current_time( 'mysql' ) );
        delete_post_meta( $post->ID, '_sync_errors' ); // Limpiar errores previos
        delete_post_meta( $post->ID, '_mm_sync_scheduler_fallback' );

        $chunk_size = 50;
        $total_chunks = ceil( $total_items / $chunk_size );

        $use_action_scheduler = function_exists( 'as_enqueue_async_action' );
        if ( ! $use_action_scheduler ) {
            update_post_meta( $post->ID, '_mm_sync_scheduler_fallback', '1' );
        }

        for ( $chunk_index = 0; $chunk_index < $total_chunks; $chunk_index++ ) {
            $offset = $chunk_index * $chunk_size;
            $args = array(
                'lote_id'      => $post->ID,
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

    public function ejecutar_sync_chunk( $args ) {
        $lote_id      = isset( $args['lote_id'] ) ? intval( $args['lote_id'] ) : 0;
        $limit        = isset( $args['limit'] ) ? intval( $args['limit'] ) : 50;
        $offset       = isset( $args['offset'] ) ? intval( $args['offset'] ) : 0;
        $chunk_index  = isset( $args['chunk_index'] ) ? intval( $args['chunk_index'] ) : 0;
        $total_chunks = isset( $args['total_chunks'] ) ? intval( $args['total_chunks'] ) : 1;

        if ( ! $lote_id ) {
            return;
        }

        // GUARDADO DE SEGURIDAD: Si el lote ya fue completado por otro proceso, no procesar más
        $status = get_post_meta( $lote_id, '_mm_sync_status', true );
        if ( $status === 'completed' || $status === 'completed_with_errors' ) {
            return;
        }

        // CONTROL DE INTEGRACIÓN: Asegurar que WooCommerce esté activo
        if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_update_product_stock' ) ) {
            return;
        }

        // Cambiar estado a procesando
        update_post_meta( $lote_id, '_mm_sync_status', 'processing' );

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
                add_post_meta( $lote_id, '_sync_errors', $error_item, false );
                $processed_count++;
                continue;
            }

            $cantidad = intval( $item->cantidad_contada );
            $precio   = floatval( $item->precio_propuesto );
            $has_error = false;

            // A) Actualizar el Inventario
            if ( $cantidad > 0 ) {
                $tipo_movimiento = get_post_meta( $lote_id, '_mm_tipo_movimiento', true ) ?: 'sumar';
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
                        add_post_meta( $lote_id, '_sync_errors', $error_item, false );
                    }
                } catch ( Exception $e ) {
                    $has_error = true;
                    $error_item = array(
                        'producto_id' => $producto_id,
                        'sku'         => $sku,
                        'mensaje'     => sprintf( 'Excepción actualizando stock: %s', $e->getMessage() ),
                        'fecha'       => current_time( 'mysql' )
                    );
                    add_post_meta( $lote_id, '_sync_errors', $error_item, false );
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
                    add_post_meta( $lote_id, '_sync_errors', $error_item, false );
                }
            }

            // Si no hubo errores, guardamos synced_at
            if ( ! $has_error ) {
                $wpdb->update(
                    $tabla,
                    array( 'synced_at' => current_time( 'mysql' ) ),
                    array( 'id' => $item->id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }

            $processed_count++;
        }

        $this->incrementar_y_comprobar_cierre( $lote_id, $processed_count );
    }

    private function incrementar_y_comprobar_cierre( $lote_id, $count ) {
        global $wpdb;

        // Incremento atómico para evitar race conditions
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
        // Evitar doble finalización
        $sync_status = get_post_meta( $lote_id, '_mm_sync_status', true );
        if ( $sync_status === 'completed' || $sync_status === 'completed_with_errors' ) {
            return;
        }

        $sync_errors = get_post_meta( $lote_id, '_sync_errors', false );

        if ( empty( $sync_errors ) ) {
            update_post_meta( $lote_id, '_mm_sync_status', 'completed' );
            update_post_meta( $lote_id, '_sincronizado_wc', current_time( 'mysql' ) );
        } else {
            update_post_meta( $lote_id, '_mm_sync_status', 'completed_with_errors' );
            update_post_meta( $lote_id, '_sincronizado_wc', current_time( 'mysql' ) );
        }
        update_post_meta( $lote_id, '_mm_sync_completed_at', current_time( 'mysql' ) );
    }
}

new MM_Logistica_Sync();
