<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait JefaturaTrait {
    private function get_lotes_jefatura_en_proceso() {
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( ! isset( $lote->ID ) ) {
                    continue;
                }

                $lote_id = intval( $lote->ID );

                // Si ya fue sincronizado, no debe aparecer como pendiente/en proceso.
                if ( $this->lote_repo->is_synchronized( $lote_id ) || 'mm_cargado' === $this->lote_repo->get_status( $lote_id ) ) {
                    continue;
                }

                $lotes[ $lote_id ] = $lote;
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_lotes_jefatura_por_aprobar() {
        $lotes = array();

        foreach ( $this->lote_repo->find_by_status( 'mm_p_aprobacion' ) as $lote ) {
            if ( ! isset( $lote->ID ) ) {
                continue;
            }

            $lote_id = intval( $lote->ID );
            if ( ! $this->lote_repo->is_synchronized( $lote_id ) ) {
                $lotes[ $lote_id ] = $lote;
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function render_jefatura_dashboard() {
        $counts = $this->get_lote_counts();
        $pendientes = $this->get_lotes_jefatura_por_aprobar();
        $en_proceso = $this->get_lotes_jefatura_en_proceso();
        $cargados   = array_slice( $this->lote_repo->find_by_status( 'mm_cargado' ), 0, 6 );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'jefatura' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-jefatura-hero">
                    <div>
                        <span class="mm-eyebrow">Panel de Jefatura</span>
                        <h1>Control logístico general</h1>
                        <p>Supervisa todos los lotes activos: en conteo, precios, pendientes de aprobación y cargados.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>En conteo</small><strong><?php echo esc_html( $counts['draft'] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>En precios</small><strong><?php echo esc_html( $counts['mm_p_precios'] ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Por aprobar</small><strong><?php echo esc_html( count( $pendientes ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Cargados</small><strong><?php echo esc_html( $counts['mm_cargado'] ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Pendientes de aprobación</h2>
                        <span>Aquí aparecen los lotes que Precios ya envió para aprobación final.</span>
                    </div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $pendientes ) ) : ?><div class="mm-empty-state">No hay lotes pendientes de aprobación.</div><?php endif; ?>
                        <?php foreach ( $pendientes as $lote ) { echo $this->render_lote_card( $lote, 'jefatura' ); } ?>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Todos los lotes en proceso</h2>
                        <span>Vista de control para jefatura: borrador, publicados, en precios y por aprobar.</span>
                    </div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $en_proceso ) ) : ?><div class="mm-empty-state">No hay lotes activos en proceso.</div><?php endif; ?>
                        <?php foreach ( $en_proceso as $lote ) { echo $this->render_lote_card( $lote, 'jefatura' ); } ?>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Últimos lotes cargados</h2>
                        <span>Consulta etiquetas, CSV, bitácora y sincronización.</span>
                    </div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $cargados ) ) : ?><div class="mm-empty-state">Aún no hay lotes cargados.</div><?php endif; ?>
                        <?php foreach ( $cargados as $lote ) { echo $this->render_lote_card( $lote, 'jefatura' ); } ?>
                    </div>
                </section>
            </section>
        </main>
                        <?php
                $selected_lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
                if ( $selected_lote_id ) {
                    echo $this->render_multi_prices_panel( $selected_lote_id );
                }
                ?>
        <?php return ob_get_clean();
    }

    public function ajax_aprobar_cargar_app() {
        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_app_precios_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        if ( ! $this->permission_guard->is_admin() ) {
            wp_send_json_error( array( 'message' => 'Solo jefatura puede aprobar y cargar el lote.' ), 403 );
        }

        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            wp_send_json_error( array( 'message' => 'El lote no existe.' ), 404 );
        }

        $status = $this->lote_repo->get_status( $lote_id );
        if ( ! in_array( $status, array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Este lote no está disponible para autorización de precio.' ), 400 );
        }

        if ( $this->lote_repo->is_synchronized( $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Este lote ya fue cargado anteriormente.' ), 400 );
        }

        if ( $this->item_repo->count_distinct_items( $lote_id ) === 0 ) {
            wp_send_json_error( array( 'message' => 'No puedes aprobar un lote sin productos.' ), 400 );
        }

        $sin_precio = $this->item_repo->count_items_without_price( $lote_id );
        if ( $sin_precio > 0 ) {
            wp_send_json_error( array( 'message' => 'Faltan precios en ' . $sin_precio . ' producto(s).' ), 400 );
        }

        // Primero guarda cualquier precio final ajustado por jefatura.
        if ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
            foreach ( $_POST['items'] as $item_id => $values ) {
                $item_id = absint( $item_id );
                $costo  = isset( $values['costo_ia'] ) ? max( 0, floatval( $values['costo_ia'] ) ) : 0;
                $precio = isset( $values['precio_propuesto'] ) ? max( 0, floatval( $values['precio_propuesto'] ) ) : 0;
                if ( $item_id > 0 ) {
                    $this->item_repo->update_prices( $item_id, $lote_id, $costo, $precio );
                }
            }
        }

        update_post_meta( $lote_id, '_mm_jefatura_aprobado_at', current_time( 'mysql' ) );
        update_post_meta( $lote_id, '_mm_jefatura_aprobado_by', get_current_user_id() );

        $updated = $this->lote_repo->update_status( $lote_id, 'mm_cargado' );
        if ( ! $updated ) {
            wp_send_json_error( array( 'message' => 'No se pudo cambiar el lote a cargado.' ), 500 );
        }

        wp_send_json_success( array(
            'message' => 'Precio autorizado y lote enviado a carga. Cuando termine la sincronización aparecerá en Etiquetas.',
            'redirect' => home_url( '/?mm_logistica_app=etiquetas' ),
        ) );
    }
}
