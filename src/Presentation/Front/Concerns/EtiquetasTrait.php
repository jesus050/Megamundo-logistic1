<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait EtiquetasTrait {
    private function get_lotes_listos_para_etiquetas() {
        $cargados = $this->lote_repo->find_by_status( 'mm_cargado' );
        $publicados = $this->lote_repo->find_by_status( 'publish' );
        $lotes = array();

        foreach ( array_merge( $cargados, $publicados ) as $lote ) {
            if ( ! isset( $lote->ID ) ) {
                continue;
            }
            $lote_id = intval( $lote->ID );
            if ( $this->lote_repo->is_synchronized( $lote_id ) || 'mm_cargado' === $this->lote_repo->get_status( $lote_id ) ) {
                $lotes[ $lote_id ] = $lote;
            }
        }

        return array_values( $lotes );
    }


    private function get_etiquetas_item_statuses( $lote_id ) {
        $raw = get_post_meta( intval( $lote_id ), '_mm_etiquetas_items_status', true );
        return is_array( $raw ) ? $raw : array();
    }

    private function get_etiqueta_item_status( $lote_id, $item_id ) {
        $statuses = $this->get_etiquetas_item_statuses( $lote_id );
        $item_id = intval( $item_id );
        return isset( $statuses[ $item_id ] ) && is_array( $statuses[ $item_id ] ) ? $statuses[ $item_id ] : array(
            'status' => 'pendiente',
            'printed_at' => '',
            'printed_by' => 0,
            'print_count' => 0,
        );
    }

    public function ajax_marcar_etiqueta_impresa_app() {
        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! $item_id || ! wp_verify_nonce( $nonce, 'mm_app_etiquetas_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para marcar etiquetas.' ), 403 );
        }

        $items = $this->item_repo ? $this->item_repo->get_by_lote( $lote_id ) : array();
        $exists = false;
        foreach ( $items as $item ) {
            if ( intval( $item->id ) === $item_id ) {
                $exists = true;
                break;
            }
        }

        if ( ! $exists ) {
            wp_send_json_error( array( 'message' => 'El producto no existe dentro de este lote.' ), 404 );
        }

        $statuses = $this->get_etiquetas_item_statuses( $lote_id );
        $prev = isset( $statuses[ $item_id ] ) && is_array( $statuses[ $item_id ] ) ? $statuses[ $item_id ] : array();
        $count = isset( $prev['print_count'] ) ? intval( $prev['print_count'] ) + 1 : 1;

        $statuses[ $item_id ] = array(
            'status'      => $count > 1 ? 'reimpreso' : 'impreso',
            'printed_at'  => current_time( 'mysql' ),
            'printed_by'  => get_current_user_id(),
            'print_count' => $count,
        );

        update_post_meta( $lote_id, '_mm_etiquetas_items_status', $statuses );

        wp_send_json_success( array(
            'message' => $count > 1 ? 'Reimpresión registrada.' : 'Producto marcado como impreso.',
            'status' => $statuses[ $item_id ]['status'],
            'print_count' => $count,
        ) );
    }

    private function render_etiqueta_card( $lote ) {
        $lote_id = intval( $lote->ID );
        $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
        $units = 0;
        foreach ( $items as $item ) {
            $units += intval( $item->cantidad_contada );
        }
        $print_url = wp_nonce_url( home_url( '/?imprimir_tickets_lote=' . $lote_id ), 'imprimir_tickets_' . $lote_id );
        $etiquetas_nonce = wp_create_nonce( 'mm_app_etiquetas_' . $lote_id );
        $status_items = $this->get_etiquetas_item_statuses( $lote_id );
        $printed_count = 0;
        foreach ( $items as $status_item ) {
            $tmp_status = $this->get_etiqueta_item_status( $lote_id, intval( $status_item->id ) );
            if ( ! empty( $tmp_status['status'] ) && 'pendiente' !== $tmp_status['status'] ) {
                $printed_count++;
            }
        }
        $detail_url = home_url( '/?mm_logistica_app=jefatura&lote_id=' . $lote_id );
        if ( ! $this->permission_guard->can_access_jefatura_panel() ) {
            $detail_url = home_url( '/?mm_logistica_app=precios&lote_id=' . $lote_id );
        }
        ob_start(); ?>
        <article class="mm-platform-lote-card mm-label-lote-card">
            <div class="mm-lote-card-top">
                <span class="mm-badge-soft">Listo para imprimir</span>
                <small>Lote #<?php echo esc_html( $lote_id ); ?></small>
            </div>
            <h3><?php echo esc_html( get_the_title( $lote_id ) ); ?></h3>
            <div class="mm-lote-card-metrics">
                <span><strong><?php echo esc_html( count( $items ) ); ?></strong><small>SKUs</small></span>
                <span><strong><?php echo esc_html( $units ); ?></strong><small>Etiquetas aprox.</small></span>
                <span><strong><?php echo esc_html( $this->status_label( $this->lote_repo->get_status( $lote_id ) ) ); ?></strong><small>Estado</small></span>
                <span><strong><?php echo esc_html( $printed_count ); ?>/<?php echo esc_html( count( $items ) ); ?></strong><small>Productos impresos</small></span>
            </div>
            <div class="mm-lote-card-actions">
                <a class="mm-mini-primary" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener">Imprimir lote completo</a>
                <a class="mm-mini-secondary" href="<?php echo esc_url( $detail_url ); ?>">Revisar lote</a>
            </div>

            <?php if ( ! empty( $items ) ) : ?>
                <div class="mm-product-print-list">
                    <h4>Imprimir producto por producto</h4>
                    <?php foreach ( $items as $item ) :
                        $product_name = $item->producto_id ? get_the_title( (int) $item->producto_id ) : '';
                        if ( ! $product_name ) { $product_name = 'Producto sin nombre'; }
                        $qty = intval( $item->cantidad_contada );
                        $item_print_url = wp_nonce_url( home_url( '/?imprimir_tickets_lote=' . $lote_id . '&item_id=' . intval( $item->id ) ), 'imprimir_tickets_' . $lote_id );
                    ?>
                        <?php
                            $print_status = $this->get_etiqueta_item_status( $lote_id, intval( $item->id ) );
                            $status_label = ! empty( $print_status['status'] ) ? $print_status['status'] : 'pendiente';
                            $print_count = ! empty( $print_status['print_count'] ) ? intval( $print_status['print_count'] ) : 0;
                        ?>
                        <div class="mm-product-print-row" data-lote-id="<?php echo esc_attr( $lote_id ); ?>" data-item-id="<?php echo esc_attr( (int) $item->id ); ?>" data-nonce="<?php echo esc_attr( $etiquetas_nonce ); ?>">
                            <div>
                                <strong><?php echo esc_html( $product_name ); ?></strong>
                                <small>SKU: <?php echo esc_html( $item->sku ); ?> · <?php echo esc_html( $qty ); ?> etiqueta(s)</small>
                                <span class="mm-print-status mm-print-status-<?php echo esc_attr( $status_label ); ?>">
                                    <?php echo esc_html( ucfirst( $status_label ) ); ?><?php echo $print_count > 1 ? ' · ' . esc_html( $print_count ) . ' veces' : ''; ?>
                                </span>
                            </div>
                            <div class="mm-product-print-actions">
                                <a class="mm-mini-secondary" href="<?php echo esc_url( $item_print_url ); ?>" target="_blank" rel="noopener"><?php echo 'pendiente' === $status_label ? 'Imprimir todo este producto' : 'Reimprimir todo'; ?></a>
                                <div class="mm-custom-print">
                                    <input type="number" min="1" max="<?php echo esc_attr( max( 1, $qty ) ); ?>" value="<?php echo esc_attr( $qty ); ?>" class="mm-custom-print-qty" aria-label="Cantidad personalizada de etiquetas">
                                    <button type="button" class="mm-mini-secondary mm-btn-print-custom" data-print-base="<?php echo esc_url( $item_print_url ); ?>">Imprimir cantidad</button>
                                </div>
                                <button type="button" class="mm-mini-primary mm-btn-marcar-impreso"><?php echo 'pendiente' === $status_label ? 'Marcar impreso' : 'Registrar reimpresión'; ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php return ob_get_clean();
    }

    private function render_etiquetas_dashboard() {
        $lotes = $this->get_lotes_listos_para_etiquetas();
        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'etiquetas' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header">
                    <div>
                        <span class="mm-eyebrow">Panel de Etiquetas</span>
                        <h1>Impresión de tickets y etiquetas</h1>
                        <p>Cuando jefatura aprueba un lote, el sistema lo carga a WooCommerce y aquí queda listo para imprimir etiquetas con el precio final.</p>
                    </div>
                </header>
                <div class="mm-role-summary-grid">
                    <div class="mm-role-summary-card is-success"><small>Lotes listos</small><strong><?php echo esc_html( count( $lotes ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Regla</small><strong>Solo cargados</strong></div>
                    <div class="mm-role-summary-card"><small>Precio usado</small><strong>Precio final</strong></div>
                </div>
                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Lotes listos para impresión</h2>
                        <span>Solo aparecen lotes aprobados/cargados o sincronizados. No se imprimen etiquetas antes de aprobación.</span>
                    </div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $lotes ) ) : ?>
                            <div class="mm-empty-state">Aún no hay lotes aprobados listos para imprimir etiquetas.</div>
                        <?php endif; ?>
                        <?php foreach ( $lotes as $lote ) { echo $this->render_etiqueta_card( $lote ); } ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }
}
