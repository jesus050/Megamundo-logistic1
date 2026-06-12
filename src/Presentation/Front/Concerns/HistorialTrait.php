<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait HistorialTrait {
    private function get_historial_lotes_disponibles() {
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
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

    private function get_historial_lote_id_actual() {
        $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
        if ( $lote_id > 0 && $this->lote_repo->find( $lote_id ) ) {
            return $lote_id;
        }

        $lotes = $this->get_historial_lotes_disponibles();
        return ! empty( $lotes ) ? intval( $lotes[0]->ID ) : 0;
    }

    private function get_historial_item_events( $lote_id, $item ) {
        $events = array();
        $lote_id = intval( $lote_id );
        $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
        $product_id = isset( $item->producto_id ) ? intval( $item->producto_id ) : 0;

        $sku = isset( $item->sku ) ? $item->sku : '';
        $qty = isset( $item->cantidad_contada ) ? intval( $item->cantidad_contada ) : 0;
        $operator = isset( $item->operador ) && ! empty( $item->operador ) ? $item->operador : '';
        $scan_date = isset( $item->fecha_escaneo ) && ! empty( $item->fecha_escaneo ) ? $item->fecha_escaneo : '';

        $events[] = array(
            'type'  => 'escaneo',
            'icon'  => '📦',
            'title' => 'Producto registrado en lote',
            'text'  => 'SKU ' . $sku . ' con cantidad ' . $qty . ( $operator ? ' por ' . $operator : '' ) . '.',
            'date'  => $scan_date,
        );

        if ( $product_id > 0 ) {
            $thumb = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
            if ( $thumb ) {
                $events[] = array(
                    'type'  => 'foto',
                    'icon'  => '📸',
                    'title' => 'Foto de producto disponible',
                    'text'  => 'El producto tiene imagen registrada para revisión, etiquetas o catálogo.',
                    'date'  => $scan_date,
                );
            }

            $review_state = get_post_meta( $product_id, '_mm_revision_estado', true );
            $review_at = get_post_meta( $product_id, '_mm_revision_at', true );
            $review_by = absint( get_post_meta( $product_id, '_mm_revision_by', true ) );
            if ( 'revisado' === $review_state ) {
                $user = $review_by ? get_userdata( $review_by ) : null;
                $events[] = array(
                    'type'  => 'revision_ia',
                    'icon'  => '🤖',
                    'title' => 'Producto nuevo revisado',
                    'text'  => 'Información de IA revisada' . ( $user ? ' por ' . $user->display_name : '' ) . '.',
                    'date'  => $review_at,
                );
            }
        }

        $cost = isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0;
        $price = isset( $item->precio_propuesto ) ? floatval( $item->precio_propuesto ) : 0;
        if ( $cost > 0 || $price > 0 ) {
            $events[] = array(
                'type'  => 'precio',
                'icon'  => '🏷️',
                'title' => 'Costo y precio asignados',
                'text'  => 'Costo: $' . number_format( $cost, 0, ',', '.' ) . ' · Precio: $' . number_format( $price, 0, ',', '.' ) . '.',
                'date'  => '',
            );
        }

        $approved_at = get_post_meta( $lote_id, '_mm_jefatura_aprobado_at', true );
        $approved_by = absint( get_post_meta( $lote_id, '_mm_jefatura_aprobado_by', true ) );
        if ( $approved_at ) {
            $user = $approved_by ? get_userdata( $approved_by ) : null;
            $events[] = array(
                'type'  => 'autorizacion',
                'icon'  => '✅',
                'title' => 'Precio autorizado por jefatura',
                'text'  => 'Precio final autorizado' . ( $user ? ' por ' . $user->display_name : '' ) . '.',
                'date'  => $approved_at,
            );
        }

        $synced_at = property_exists( $item, 'synced_at' ) ? $item->synced_at : '';
        $sync_error = property_exists( $item, 'sync_error' ) ? $item->sync_error : '';
        if ( $synced_at ) {
            $events[] = array(
                'type'  => 'sync_ok',
                'icon'  => '🔁',
                'title' => 'Sincronizado con WooCommerce',
                'text'  => 'Producto cargado correctamente al sistema.',
                'date'  => $synced_at,
            );
        } elseif ( $sync_error ) {
            $events[] = array(
                'type'  => 'sync_error',
                'icon'  => '⚠️',
                'title' => 'Error de sincronización',
                'text'  => $sync_error,
                'date'  => '',
            );
        }

        $label_statuses = get_post_meta( $lote_id, '_mm_etiquetas_items_status', true );
        $label_statuses = is_array( $label_statuses ) ? $label_statuses : array();
        if ( $item_id && isset( $label_statuses[ $item_id ] ) && is_array( $label_statuses[ $item_id ] ) ) {
            $status = $label_statuses[ $item_id ];
            $print_count = isset( $status['print_count'] ) ? intval( $status['print_count'] ) : 0;
            if ( $print_count > 0 ) {
                $printed_by = ! empty( $status['printed_by'] ) ? get_userdata( absint( $status['printed_by'] ) ) : null;
                $events[] = array(
                    'type'  => 'impresion',
                    'icon'  => '🖨️',
                    'title' => $print_count > 1 ? 'Etiqueta reimpresa' : 'Etiqueta impresa',
                    'text'  => 'Impresiones registradas: ' . $print_count . ( $printed_by ? ' · por ' . $printed_by->display_name : '' ) . '.',
                    'date'  => isset( $status['printed_at'] ) ? $status['printed_at'] : '',
                );
            }
        }

        return $events;
    }

    private function render_historial_producto_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para ver el historial por producto.' );
        }

        $lotes = $this->get_historial_lotes_disponibles();
        $lote_id = $this->get_historial_lote_id_actual();
        $items = $lote_id && $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

        $total_items = count( $items );
        $with_price = 0;
        $synced = 0;
        $printed = 0;

        $label_statuses = $lote_id ? get_post_meta( $lote_id, '_mm_etiquetas_items_status', true ) : array();
        $label_statuses = is_array( $label_statuses ) ? $label_statuses : array();

        foreach ( $items as $item ) {
            if ( isset( $item->precio_propuesto ) && floatval( $item->precio_propuesto ) > 0 ) {
                $with_price++;
            }
            if ( property_exists( $item, 'synced_at' ) && ! empty( $item->synced_at ) ) {
                $synced++;
            }
            $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
            if ( $item_id && isset( $label_statuses[ $item_id ]['print_count'] ) && intval( $label_statuses[ $item_id ]['print_count'] ) > 0 ) {
                $printed++;
            }
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'historial' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Historial por producto</span>
                        <h1>Trazabilidad completa</h1>
                        <p>Consulta qué pasó con cada SKU dentro del lote: escaneo, precios, autorización, sincronización e impresión.</p>
                    </div>
                </header>

                

                <section class="mm-report-filter-card">
                    <div>
                        <h2>Seleccionar lote</h2>
                        <p>El historial se muestra producto por producto dentro del lote seleccionado.</p>
                    </div>
                    <form class="mm-report-date-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <input type="hidden" name="mm_logistica_app" value="historial">
                        <label>Lote
                            <select name="lote_id" class="mm-input">
                                <?php foreach ( $lotes as $lote ) : ?>
                                    <option value="<?php echo esc_attr( $lote->ID ); ?>" <?php selected( $lote_id, $lote->ID ); ?>>
                                        #<?php echo esc_html( $lote->ID ); ?> — <?php echo esc_html( get_the_title( $lote->ID ) ); ?> / <?php echo esc_html( $this->status_label( $this->lote_repo->get_status( $lote->ID ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="mm-mini-primary">Ver historial</button>
                    </form>
                </section>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Productos</small><strong><?php echo esc_html( $total_items ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Con precio</small><strong><?php echo esc_html( $with_price ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Sincronizados</small><strong><?php echo esc_html( $synced ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Etiquetas impresas</small><strong><?php echo esc_html( $printed ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Historial del lote <?php echo $lote_id ? '#' . esc_html( $lote_id ) : ''; ?></h2>
                        <span>Una tarjeta por producto.</span>
                    </div>

                    <div class="mm-history-grid">
                        <?php if ( empty( $items ) ) : ?>
                            <div class="mm-empty-state">No hay productos para mostrar en este lote.</div>
                        <?php endif; ?>

                        <?php foreach ( $items as $item ) :
                            $product_id = isset( $item->producto_id ) ? intval( $item->producto_id ) : 0;
                            $name = $product_id ? get_the_title( $product_id ) : 'Producto sin nombre';
                            $thumb = $product_id ? get_the_post_thumbnail_url( $product_id, 'thumbnail' ) : '';
                            $events = $this->get_historial_item_events( $lote_id, $item );
                        ?>
                            <article class="mm-history-card">
                                <div class="mm-history-product-head">
                                    <div class="mm-history-thumb">
                                        <?php if ( $thumb ) : ?>
                                            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $name ); ?>">
                                        <?php else : ?>
                                            <span>Sin foto</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h3><?php echo esc_html( $name ); ?></h3>
                                        <p>SKU: <strong><?php echo esc_html( isset( $item->sku ) ? $item->sku : '' ); ?></strong> · Cantidad: <strong><?php echo esc_html( isset( $item->cantidad_contada ) ? intval( $item->cantidad_contada ) : 0 ); ?></strong></p>
                                    </div>
                                </div>

                                <div class="mm-history-timeline">
                                    <?php foreach ( $events as $event ) : ?>
                                        <div class="mm-history-event mm-history-<?php echo esc_attr( $event['type'] ); ?>">
                                            <span class="mm-history-event-icon"><?php echo esc_html( $event['icon'] ); ?></span>
                                            <div>
                                                <strong><?php echo esc_html( $event['title'] ); ?></strong>
                                                <p><?php echo esc_html( $event['text'] ); ?></p>
                                                <?php if ( ! empty( $event['date'] ) ) : ?>
                                                    <small><?php echo esc_html( $event['date'] ); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }
}
