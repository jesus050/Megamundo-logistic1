<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait SincronizacionTrait {
    private function get_lotes_para_sincronizacion_dashboard() {
        $statuses = array( 'mm_cargado', 'publish', 'mm_p_aprobacion' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( isset( $lote->ID ) ) {
                    $lotes[ intval( $lote->ID ) ] = $lote;
                }
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_lote_sync_summary( $lote_id ) {
        $lote_id = intval( $lote_id );
        $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

        $summary = array(
            'total'      => count( $items ),
            'ok'         => 0,
            'error'      => 0,
            'pendiente'  => 0,
            'sync_meta'  => $this->lote_repo->get_sync_meta( $lote_id ),
            'items'      => array(),
        );

        foreach ( $items as $item ) {
            $status = 'pendiente';
            $error  = '';

            $synced_at = property_exists( $item, 'synced_at' ) ? $item->synced_at : '';
            $sync_error = property_exists( $item, 'sync_error' ) ? $item->sync_error : '';

            if ( ! empty( $synced_at ) ) {
                $status = 'ok';
                $summary['ok']++;
            } elseif ( ! empty( $sync_error ) ) {
                $status = 'error';
                $error = $sync_error;
                $summary['error']++;
            } else {
                $summary['pendiente']++;
            }

            $summary['items'][] = array(
                'id'           => intval( $item->id ),
                'sku'          => $item->sku,
                'producto_id'  => intval( $item->producto_id ),
                'nombre'       => intval( $item->producto_id ) ? get_the_title( intval( $item->producto_id ) ) : 'Producto sin nombre',
                'cantidad'     => intval( $item->cantidad_contada ),
                'precio'       => floatval( $item->precio_propuesto ),
                'synced_at'    => $synced_at,
                'status'       => $status,
                'error'        => $error,
            );
        }

        return $summary;
    }

    public function ajax_reintentar_sincronizacion_lote() {
        if ( ! is_user_logged_in() || ! $this->permission_guard->can_access_jefatura_panel() ) {
            wp_send_json_error( array( 'message' => 'Solo jefatura puede reintentar sincronización.' ), 403 );
        }

        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_reintentar_sync_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            wp_send_json_error( array( 'message' => 'Lote no encontrado.' ), 404 );
        }

        delete_post_meta( $lote_id, '_sincronizado_wc' );
        $this->lote_repo->update_sync_status( $lote_id, 'pendiente' );
        update_post_meta( $lote_id, '_mm_sync_retry_requested_at', current_time( 'mysql' ) );
        update_post_meta( $lote_id, '_mm_sync_retry_requested_by', get_current_user_id() );

        $this->lote_repo->update_status( $lote_id, 'mm_cargado' );

        wp_send_json_success( array(
            'message' => 'Reintento solicitado. Revisa nuevamente el estado en unos minutos.',
        ) );
    }

    private function render_sincronizacion_dashboard() {
        if ( ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'Solo jefatura puede ver el control de sincronización.' );
        }

        $lotes = $this->get_lotes_para_sincronizacion_dashboard();
        $total_lotes = count( $lotes );
        $total_ok = 0;
        $total_error = 0;
        $total_pending = 0;

        foreach ( $lotes as $lote ) {
            $summary = $this->get_lote_sync_summary( intval( $lote->ID ) );
            $total_ok += $summary['ok'];
            $total_error += $summary['error'];
            $total_pending += $summary['pendiente'];
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'sincronizacion' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Control de sincronización</span>
                        <h1>Carga a WooCommerce</h1>
                        <p>Verifica qué productos cargaron correctamente, cuáles quedaron pendientes y cuáles tuvieron error.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Lotes revisados</small><strong><?php echo esc_html( $total_lotes ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Productos OK</small><strong><?php echo esc_html( $total_ok ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Pendientes</small><strong><?php echo esc_html( $total_pending ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Con error</small><strong><?php echo esc_html( $total_error ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Estado por lote</h2>
                        <span>Reintenta solo si hay errores o productos pendientes después de cargar.</span>
                    </div>

                    <div class="mm-sync-lote-list">
                        <?php if ( empty( $lotes ) ) : ?>
                            <div class="mm-empty-state">No hay lotes cargados o en proceso de sincronización.</div>
                        <?php endif; ?>

                        <?php foreach ( $lotes as $lote ) :
                            $lote_id = intval( $lote->ID );
                            $summary = $this->get_lote_sync_summary( $lote_id );
                            $nonce = wp_create_nonce( 'mm_reintentar_sync_' . $lote_id );
                            $sync_status = $summary['sync_meta']['status'] ?? 'pendiente';
                        ?>
                            <article class="mm-sync-card" data-lote-id="<?php echo esc_attr( $lote_id ); ?>">
                                <div class="mm-sync-card-head">
                                    <div>
                                        <span class="mm-badge-soft">Lote #<?php echo esc_html( $lote_id ); ?></span>
                                        <h3><?php echo esc_html( get_the_title( $lote_id ) ); ?></h3>
                                        <p>Estado general: <strong><?php echo esc_html( $sync_status ); ?></strong></p>
                                    </div>
                                    <div class="mm-sync-actions">
                                        <a class="mm-mini-secondary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=jefatura&lote_id=' . $lote_id ) ); ?>">Ver lote</a>
                                        <button type="button" class="mm-mini-primary mm-btn-retry-sync" data-lote-id="<?php echo esc_attr( $lote_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Reintentar carga</button>
                                    </div>
                                </div>

                                <div class="mm-sync-metrics">
                                    <span><strong><?php echo esc_html( $summary['total'] ); ?></strong><small>Total</small></span>
                                    <span class="is-ok"><strong><?php echo esc_html( $summary['ok'] ); ?></strong><small>OK</small></span>
                                    <span class="is-pending"><strong><?php echo esc_html( $summary['pendiente'] ); ?></strong><small>Pendientes</small></span>
                                    <span class="is-error"><strong><?php echo esc_html( $summary['error'] ); ?></strong><small>Errores</small></span>
                                </div>

                                <div class="mm-sync-table-wrap">
                                    <table class="mm-sync-table">
                                        <thead>
                                            <tr>
                                                <th>Estado</th>
                                                <th>SKU</th>
                                                <th>Producto</th>
                                                <th>Cant.</th>
                                                <th>Precio</th>
                                                <th>Detalle</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $summary['items'] as $item ) : ?>
                                                <tr>
                                                    <td><span class="mm-sync-status mm-sync-<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( strtoupper( $item['status'] ) ); ?></span></td>
                                                    <td><?php echo esc_html( $item['sku'] ); ?></td>
                                                    <td><?php echo esc_html( $item['nombre'] ); ?></td>
                                                    <td><?php echo esc_html( $item['cantidad'] ); ?></td>
                                                    <td>$<?php echo esc_html( number_format( $item['precio'], 0, ',', '.' ) ); ?></td>
                                                    <td><?php echo $item['error'] ? esc_html( $item['error'] ) : ( $item['synced_at'] ? 'Sincronizado: ' . esc_html( $item['synced_at'] ) : 'Pendiente de carga' ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mm-sync-message" hidden></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }
}
