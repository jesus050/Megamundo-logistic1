<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait MekanoTrait {
    public function ajax_exportar_mekano_lote() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_jefatura_panel() && ! $this->permission_guard->can_access_precios_panel() ) ) { wp_die( 'No tienes permiso para exportar a Mekano.', 403 ); }
            $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
            $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
            if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_exportar_mekano_' . $lote_id ) ) { wp_die( 'Acceso no autorizado.', 403 ); }
            $content = $this->mekano_service->build_mekano_csv_content( $lote_id );
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="mekano-lote-' . $lote_id . '-' . date( 'Ymd-His' ) . '.csv"' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
            echo $content;
            exit;
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error exportando Mekano CSV: ' . $e->getMessage() );
            wp_die( 'No se pudo generar el archivo Mekano.', 500 );
        }
    }



    public function ajax_exportar_mekano_xlsx_lote() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_jefatura_panel() && ! $this->permission_guard->can_access_precios_panel() ) ) {
                wp_die( 'No tienes permiso para exportar a Mekano.', 403 );
            }

            $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
            $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

            if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_exportar_mekano_' . $lote_id ) ) {
                wp_die( 'Acceso no autorizado.', 403 );
            }

            if ( ! $this->lote_repo->find( $lote_id ) ) {
                wp_die( 'Lote no encontrado.', 404 );
            }

            $file = $this->mekano_service->build_mekano_xlsx_from_template( $lote_id );
            if ( is_wp_error( $file ) ) {
                wp_die( esc_html( $file->get_error_message() ), 500 );
            }

            if ( ! file_exists( $file ) ) {
                wp_die( 'El archivo XLSX se generó, pero no se encontró en el servidor.', 500 );
            }

            $filename = basename( $file );

            while ( ob_get_level() ) {
                ob_end_clean();
            }

            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Content-Length: ' . filesize( $file ) );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );

            readfile( $file );
            exit;
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error exportando XLSX Mekano: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine() );
            $message = current_user_can( 'manage_options' )
                ? 'Error generando XLSX: ' . $e->getMessage()
                : 'No se pudo generar el XLSX de Mekano.';
            wp_die( esc_html( $message ), 500 );
        }
    }


    private function render_mekano_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para exportar información hacia Mekano.' );
        }

        $lotes = $this->mekano_service->get_mekano_lotes_disponibles();
        $lote_id = $this->mekano_service->get_mekano_lote_actual();
        $items = $lote_id && $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
        $nonce = $lote_id ? wp_create_nonce( 'mm_exportar_mekano_' . $lote_id ) : '';
        $export_url = $lote_id ? admin_url( 'admin-ajax.php?action=mm_app_exportar_mekano_lote&lote_id=' . $lote_id . '&nonce=' . $nonce ) : '';
        $export_xlsx_url = $lote_id ? admin_url( 'admin-ajax.php?action=mm_app_exportar_mekano_xlsx_lote&lote_id=' . $lote_id . '&nonce=' . $nonce ) : '';

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'mekano' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Exportación Mekano</span>
                        <h1>Plantilla de referencias</h1>
                        <p>Genera el archivo usando exactamente la plantilla oficial de Mekano.</p>
                    </div>
                    <?php if ( $export_xlsx_url ) : ?>
                        <div class="mm-mekano-actions">
                            <a class="mm-secondary-action" href="<?php echo esc_url( $export_xlsx_url ); ?>">Descargar XLSX exacto</a>
                            <a class="mm-mini-secondary" href="<?php echo esc_url( $export_url ); ?>">Descargar CSV</a>
                        </div>
                    <?php endif; ?>
                </header>

                <section class="mm-report-filter-card">
                    <div>
                        <h2>Seleccionar lote</h2>
                        <p>El XLSX conserva el documento original. Solo se llenan datos desde la fila 8 en la hoja PLANTILLA.</p>
                    </div>
                    <form class="mm-report-date-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <input type="hidden" name="mm_logistica_app" value="mekano">
                        <label>Lote
                            <select name="lote_id" class="mm-input">
                                <?php foreach ( $lotes as $lote ) : ?>
                                    <option value="<?php echo esc_attr( $lote->ID ); ?>" <?php selected( $lote_id, $lote->ID ); ?>>
                                        #<?php echo esc_html( $lote->ID ); ?> — <?php echo esc_html( get_the_title( $lote->ID ) ); ?> / <?php echo esc_html( $this->status_label( $this->lote_repo->get_status( $lote->ID ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="mm-mini-primary">Ver lote</button>
                    </form>
                </section>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Lote</small><strong>#<?php echo esc_html( $lote_id ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Referencias</small><strong><?php echo esc_html( count( $items ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Formato principal</small><strong>XLSX</strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Plantilla</small><strong>Oficial</strong></div>
                </div>

                
                <?php if ( $export_xlsx_url ) : ?>
                    <section class="mm-platform-section mm-mekano-download-panel">
                        <div class="mm-section-head">
                            <h2>Exportar archivo Mekano</h2>
                            <span>Descarga la plantilla exacta con los productos del lote seleccionado.</span>
                        </div>
                        <div class="mm-mekano-download-actions">
                            <a class="mm-secondary-action" href="<?php echo esc_url( $export_xlsx_url ); ?>">Descargar XLSX exacto</a>
                            <a class="mm-mini-secondary" href="<?php echo esc_url( $export_url ); ?>">Descargar CSV</a>
                        </div>
                    </section>
                <?php endif; ?>

<section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Vista previa</h2>
                        <span>Primeras referencias del lote seleccionado.</span>
                    </div>
                    <div class="mm-mekano-preview-wrap">
                        <table class="mm-mekano-preview-table">
                            <thead><tr><th>CODIGO</th><th>NOMBRE</th><th>LINEA</th><th>UND</th><th>COSTO</th><th>PRECIO</th><th>CATEGORIA</th></tr></thead>
                            <tbody>
                                <?php if ( empty( $items ) ) : ?><tr><td colspan="7">No hay productos para exportar en este lote.</td></tr><?php endif; ?>
                                <?php foreach ( array_slice( $items, 0, 20 ) as $item ) : $v = $this->get_item_mekano_values( $lote_id, $item ); ?>
                                    <tr>
                                        <td><?php echo esc_html( $v['codigo'] ); ?></td>
                                        <td><?php echo esc_html( $v['nombre'] ); ?></td>
                                        <td><?php echo esc_html( $v['linea'] ); ?></td>
                                        <td><?php echo esc_html( $v['unidad'] ); ?></td>
                                        <td>$<?php echo esc_html( number_format( $v['costo'], 0, ',', '.' ) ); ?></td>
                                        <td>$<?php echo esc_html( number_format( $v['precio'], 0, ',', '.' ) ); ?></td>
                                        <td><?php echo esc_html( $v['categoria'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head"><h2>Plantilla exacta</h2><span>Se conserva el documento original.</span></div>
                    <div class="mm-mekano-note-actions">
                        <?php if ( $export_xlsx_url ) : ?>
                            <a class="mm-secondary-action" href="<?php echo esc_url( $export_xlsx_url ); ?>">Descargar XLSX exacto</a>
                            <a class="mm-mini-secondary" href="<?php echo esc_url( $export_url ); ?>">Descargar CSV</a>
                        <?php endif; ?>
                    </div>
                    <div class="mm-safe-note">
                        <strong>Importante:</strong>
                        <p>El exportador usa el XLSX oficial como base. Solo reemplaza datos desde la fila 8 en columnas A:W y conserva hojas auxiliares, formatos y estructura. La columna LINEA queda en blanco por ahora hasta confirmar su uso en Mekano. Si el servidor no permite XLSX, usa CSV como respaldo.</p>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }
}
