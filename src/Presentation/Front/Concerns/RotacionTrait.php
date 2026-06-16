<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Sales\SalesRepository;
use MegaMundo\Logistica\Domain\Inventory\InventoryRepository;
use MegaMundo\Logistica\Application\Inventory\MekanoSalesImporter;
use MegaMundo\Logistica\Application\Inventory\MekanoInventoryImporter;
use MegaMundo\Logistica\Application\Inventory\StockRotationReport;

/**
 * Módulo "Rotación": cruza las existencias (stock) con las ventas para mostrar
 * qué mercancía está parada. El análisis es relativo al periodo de datos
 * cargado (configurable; por defecto desde el 1 de abril de 2026), por eso el
 * periodo se muestra de forma destacada.
 */
trait RotacionTrait {

    private function rotacion_periodo_option() {
        return 'mm_rotacion_periodo_inicio';
    }

    private function rotacion_periodo_default() {
        return '2026-04-01';
    }

    private function sales_repo() {
        static $r = null;
        return $r ?: $r = new SalesRepository();
    }
    private function inventory_repo() {
        static $r = null;
        return $r ?: $r = new InventoryRepository();
    }
    private function sales_importer() {
        static $r = null;
        return $r ?: $r = new MekanoSalesImporter();
    }
    private function inventory_importer() {
        static $r = null;
        return $r ?: $r = new MekanoInventoryImporter();
    }
    private function stock_report() {
        static $r = null;
        return $r ?: $r = new StockRotationReport();
    }

    private function can_access_rotacion_panel() {
        return $this->permission_guard->can_access_jefatura_panel() || $this->permission_guard->is_admin();
    }

    private function rotacion_periodo_inicio() {
        $v = (string) get_option( $this->rotacion_periodo_option(), $this->rotacion_periodo_default() );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $this->rotacion_periodo_default();
    }

    private function rotacion_periodo_label() {
        $meses = array( 1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre' );
        $ini = $this->rotacion_periodo_inicio();
        $ts = strtotime( $ini );
        $d = (int) date( 'j', $ts ); $m = (int) date( 'n', $ts ); $y = date( 'Y', $ts );
        return $d . ' de ' . $meses[ $m ] . ' de ' . $y;
    }

    public function render_rotacion_dashboard() {
        if ( ! $this->can_access_rotacion_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para ver el módulo de rotación.' );
        }

        $tiene_inv    = $this->inventory_repo()->count() > 0;
        $tiene_ventas = $this->sales_repo()->has_any();

        $report = array( 'items' => array(), 'summary' => array( StockRotationReport::NO_ROTA => 0, StockRotationReport::LENTO => 0, StockRotationReport::OK => 0, 'total' => 0, 'unidades_paradas' => 0 ) );
        if ( $tiene_inv ) {
            $report = $this->stock_report()->build(
                $this->inventory_repo()->get_stock_rows(),
                $this->sales_repo()->get_last_sale_map()
            );
        }
        $sum   = $report['summary'];
        // Para la tabla: mostrar las 3 categorías (no solo lo parado).
        $por_estado = array( StockRotationReport::NO_ROTA => array(), StockRotationReport::LENTO => array(), StockRotationReport::OK => array() );
        foreach ( $report['items'] as $it ) { $por_estado[ $it['estado'] ][] = $it; }
        $items = array_merge(
            array_slice( $por_estado[ StockRotationReport::NO_ROTA ], 0, 150 ),
            array_slice( $por_estado[ StockRotationReport::LENTO ], 0, 100 ),
            array_slice( $por_estado[ StockRotationReport::OK ], 0, 100 )
        );
        $tot = max( 1, (int) $sum['total'] );
        $pct_no = (int) round( $sum[ StockRotationReport::NO_ROTA ] / $tot * 100 );
        $pct_le = (int) round( $sum[ StockRotationReport::LENTO ] / $tot * 100 );
        $pct_ok = (int) round( $sum[ StockRotationReport::OK ] / $tot * 100 );
        $nonce = wp_create_nonce( 'mm_rotacion_import' );
        $periodo = $this->rotacion_periodo_label();

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'rotacion' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Análisis</span>
                        <h1>Rotación de mercancía</h1>
                        <p>Cruce de existencias y ventas para ver qué productos están parados.</p>
                        <span class="mm-badge-soft" style="margin-top:10px; display:inline-block;">📅 Datos del <?php echo esc_html( $periodo ); ?> a hoy</span>
                    </div>
                </header>

                <div class="mm-safe-note" style="margin-bottom:16px;">
                    El análisis es relativo a ese periodo: un producto marcado <strong>"no rota"</strong> tiene stock y <strong>no se ha vendido desde el <?php echo esc_html( $periodo ); ?></strong>. Cargar más histórico amplía la ventana.
                </div>

                <div class="mm-role-summary-grid mm-role-summary-grid-4" style="margin-bottom:18px;">
                    <div class="mm-role-summary-card"><small>No rota</small><strong><?php echo esc_html( $sum[ StockRotationReport::NO_ROTA ] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Lento</small><strong><?php echo esc_html( $sum[ StockRotationReport::LENTO ] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Rotando bien</small><strong><?php echo esc_html( $sum[ StockRotationReport::OK ] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Unidades paradas</small><strong><?php echo esc_html( number_format_i18n( $sum['unidades_paradas'] ) ); ?></strong></div>
                </div>

                <?php if ( $tiene_inv ) : ?>
                <div class="mm-platform-section" style="margin-bottom:18px;">
                    <h2 class="mm-section-head">Distribución de la rotación</h2>
                    <div style="display:flex; height:26px; border-radius:8px; overflow:hidden; margin:12px 0 10px; background:#f1f5f9;">
                        <div title="No rota" style="width:<?php echo esc_attr( $pct_no ); ?>%; background:#dc2626;"></div>
                        <div title="Lento" style="width:<?php echo esc_attr( $pct_le ); ?>%; background:#f59e0b;"></div>
                        <div title="Rotando" style="width:<?php echo esc_attr( $pct_ok ); ?>%; background:#16a34a;"></div>
                    </div>
                    <div style="display:flex; flex-wrap:wrap; gap:18px; font-size:12px; color:var(--mm-ink-soft,#475569);">
                        <span><span style="display:inline-block;width:10px;height:10px;background:#dc2626;border-radius:2px;margin-right:5px;"></span>No rota — <?php echo esc_html( $sum[ StockRotationReport::NO_ROTA ] ); ?> (<?php echo esc_html( $pct_no ); ?>%)</span>
                        <span><span style="display:inline-block;width:10px;height:10px;background:#f59e0b;border-radius:2px;margin-right:5px;"></span>Lento — <?php echo esc_html( $sum[ StockRotationReport::LENTO ] ); ?> (<?php echo esc_html( $pct_le ); ?>%)</span>
                        <span><span style="display:inline-block;width:10px;height:10px;background:#16a34a;border-radius:2px;margin-right:5px;"></span>Rotando — <?php echo esc_html( $sum[ StockRotationReport::OK ] ); ?> (<?php echo esc_html( $pct_ok ); ?>%)</span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mm-platform-card-grid" style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:18px;">
                    <div class="mm-platform-section">
                        <h2 class="mm-section-head">1. Cargar existencias</h2>
                        <p class="mm-safe-note">Reporte de inventario de Mekano (con EXISTENCIA). Guardar como CSV. <?php echo $tiene_inv ? '<strong>✓ Ya hay existencias cargadas.</strong>' : 'Aún no has cargado existencias.'; ?></p>
                        <form class="mm-rot-form" data-action="mm_app_rotacion_inv_preview" data-confirm="mm_app_rotacion_inv_confirmar" enctype="multipart/form-data" style="margin-top:10px;">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                            <input class="mm-input" type="file" name="archivo" accept=".csv,text/csv" required style="max-width:100%;">
                            <button type="submit" class="mm-primary-action" style="margin-top:8px;">Ver vista previa</button>
                        </form>
                        <div class="mm-rot-preview" hidden style="margin-top:12px;"></div>
                    </div>
                    <div class="mm-platform-section">
                        <h2 class="mm-section-head">2. Cargar ventas</h2>
                        <p class="mm-safe-note">Reporte de ventas de Mekano. Guardar como CSV. Subir la misma fecha reemplaza, no duplica. <?php echo $tiene_ventas ? '<strong>✓ Ya hay ventas cargadas.</strong>' : ''; ?></p>
                        <form class="mm-rot-form" data-action="mm_app_rotacion_preview" data-confirm="mm_app_rotacion_confirmar" enctype="multipart/form-data" style="margin-top:10px;">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                            <input class="mm-input" type="file" name="archivo" accept=".csv,text/csv" required style="max-width:100%;">
                            <button type="submit" class="mm-primary-action" style="margin-top:8px;">Ver vista previa</button>
                        </form>
                        <div class="mm-rot-preview" hidden style="margin-top:12px;"></div>
                    </div>
                </div>

                <div class="mm-platform-section">
                    <h2 class="mm-section-head">Detalle por producto</h2>
                    <?php if ( ! $tiene_inv ) : ?>
                        <div class="mm-empty-state">Carga primero las existencias (paso 1) para ver el stock parado. Si además cargas las ventas, sabrás hace cuánto no se vende cada producto.</div>
                    <?php else : ?>
                        <?php if ( ! $tiene_ventas ) : ?>
                            <div class="mm-safe-note" style="margin-bottom:12px;">Cargaste existencias pero aún no hay ventas: todo aparece como "no rota". Carga las ventas (paso 2) para distinguir lo que sí se mueve.</div>
                        <?php endif; ?>
                        <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:8px 0 14px;">
                            <button type="button" class="mm-rot-chip mm-mini-secondary is-on" data-filtro="todos">Todos</button>
                            <button type="button" class="mm-rot-chip mm-mini-secondary" data-filtro="no_rota">No rota</button>
                            <button type="button" class="mm-rot-chip mm-mini-secondary" data-filtro="lento">Lento</button>
                            <button type="button" class="mm-rot-chip mm-mini-secondary" data-filtro="ok">Rotando</button>
                            <input type="search" id="mm-rot-buscar" class="mm-input" placeholder="Buscar por SKU o nombre…" style="max-width:260px; margin-left:auto;">
                        </div>
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <thead>
                                <tr style="text-align:left; border-bottom:1px solid var(--mm-line,#e2e8f0);">
                                    <th style="padding:8px 6px;">SKU</th>
                                    <th style="padding:8px 6px;">Producto</th>
                                    <th style="padding:8px 6px; text-align:right;">Stock</th>
                                    <th style="padding:8px 6px;">Última venta</th>
                                    <th style="padding:8px 6px; text-align:right;">Días sin venta</th>
                                    <th style="padding:8px 6px;">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $items as $it ) :
                                    $badge = array(
                                        StockRotationReport::NO_ROTA => array( 'No rota', '#fef2f2', '#b91c1c' ),
                                        StockRotationReport::LENTO   => array( 'Lento', '#fffbeb', '#b45309' ),
                                        StockRotationReport::OK      => array( 'Rotando', '#f0fdf4', '#15803d' ),
                                    );
                                    $b = $badge[ $it['estado'] ] ?? array( $it['estado'], '#f1f5f9', '#475569' );
                                    ?>
                                    <tr class="mm-rot-row" data-estado="<?php echo esc_attr( $it['estado'] ); ?>" data-buscar="<?php echo esc_attr( strtolower( $it['sku'] . ' ' . $it['nombre'] ) ); ?>" style="border-bottom:1px solid var(--mm-line,#eef2f7);">
                                        <td style="padding:8px 6px; font-family:monospace;"><?php echo esc_html( $it['sku'] ); ?></td>
                                        <td style="padding:8px 6px;"><?php echo esc_html( $it['nombre'] ); ?></td>
                                        <td style="padding:8px 6px; text-align:right;"><?php echo esc_html( number_format_i18n( $it['stock'] ) ); ?></td>
                                        <td style="padding:8px 6px;"><?php echo esc_html( $it['ultima_venta'] ?: 'Sin ventas' ); ?></td>
                                        <td style="padding:8px 6px; text-align:right;"><?php echo null === $it['dias_sin_venta'] ? '—' : esc_html( $it['dias_sin_venta'] ); ?></td>
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
            var ajax = (window.mmApiSettings && mmApiSettings.ajaxUrl) ? mmApiSettings.ajaxUrl : '/wp-admin/admin-ajax.php';
            document.querySelectorAll('.mm-rot-form').forEach(function(form){
                var box = form.parentElement.querySelector('.mm-rot-preview');
                form.addEventListener('submit', async function(e){
                    e.preventDefault();
                    box.hidden = false; box.innerHTML = '<p class="mm-safe-note">Procesando…</p>';
                    var fd = new FormData(form);
                    fd.append('action', form.dataset.action);
                    try {
                        var res = await fetch(ajax, {method:'POST', credentials:'same-origin', body:fd});
                        var data = await res.json();
                        if (!res.ok || !data.success) { box.innerHTML = '<p class="mm-message mm-message-error">'+((data.data&&data.data.message)||'No se pudo leer el archivo.')+'</p>'; return; }
                        var s = data.data;
                        var li = '';
                        Object.keys(s.resumen||{}).forEach(function(k){ li += '<li>'+s.resumen[k]+'</li>'; });
                        var avisos = (s.avisos||[]).map(function(a){ return '<p class="mm-message mm-message-warning">'+a+'</p>'; }).join('');
                        box.innerHTML = '<div class="mm-detail-card" style="padding:14px;"><strong>Vista previa</strong><ul style="margin:8px 0; padding-left:18px; font-size:13px;">'+li+'</ul>'+avisos+'<button class="mm-primary-action mm-rot-confirm" type="button">Confirmar e importar</button></div>';
                        box.querySelector('.mm-rot-confirm').addEventListener('click', async function(){
                            this.disabled = true; this.textContent = 'Importando…';
                            var fd2 = new FormData();
                            fd2.append('action', form.dataset.confirm);
                            fd2.append('nonce', form.querySelector('[name=nonce]').value);
                            var r2 = await fetch(ajax, {method:'POST', credentials:'same-origin', body:fd2});
                            var d2 = await r2.json();
                            if (!r2.ok || !d2.success) { box.innerHTML = '<p class="mm-message mm-message-error">'+((d2.data&&d2.data.message)||'No se pudo importar.')+'</p>'; return; }
                            box.innerHTML = '<p class="mm-message mm-message-success">Listo: '+d2.data.saved+' registros guardados. Recargando…</p>';
                            setTimeout(function(){ location.reload(); }, 1000);
                        });
                    } catch (err) { box.innerHTML = '<p class="mm-message mm-message-error">Error de conexión.</p>'; }
                });
            });

            // Filtros por estado + buscador de la tabla
            var rows = Array.prototype.slice.call(document.querySelectorAll('.mm-rot-row'));
            var chips = Array.prototype.slice.call(document.querySelectorAll('.mm-rot-chip'));
            var buscar = document.getElementById('mm-rot-buscar');
            var filtro = 'todos';
            function aplicar(){
                var q = (buscar && buscar.value ? buscar.value : '').trim().toLowerCase();
                rows.forEach(function(tr){
                    var okEstado = (filtro === 'todos') || (tr.dataset.estado === filtro);
                    var okBusca = !q || (tr.dataset.buscar || '').indexOf(q) !== -1;
                    tr.style.display = (okEstado && okBusca) ? '' : 'none';
                });
            }
            chips.forEach(function(c){
                c.addEventListener('click', function(){
                    filtro = c.dataset.filtro;
                    chips.forEach(function(x){ x.classList.toggle('is-on', x === c); });
                    aplicar();
                });
            });
            if (buscar) { buscar.addEventListener('input', aplicar); }
        })();
        </script>
        <?php return ob_get_clean();
    }

    /* ---------------- Importación de VENTAS ---------------- */

    public function ajax_rotacion_importar_preview() {
        $this->rotacion_guard();
        $content = $this->rotacion_read_upload();
        $importer = $this->sales_importer();
        $rows = $importer->parse_csv( $content );
        if ( count( $rows ) < 2 ) {
            wp_send_json_error( array( 'message' => 'El CSV no tiene filas de datos. ¿Lo guardaste como CSV?' ), 400 );
        }
        $headers = array_shift( $rows );
        $mapping = $importer->suggest_mapping( $headers );
        $preview = $importer->build_preview( $rows, $mapping, $this->sales_repo()->get_imported_dates() );
        if ( empty( $preview['ok'] ) ) {
            wp_send_json_error( array( 'message' => $preview['message'] . '. Columnas: ' . implode( ', ', $headers ) ), 400 );
        }
        set_transient( 'mm_rot_prev_ventas_' . get_current_user_id(), $preview['dias'], HOUR_IN_SECONDS );
        $s = $preview['summary'];
        $avisos = array();
        if ( ! empty( $s['fechas_ya_importadas'] ) ) {
            $avisos[] = 'Estas fechas ya estaban cargadas y se reemplazarán: ' . implode( ', ', $s['fechas_ya_importadas'] );
        }
        wp_send_json_success( array(
            'resumen' => array(
                $s['registros'] . ' registros (sku+fecha)',
                $s['skus_distintos'] . ' productos',
                'Fechas: ' . implode( ', ', $s['fechas'] ),
                $s['unidades_total'] . ' unidades vendidas',
            ),
            'avisos' => $avisos,
        ) );
    }

    public function ajax_rotacion_importar_confirmar() {
        $this->rotacion_guard();
        $dias = get_transient( 'mm_rot_prev_ventas_' . get_current_user_id() );
        if ( ! is_array( $dias ) || empty( $dias ) ) {
            wp_send_json_error( array( 'message' => 'La vista previa expiró. Vuelve a subir el archivo.' ), 400 );
        }
        $saved = $this->sales_repo()->bulk_upsert( $dias );
        delete_transient( 'mm_rot_prev_ventas_' . get_current_user_id() );
        wp_send_json_success( array( 'saved' => $saved ) );
    }

    /* ---------------- Importación de EXISTENCIAS ---------------- */

    public function ajax_rotacion_inv_preview() {
        $this->rotacion_guard();
        $content = $this->rotacion_read_upload();
        $importer = $this->inventory_importer();
        $rows = $importer->parse_csv( $content );
        if ( count( $rows ) < 2 ) {
            wp_send_json_error( array( 'message' => 'El CSV no tiene filas de datos. ¿Lo guardaste como CSV?' ), 400 );
        }
        $headers = array_shift( $rows );
        $sample = array_slice( $rows, 0, 30 );
        $mapping = $importer->suggest_mapping( $headers, $sample );
        $preview = $importer->build_preview( $rows, $mapping, $this->inventory_repo()->get_existing_skus() );
        if ( empty( $preview['ok'] ) ) {
            wp_send_json_error( array( 'message' => $preview['message'] . '. Columnas: ' . implode( ', ', $headers ) ), 400 );
        }
        set_transient( 'mm_rot_prev_inv_' . get_current_user_id(), $preview['rows'], HOUR_IN_SECONDS );
        $s = $preview['summary'];
        wp_send_json_success( array(
            'resumen' => array(
                $s['skus'] . ' productos en el archivo',
                $s['con_stock'] . ' con stock disponible',
                $s['nuevos'] . ' nuevos · ' . $s['actualizar'] . ' a actualizar',
            ),
            'avisos' => $s['con_error'] ? array( $s['con_error'] . ' línea(s) sin SKU se omitirán.' ) : array(),
        ) );
    }

    public function ajax_rotacion_inv_confirmar() {
        $this->rotacion_guard();
        $rows = get_transient( 'mm_rot_prev_inv_' . get_current_user_id() );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            wp_send_json_error( array( 'message' => 'La vista previa expiró. Vuelve a subir el archivo.' ), 400 );
        }
        $saved = $this->inventory_repo()->bulk_replace( $rows );
        delete_transient( 'mm_rot_prev_inv_' . get_current_user_id() );
        wp_send_json_success( array( 'saved' => $saved ) );
    }

    /* ---------------- Helpers compartidos ---------------- */

    private function rotacion_guard() {
        if ( ! is_user_logged_in() || ! $this->can_access_rotacion_panel() ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para importar.' ), 403 );
        }
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_rotacion_import' ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida. Recarga la página.' ), 403 );
        }
    }

    private function rotacion_read_upload() {
        if ( empty( $_FILES['archivo']['tmp_name'] ) || ! is_uploaded_file( $_FILES['archivo']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => 'No se recibió ningún archivo.' ), 400 );
        }
        $content = file_get_contents( $_FILES['archivo']['tmp_name'] );
        if ( false === $content || '' === trim( (string) $content ) ) {
            wp_send_json_error( array( 'message' => 'El archivo está vacío.' ), 400 );
        }
        return $content;
    }
}
