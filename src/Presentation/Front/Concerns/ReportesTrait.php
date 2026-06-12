<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait ReportesTrait {
    private function get_report_date_filters() {
        $today = current_time( 'Y-m-d' );
        $from = isset( $_GET['fecha_desde'] ) ? sanitize_text_field( wp_unslash( $_GET['fecha_desde'] ) ) : $today;
        $to   = isset( $_GET['fecha_hasta'] ) ? sanitize_text_field( wp_unslash( $_GET['fecha_hasta'] ) ) : $today;

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            $from = $today;
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $to = $today;
        }

        return array(
            'from' => $from,
            'to'   => $to,
        );
    }

    private function report_lote_in_date_range( $lote_id, $filters ) {
        $timestamp = get_post_time( 'U', true, $lote_id );
        if ( ! $timestamp ) {
            return false;
        }

        $from_ts = strtotime( $filters['from'] . ' 00:00:00' );
        $to_ts   = strtotime( $filters['to'] . ' 23:59:59' );

        return $timestamp >= $from_ts && $timestamp <= $to_ts;
    }

    private function report_date_url( $from, $to ) {
        return add_query_arg(
            array(
                'mm_logistica_app' => 'reportes',
                'fecha_desde'      => $from,
                'fecha_hasta'      => $to,
            ),
            home_url( '/' )
        );
    }


    private function get_all_lotes_for_reports( $filters = null ) {
        $filters = $filters ?: $this->get_report_date_filters();
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( isset( $lote->ID ) && $this->report_lote_in_date_range( intval( $lote->ID ), $filters ) ) {
                    $lotes[ intval( $lote->ID ) ] = $lote;
                }
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_report_metrics( $filters = null ) {
        $filters = $filters ?: $this->get_report_date_filters();
        $lotes = $this->get_all_lotes_for_reports( $filters );
        $metrics = array(
            'lotes_total'               => count( $lotes ),
            'lotes_cargados'            => 0,
            'unidades_total'            => 0,
            'skus_total'                => 0,
            'costo_total'               => 0,
            'venta_total'               => 0,
            'margen_total'              => 0,
            'productos_nuevos_borrador' => 0,
            'productos_impresos'        => 0,
            'productos_pendientes'      => 0,
            'reimpresiones'             => 0,
        );

        $draft_products = array();

        foreach ( $lotes as $lote ) {
            $lote_id = intval( $lote->ID );
            $status = $this->lote_repo->get_status( $lote_id );

            if ( 'mm_cargado' === $status || $this->lote_repo->is_synchronized( $lote_id ) ) {
                $metrics['lotes_cargados']++;
            }

            $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
            $metrics['skus_total'] += count( $items );

            $label_statuses = get_post_meta( $lote_id, '_mm_etiquetas_items_status', true );
            $label_statuses = is_array( $label_statuses ) ? $label_statuses : array();

            foreach ( $items as $item ) {
                $qty  = intval( $item->cantidad_contada );
                $cost = floatval( $item->costo_ia );
                $sale = floatval( $item->precio_propuesto );

                $metrics['unidades_total'] += $qty;
                $metrics['costo_total']    += $cost * $qty;
                $metrics['venta_total']    += $sale * $qty;
                $metrics['margen_total']   += ( $sale - $cost ) * $qty;

                $pid = intval( $item->producto_id );
                if ( $pid > 0 && 'draft' === get_post_status( $pid ) ) {
                    $draft_products[ $pid ] = true;
                }

                $item_id = intval( $item->id );
                $label_status = isset( $label_statuses[ $item_id ] ) ? $label_statuses[ $item_id ] : array();
                $state = isset( $label_status['status'] ) ? $label_status['status'] : 'pendiente';

                if ( empty( $state ) || 'pendiente' === $state ) {
                    $metrics['productos_pendientes']++;
                } else {
                    $metrics['productos_impresos']++;
                }

                if ( isset( $label_status['print_count'] ) && intval( $label_status['print_count'] ) > 1 ) {
                    $metrics['reimpresiones'] += intval( $label_status['print_count'] ) - 1;
                }
            }
        }

        $metrics['productos_nuevos_borrador'] = count( $draft_products );
        return $metrics;
    }

    private function report_money( $value ) {
        return '$' . number_format( floatval( $value ), 0, ',', '.' );
    }

    private function report_export_url( $type ) {
        $filters = $this->get_report_date_filters();
        return wp_nonce_url(
            add_query_arg(
                array(
                    'mm_export_report' => sanitize_key( $type ),
                    'fecha_desde'      => $filters['from'],
                    'fecha_hasta'      => $filters['to'],
                ),
                home_url( '/' )
            ),
            'mm_export_report_' . sanitize_key( $type )
        );
    }

    public function export_report_csv() {
        if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
            wp_die( 'No tienes permisos para exportar reportes.' );
        }

        $type = isset( $_GET['mm_export_report'] ) ? sanitize_key( wp_unslash( $_GET['mm_export_report'] ) ) : '';

        if ( ! $type || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mm_export_report_' . $type ) ) {
            wp_die( 'Enlace de exportación no autorizado o vencido.' );
        }

        $filters = $this->get_report_date_filters();
        $filename = 'megamundo-reporte-' . $type . '-' . $filters['from'] . '-a-' . $filters['to'] . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        if ( 'lotes' === $type ) {
            fputcsv( $out, array( 'ID Lote', 'Nombre', 'Estado', 'SKUs', 'Unidades', 'Costo total', 'Venta total', 'Margen total', 'Fecha' ) );
            foreach ( $this->get_all_lotes_for_reports( $filters ) as $lote ) {
                $lote_id = intval( $lote->ID );
                $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
                $units = 0;
                $cost = 0;
                $sale = 0;

                foreach ( $items as $item ) {
                    $qty = intval( $item->cantidad_contada );
                    $units += $qty;
                    $cost  += floatval( $item->costo_ia ) * $qty;
                    $sale  += floatval( $item->precio_propuesto ) * $qty;
                }

                fputcsv( $out, array(
                    $lote_id,
                    get_the_title( $lote_id ),
                    $this->status_label( $this->lote_repo->get_status( $lote_id ) ),
                    count( $items ),
                    $units,
                    $cost,
                    $sale,
                    $sale - $cost,
                    get_the_date( 'Y-m-d H:i:s', $lote_id ),
                ) );
            }
        } elseif ( 'productos' === $type ) {
            fputcsv( $out, array( 'ID Lote', 'Lote', 'SKU', 'Producto', 'Cantidad', 'Costo unitario', 'Precio final', 'Margen unitario', 'Estado lote' ) );
            foreach ( $this->get_all_lotes_for_reports( $filters ) as $lote ) {
                $lote_id = intval( $lote->ID );
                $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

                foreach ( $items as $item ) {
                    $product_name = intval( $item->producto_id ) ? get_the_title( intval( $item->producto_id ) ) : 'Producto sin nombre';
                    $cost = floatval( $item->costo_ia );
                    $sale = floatval( $item->precio_propuesto );

                    fputcsv( $out, array(
                        $lote_id,
                        get_the_title( $lote_id ),
                        $item->sku,
                        $product_name,
                        intval( $item->cantidad_contada ),
                        $cost,
                        $sale,
                        $sale - $cost,
                        $this->status_label( $this->lote_repo->get_status( $lote_id ) ),
                    ) );
                }
            }
        } elseif ( 'etiquetas' === $type ) {
            fputcsv( $out, array( 'ID Lote', 'Lote', 'SKU', 'Producto', 'Cantidad', 'Estado impresión', 'Veces impresas' ) );
            foreach ( $this->get_all_lotes_for_reports( $filters ) as $lote ) {
                $lote_id = intval( $lote->ID );
                $statuses = get_post_meta( $lote_id, '_mm_etiquetas_items_status', true );
                $statuses = is_array( $statuses ) ? $statuses : array();
                $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

                foreach ( $items as $item ) {
                    $item_id = intval( $item->id );
                    $state = isset( $statuses[ $item_id ]['status'] ) ? $statuses[ $item_id ]['status'] : 'pendiente';
                    $print_count = isset( $statuses[ $item_id ]['print_count'] ) ? intval( $statuses[ $item_id ]['print_count'] ) : 0;
                    $product_name = intval( $item->producto_id ) ? get_the_title( intval( $item->producto_id ) ) : 'Producto sin nombre';

                    fputcsv( $out, array(
                        $lote_id,
                        get_the_title( $lote_id ),
                        $item->sku,
                        $product_name,
                        intval( $item->cantidad_contada ),
                        $state,
                        $print_count,
                    ) );
                }
            }
        } else {
            fputcsv( $out, array( 'Reporte no válido' ) );
        }

        fclose( $out );
        exit;
    }

    private function render_reportes_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para ver reportes.' );
        }

        $filters = $this->get_report_date_filters();
        $metrics = $this->get_report_metrics( $filters );
        $today = current_time( 'Y-m-d' );
        $yesterday = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
        $last_7 = date( 'Y-m-d', strtotime( $today . ' -6 days' ) );
        $month_start = date( 'Y-m-01', strtotime( $today ) );
        $margin_pct = $metrics['venta_total'] > 0 ? ( $metrics['margen_total'] / $metrics['venta_total'] ) * 100 : 0;

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'reportes' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Reportes</span>
                        <h1>Medición y exportación</h1>
                        <p>Consulta el rendimiento operativo de lotes, productos, costos, precios y etiquetas por día o rango de fechas.</p>
                    </div>
                </header>

                <section class="mm-report-filter-card">
                    <div>
                        <h2>Filtro por fecha</h2>
                        <p>Mostrando datos desde <strong><?php echo esc_html( $filters['from'] ); ?></strong> hasta <strong><?php echo esc_html( $filters['to'] ); ?></strong>.</p>
                    </div>
                    <div class="mm-report-quick-dates">
                        <a href="<?php echo esc_url( $this->report_date_url( $today, $today ) ); ?>">Hoy</a>
                        <a href="<?php echo esc_url( $this->report_date_url( $yesterday, $yesterday ) ); ?>">Ayer</a>
                        <a href="<?php echo esc_url( $this->report_date_url( $last_7, $today ) ); ?>">Últimos 7 días</a>
                        <a href="<?php echo esc_url( $this->report_date_url( $month_start, $today ) ); ?>">Este mes</a>
                    </div>
                    <form class="mm-report-date-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <input type="hidden" name="mm_logistica_app" value="reportes">
                        <label>Desde <input type="date" name="fecha_desde" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
                        <label>Hasta <input type="date" name="fecha_hasta" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
                        <button type="submit" class="mm-mini-primary">Aplicar filtro</button>
                    </form>
                </section>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Lotes totales</small><strong><?php echo esc_html( $metrics['lotes_total'] ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Lotes cargados</small><strong><?php echo esc_html( $metrics['lotes_cargados'] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Unidades ingresadas</small><strong><?php echo esc_html( $metrics['unidades_total'] ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>SKUs registrados</small><strong><?php echo esc_html( $metrics['skus_total'] ); ?></strong></div>
                </div>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Costo total</small><strong><?php echo esc_html( $this->report_money( $metrics['costo_total'] ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Venta total final</small><strong><?php echo esc_html( $this->report_money( $metrics['venta_total'] ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Margen estimado</small><strong><?php echo esc_html( $this->report_money( $metrics['margen_total'] ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>% Margen</small><strong><?php echo esc_html( number_format( $margin_pct, 1, ',', '.' ) ); ?>%</strong></div>
                </div>

                <div class="mm-dashboard-alert-grid">
                    <div class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Etiquetas</h3><span>Control de impresión</span></div>
                        <ul class="mm-summary-list">
                            <li><span>Productos impresos/reimpresos</span><strong><?php echo esc_html( $metrics['productos_impresos'] ); ?></strong></li>
                            <li><span>Productos pendientes por imprimir</span><strong><?php echo esc_html( $metrics['productos_pendientes'] ); ?></strong></li>
                            <li><span>Reimpresiones registradas</span><strong><?php echo esc_html( $metrics['reimpresiones'] ); ?></strong></li>
                        </ul>
                    </div>

                    <div class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Productos nuevos</h3><span>Revisión</span></div>
                        <ul class="mm-summary-list">
                            <li><span>Productos nuevos en borrador</span><strong><?php echo esc_html( $metrics['productos_nuevos_borrador'] ); ?></strong></li>
                        </ul>
                    </div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Exportaciones CSV</h2>
                        <span>Descarga reportes filtrados por la fecha seleccionada.</span>
                    </div>

                    <div class="mm-report-export-grid">
                        <a class="mm-report-export-card" href="<?php echo esc_url( $this->report_export_url( 'lotes' ) ); ?>">
                            <strong>Reporte de lotes</strong>
                            <small>Estados, unidades, costos, ventas y margen por lote.</small>
                            <span>Exportar CSV</span>
                        </a>
                        <a class="mm-report-export-card" href="<?php echo esc_url( $this->report_export_url( 'productos' ) ); ?>">
                            <strong>Reporte de productos</strong>
                            <small>SKU, producto, cantidad, costo, precio final y margen.</small>
                            <span>Exportar CSV</span>
                        </a>
                        <a class="mm-report-export-card" href="<?php echo esc_url( $this->report_export_url( 'etiquetas' ) ); ?>">
                            <strong>Reporte de etiquetas</strong>
                            <small>Estado de impresión, productos pendientes y reimpresiones.</small>
                            <span>Exportar CSV</span>
                        </a>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }
}
