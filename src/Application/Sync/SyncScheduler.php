<?php
namespace MegaMundo\Logistica\Application\Sync;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Domain\Lote\LoteStatuses;
use MegaMundo\Logistica\Domain\Lote\LoteAuditRepository;

class SyncScheduler {

    private $lote_repo;
    private $item_repo;
    private $audit_repo;

    public function __construct( LoteRepository $lote_repo, LoteItemRepository $item_repo, LoteAuditRepository $audit_repo ) {
        $this->lote_repo  = $lote_repo;
        $this->item_repo  = $item_repo;
        $this->audit_repo = $audit_repo;
    }

    public function hook() {
        add_action( 'transition_post_status', array( $this, 'procesar_ingreso_oficial' ), 10, 3 );
    }

    public function procesar_ingreso_oficial( $new_status, $old_status, $post ) {
        if ( $post->post_type !== 'lotes_ingreso' ) {
            return;
        }

        if ( $new_status !== LoteStatuses::LOADED || $old_status === LoteStatuses::LOADED ) {
            return;
        }

        if ( ! class_exists( 'WooCommerce' ) ) {
            error_log( 'MegaMundo Logística: Sincronización abortada. WooCommerce no está activo.' );
            return;
        }

        $sync_meta = $this->lote_repo->get_sync_meta( $post->ID );
        $sync_status = $sync_meta['status'];
        if ( $this->lote_repo->is_synchronized( $post->ID ) || $sync_status === 'queued' || $sync_status === 'processing' ) {
            return;
        }

        $total_items = $this->item_repo->count_distinct_items( $post->ID );

        if ( $total_items === 0 ) {
            $this->lote_repo->update_sync_meta( $post->ID, 'status', 'completed' );
            $this->lote_repo->mark_as_synchronized( $post->ID );
            $this->lote_repo->update_sync_meta( $post->ID, 'completed_at', current_time( 'mysql' ) );
            return;
        }

        // Inicializar metadatos
        $this->lote_repo->update_sync_meta( $post->ID, 'status', 'queued' );
        $this->lote_repo->update_sync_meta( $post->ID, 'total_items', $total_items );
        $this->lote_repo->update_sync_meta( $post->ID, 'processed_items', 0 );
        $this->lote_repo->update_sync_meta( $post->ID, 'started_at', current_time( 'mysql' ) );
        $this->lote_repo->clear_sync_errors( $post->ID );
        $this->lote_repo->update_sync_meta( $post->ID, 'scheduler_fallback', '' );

        // Registrar inicio de sincronización en auditoría
        $this->audit_repo->add_log(
            $post->ID,
            'sync_iniciada',
            sprintf( 'Sincronización asíncrona iniciada para %d productos únicos.', $total_items )
        );

        $chunk_size = 50;
        $total_chunks = ceil( $total_items / $chunk_size );

        $use_action_scheduler = function_exists( 'as_enqueue_async_action' );
        if ( ! $use_action_scheduler ) {
            $this->lote_repo->update_sync_meta( $post->ID, 'scheduler_fallback', '1' );
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
                wp_schedule_single_event( time() + ( $chunk_index * 10 ), 'mm_logistica_sync_lote_chunk', array( $args ) );
            }
        }
    }
}
