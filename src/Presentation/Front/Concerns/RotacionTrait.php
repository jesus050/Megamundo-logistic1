<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Sales\SalesRepository;
use MegaMundo\Logistica\Application\Inventory\MekanoSalesImporter;
use MegaMundo\Logistica\Application\Inventory\SalesRotationReport;

/**
 * Módulo "Rotación": importa reportes de ventas de Mekano (CSV) con vista
 * previa anti-duplicado y muestra qué mercancía no rota (días sin venta).
 */
trait RotacionTrait {

    private function sales_repo() {
        static $r = null;
        return $r ?: $r = new SalesRepository();
    }
    private function sales_importer() {
        static $r = null;
        return $r ?: $r = new MekanoSalesImporter();
    }
    private function sales_report() {
        static $r = null;
        return $r ?: $r = new SalesRotationReport();
    }

    private function can_access_rotacion_panel() {
        return $this->permission_guard->can_access_jefatura_panel() || $this->permission_guard->is_admin();
    }

    public function render_rotacion_dashboard() {
        if ( ! $this->can_access_rotacion_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para ver el módulo de rotación.' );
        }

        $rows   = $this->sales_repo()->get_rotation_rows();
        $report = $this->sales_report()->build( $rows );
        $sum    = $report['summary'];
        $items  = array_slice( $report['items'], 0, 300 );
        $nonce  = wp_create_nonce( 'mm_rotacion_import' );
        $tiene_datos = ! empty( $rows );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'rotacion' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Análisis</span>
                        <h1>Rotación de mercancía</h1>
                        <p>Qué productos no se están vendiendo, según tus reportes de ventas de Mekano.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4" style="margin-bottom:18px;">
                    <div class="mm-role-summary-card"><small>No rota (≥90 días)</small><strong><?php echo esc_html( $sum[ SalesRotationReport::NO_ROTA ] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Lento (45–90 días)</small><strong><?php echo esc_html( $sum[ SalesRotationReport::LENTO ] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Rotando bien</small><strong><?php echo esc_html( $sum[ SalesRotationReport::OK ] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Productos con ventas</small><strong><?php echo esc_html( $sum['total'] ); ?></strong></div>
                </div>

                <div class="mm-platform-section" style="margin-bottom:18px;">
                    <h2 class="mm-section-head">Importar reporte de ventas</h2>
                    <p class="mm-safe-note">En Mekano/Excel: <strong>Guardar como → CSV</strong>, luego súbelo aquí. Si subes una fecha ya cargada, se reemplaza (no se duplica).</p>
                    <form id="mm-rotacion-form" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:10px;">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                        <input class="mm-input" type="file" name="archivo" accept=".csv,text/csv" required style="max-width:340px;">
                        <button type="submit" class="mm-primary-action">Ver vista previa</button>
                    </form>
                    <div id="mm-rotacion-preview" hidden style="margin-top:14px;"></div>
                </div>

                <div class="mm-platform-section">
                    <h2 class="mm-section-head">Mercancía que no rota</h2>
                    <?php if ( ! $tiene_datos ) : ?>
                        <div class="mm-empty-state">Aún no has cargado reportes de ventas. Sube tu primer CSV arriba para empezar.</div>
                    <?php else : ?>
                        <p class="mm-safe-note" style="margin:8px 0 12px;">Mostrando los <?php echo count( $items ); ?> productos más dormidos. La precisión mejora a medida que cargas más histórico.</p>
                        <table class="mm-rotacion-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="text-align:left; border-bottom:1px solid var(--mm-line,#e2e8f0);">
                                    <th style="padding:8px 6px;">SKU</th>
                                    <th style="padding:8px 6px;">Producto</th>
                                    <th style="padding:8px 6px;">Última venta</th>
                                    <th style="padding:8px 6px; text-align:right;">Días sin venta</th>
                                    <th style="padding:8px 6px; text-align:right;">Vendido 90d</th>
                                    <th style="padding:8px 6px;">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $items as $it ) :
                                    $badge = array(
                                        SalesRotationReport::NO_ROTA => array( 'No rota', '#fef2f2', '#b91c1c' ),
                                        SalesRotationReport::LENTO   => array( 'Lento', '#fffbeb', '#b45309' ),
                                        SalesRotationReport::OK      => array( 'Rotando', '#f0fdf4', '#15803d' ),
                                    );
                                    $b = $badge[ $it['estado'] ] ?? array( $it['estado'], '#f1f5f9', '#475569' );
                                    ?>
                                    <tr style="border-bottom:1px solid var(--mm-line,#eef2f7);">
                                        <td style="padding:8px 6px; font-family:monospace;"><?php echo esc_html( $it['sku'] ); ?></td>
                                        <td style="padding:8px 6px;"><?php echo esc_html( $it['nombre'] ); ?></td>
                                        <td style="padding:8px 6px;"><?php echo esc_html( $it['ultima_venta'] ?: '—' ); ?></td>
                                        <td style="padding:8px 6px; text-align:right;"><?php echo null === $it['dias_sin_venta'] ? '—' : esc_html( $it['dias_sin_venta'] ); ?></td>
                                        <td style="padding:8px 6px; text-align:right;"><?php echo esc_html( $it['unidades_90'] ); ?></td>
                                        <td style="padding:8px 6px;"><span style="font-size:11px; padding:2px 9px; border-radius:999px; background:<?php echo esc_attr( $b[1] ); ?>; color:<?php echo esc_attr( $b[2] ); ?>;"><?php echo esc_html( $b[0] ); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
        </main>
        <script>
        (function(){
            var form = document.getElementById('mm-rotacion-form');
            var box  = document.getElementById('mm-rotacion-preview');
            if (!form) return;
            var ajax = (window.mmApiSettings && mmApiSettings.ajaxUrl) ? mmApiSettings.ajaxUrl : '/wp-admin/admin-ajax.php';

            form.addEventListener('submit', async function(e){
                e.preventDefault();
                box.hidden = false;
                box.innerHTML = '<p class="mm-safe-note">Procesando archivo…</p>';
                var fd = new FormData(form);
                fd.append('action', 'mm_app_rotacion_preview');
                try {
                    var res = await fetch(ajax, { method:'POST', credentials:'same-origin', body:fd });
                    var data = await res.json();
                    if (!res.ok || !data.success) { box.innerHTML = '<p class="mm-message mm-message-error">'+((data.data&&data.data.message)||'No se pudo leer el archivo.')+'</p>'; return; }
                    var s = data.data;
                    var avisos = '';
                    if (s.fechas_ya_importadas && s.fechas_ya_importadas.length) {
                        avisos = '<p class="mm-message mm-message-warning">Ojo: estas fechas ya estaban cargadas y se van a reemplazar: '+s.fechas_ya_importadas.join(', ')+'</p>';
                    }
                    if (s.con_error) {
                        avisos += '<p class="mm-safe-note">'+s.con_error+' línea(s) con error (sin SKU o fecha) se omitirán.</p>';
                    }
                    box.innerHTML =
                        '<div class="mm-detail-card" style="padding:14px;">'
                        + '<strong>Vista previa</strong>'
                        + '<ul style="margin:8px 0; padding-left:18px; font-size:13px;">'
                        + '<li>'+s.registros+' registros (SKU+fecha) listos para guardar</li>'
                        + '<li>'+s.skus_distintos+' productos distintos</li>'
                        + '<li>Fechas: '+(s.fechas||[]).join(', ')+'</li>'
                        + '<li>'+s.unidades_total+' unidades en total</li>'
                        + '</ul>' + avisos
                        + '<button id="mm-rotacion-confirmar" class="mm-primary-action" type="button">Confirmar e importar</button>'
                        + '</div>';
                    document.getElementById('mm-rotacion-confirmar').addEventListener('click', async function(){
                        this.disabled = true; this.textContent = 'Importando…';
                        var fd2 = new FormData();
                        fd2.append('action', 'mm_app_rotacion_confirmar');
                        fd2.append('nonce', form.querySelector('[name=nonce]').value);
                        var r2 = await fetch(ajax, { method:'POST', credentials:'same-origin', body:fd2 });
                        var d2 = await r2.json();
                        if (!r2.ok || !d2.success) { box.innerHTML = '<p class="mm-message mm-message-error">'+((d2.data&&d2.data.message)||'No se pudo importar.')+'</p>'; return; }
                        box.innerHTML = '<p class="mm-message mm-message-success">Listo: '+d2.data.saved+' registros guardados. Recargando…</p>';
                        setTimeout(function(){ location.reload(); }, 900);
                    });
                } catch (err) {
                    box.innerHTML = '<p class="mm-message mm-message-error">Error de conexión al procesar el archivo.</p>';
                }
            });
        })();
        </script>
        <?php return ob_get_clean();
    }

    public function ajax_rotacion_importar_preview() {
        if ( ! is_user_logged_in() || ! $this->can_access_rotacion_panel() ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para importar ventas.' ), 403 );
        }
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_rotacion_import' ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida. Recarga la página.' ), 403 );
        }
        if ( empty( $_FILES['archivo']['tmp_name'] ) || ! is_uploaded_file( $_FILES['archivo']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => 'No se recibió ningún archivo.' ), 400 );
        }

        $content = file_get_contents( $_FILES['archivo']['tmp_name'] );
        if ( false === $content || '' === trim( (string) $content ) ) {
            wp_send_json_error( array( 'message' => 'El archivo está vacío.' ), 400 );
        }

        $importer = $this->sales_importer();
        $rows = $importer->parse_csv( $content );
        if ( count( $rows ) < 2 ) {
            wp_send_json_error( array( 'message' => 'El CSV no tiene filas de datos. ¿Lo guardaste como CSV?' ), 400 );
        }

        $headers = array_shift( $rows );
        $mapping = $importer->suggest_mapping( $headers );
        $preview = $importer->build_preview( $rows, $mapping, $this->sales_repo()->get_imported_dates() );
        if ( empty( $preview['ok'] ) ) {
            wp_send_json_error( array( 'message' => $preview['message'] . '. Encabezados detectados: ' . implode( ', ', $headers ) ), 400 );
        }

        set_transient( 'mm_rot_prev_' . get_current_user_id(), $preview['dias'], HOUR_IN_SECONDS );

        $s = $preview['summary'];
        wp_send_json_success( array(
            'registros'            => $s['registros'],
            'skus_distintos'       => $s['skus_distintos'],
            'fechas'               => $s['fechas'],
            'fechas_ya_importadas' => $s['fechas_ya_importadas'],
            'unidades_total'       => $s['unidades_total'],
            'con_error'            => $s['con_error'],
        ) );
    }

    public function ajax_rotacion_importar_confirmar() {
        if ( ! is_user_logged_in() || ! $this->can_access_rotacion_panel() ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para importar ventas.' ), 403 );
        }
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_rotacion_import' ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida. Recarga la página.' ), 403 );
        }

        $dias = get_transient( 'mm_rot_prev_' . get_current_user_id() );
        if ( ! is_array( $dias ) || empty( $dias ) ) {
            wp_send_json_error( array( 'message' => 'La vista previa expiró. Vuelve a subir el archivo.' ), 400 );
        }

        $saved = $this->sales_repo()->bulk_upsert( $dias );
        delete_transient( 'mm_rot_prev_' . get_current_user_id() );

        wp_send_json_success( array( 'saved' => $saved ) );
    }
}
