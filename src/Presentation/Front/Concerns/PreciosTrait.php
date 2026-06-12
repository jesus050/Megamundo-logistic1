<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait PreciosTrait {
    private function get_item_multi_prices_meta( $lote_id ) {
        $prices = get_post_meta( intval( $lote_id ), '_mm_items_multi_prices', true );
        return is_array( $prices ) ? $prices : array();
    }

    private function get_item_multi_price_values( $lote_id, $item ) {
        $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
        $meta = $this->get_item_multi_prices_meta( $lote_id );

        $detal = isset( $item->precio_propuesto ) ? floatval( $item->precio_propuesto ) : 0;
        $mayor = 0;
        $gran_mayor = 0;

        if ( $item_id && isset( $meta[ $item_id ] ) && is_array( $meta[ $item_id ] ) ) {
            $detal = isset( $meta[ $item_id ]['detal'] ) ? floatval( $meta[ $item_id ]['detal'] ) : $detal;
            $mayor = isset( $meta[ $item_id ]['mayor'] ) ? floatval( $meta[ $item_id ]['mayor'] ) : 0;
            $gran_mayor = isset( $meta[ $item_id ]['gran_mayor'] ) ? floatval( $meta[ $item_id ]['gran_mayor'] ) : 0;
        }

        if ( $mayor <= 0 && $detal > 0 ) {
            $mayor = $detal;
        }
        if ( $gran_mayor <= 0 && $mayor > 0 ) {
            $gran_mayor = $mayor;
        }

        return array(
            'detal'      => $detal,
            'mayor'      => $mayor,
            'gran_mayor' => $gran_mayor,
        );
    }

    private function calculate_margin_percent_safe( $cost, $price ) {
        $cost = floatval( $cost );
        $price = floatval( $price );
        if ( $price <= 0 ) {
            return 0;
        }
        return round( ( ( $price - $cost ) / $price ) * 100, 1 );
    }

    private function validar_precios_multiples_item( $cost, $detal, $mayor, $gran_mayor ) {
        $cost = floatval( $cost );
        $detal = floatval( $detal );
        $mayor = floatval( $mayor );
        $gran_mayor = floatval( $gran_mayor );

        if ( $detal <= 0 || $mayor <= 0 || $gran_mayor <= 0 ) {
            return 'Los tres precios deben estar completos.';
        }

        if ( $cost > 0 && ( $detal < $cost || $mayor < $cost || $gran_mayor < $cost ) ) {
            return 'Ningún precio debería quedar por debajo del costo.';
        }

        if ( ! ( $detal >= $mayor && $mayor >= $gran_mayor ) ) {
            return 'La lógica recomendada es: detal ≥ mayor ≥ gran mayor.';
        }

        return '';
    }

    public function ajax_guardar_precios_multiples() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para guardar precios.' ), 403 );
            }

            $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

            if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_precios_multiples_' . $lote_id ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
            $existing = $this->get_item_multi_prices_meta( $lote_id );

            $detal_values = isset( $_POST['precio_detal'] ) && is_array( $_POST['precio_detal'] ) ? wp_unslash( $_POST['precio_detal'] ) : array();
            $mayor_values = isset( $_POST['precio_mayor'] ) && is_array( $_POST['precio_mayor'] ) ? wp_unslash( $_POST['precio_mayor'] ) : array();
            $gran_values  = isset( $_POST['precio_gran_mayor'] ) && is_array( $_POST['precio_gran_mayor'] ) ? wp_unslash( $_POST['precio_gran_mayor'] ) : array();

            $warnings = array();

            foreach ( $items as $item ) {
                $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
                if ( ! $item_id ) {
                    continue;
                }

                $cost = isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0;
                $old = $this->get_item_multi_price_values( $lote_id, $item );

                $detal = isset( $detal_values[ $item_id ] ) ? floatval( preg_replace( '/[^0-9\.]/', '', sanitize_text_field( $detal_values[ $item_id ] ) ) ) : $old['detal'];
                $mayor = isset( $mayor_values[ $item_id ] ) ? floatval( preg_replace( '/[^0-9\.]/', '', sanitize_text_field( $mayor_values[ $item_id ] ) ) ) : $old['mayor'];
                $gran  = isset( $gran_values[ $item_id ] ) ? floatval( preg_replace( '/[^0-9\.]/', '', sanitize_text_field( $gran_values[ $item_id ] ) ) ) : $old['gran_mayor'];

                $validation = $this->validar_precios_multiples_item( $cost, $detal, $mayor, $gran );
                if ( $validation ) {
                    $warnings[] = 'SKU ' . ( $item->sku ?? $item_id ) . ': ' . $validation;
                }

                $existing[ $item_id ] = array(
                    'detal'      => $detal,
                    'mayor'      => $mayor,
                    'gran_mayor' => $gran,
                    'updated_at'  => current_time( 'mysql' ),
                    'updated_by'  => get_current_user_id(),
                );
            }

            update_post_meta( $lote_id, '_mm_items_multi_prices', $existing );
            update_post_meta( $lote_id, '_mm_multi_prices_updated_at', current_time( 'mysql' ) );
            update_post_meta( $lote_id, '_mm_multi_prices_updated_by', get_current_user_id() );

            wp_send_json_success( array(
                'message'  => empty( $warnings ) ? 'Precios múltiples guardados correctamente.' : 'Precios guardados con advertencias.',
                'warnings' => $warnings,
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error guardando precios múltiples: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => 'No se pudieron guardar los precios múltiples.',
                'technical' => current_user_can( 'manage_options' ) ? $e->getMessage() : '',
            ), 500 );
        }
    }

    private function render_multi_prices_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

        if ( empty( $items ) ) {
            return '';
        }

        $can_edit = $this->permission_guard->can_access_precios_panel() || $this->permission_guard->can_access_jefatura_panel();
        $nonce = wp_create_nonce( 'mm_precios_multiples_' . $lote_id );

        ob_start(); ?>
        <section class="mm-platform-section mm-multi-prices-panel">
            <div class="mm-section-head">
                <h2>Precios múltiples</h2>
                <span>Precio detal, precio por mayor y precio gran mayor. La etiqueta principal usa precio detal.</span>
            </div>

            <form class="mm-multi-prices-form">
                <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                <div class="mm-multi-prices-table-wrap">
                    <table class="mm-multi-prices-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Producto</th>
                                <th>Costo</th>
                                <th>Detal</th>
                                <th>Mayor</th>
                                <th>Gran mayor</th>
                                <th>Márgenes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $items as $item ) :
                                $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
                                $product_id = isset( $item->producto_id ) ? intval( $item->producto_id ) : 0;
                                $name = $product_id ? get_the_title( $product_id ) : 'Producto sin nombre';
                                $sku = isset( $item->sku ) ? $item->sku : '';
                                $cost = isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0;
                                $prices = $this->get_item_multi_price_values( $lote_id, $item );
                                $warning = $this->validar_precios_multiples_item( $cost, $prices['detal'], $prices['mayor'], $prices['gran_mayor'] );
                            ?>
                                <tr class="<?php echo $warning ? 'has-price-warning' : ''; ?>">
                                    <td><strong><?php echo esc_html( $sku ); ?></strong></td>
                                    <td><?php echo esc_html( $name ); ?><?php if ( $warning ) : ?><small class="mm-price-warning"><?php echo esc_html( $warning ); ?></small><?php endif; ?></td>
                                    <td>$<?php echo esc_html( number_format( $cost, 0, ',', '.' ) ); ?></td>
                                    <td><input class="mm-input mm-price-input" type="number" min="0" step="1" name="precio_detal[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( intval( $prices['detal'] ) ); ?>" <?php disabled( ! $can_edit ); ?>></td>
                                    <td><input class="mm-input mm-price-input" type="number" min="0" step="1" name="precio_mayor[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( intval( $prices['mayor'] ) ); ?>" <?php disabled( ! $can_edit ); ?>></td>
                                    <td><input class="mm-input mm-price-input" type="number" min="0" step="1" name="precio_gran_mayor[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( intval( $prices['gran_mayor'] ) ); ?>" <?php disabled( ! $can_edit ); ?>></td>
                                    <td>
                                        <div class="mm-margin-stack">
                                            <span>Detal: <?php echo esc_html( $this->calculate_margin_percent_safe( $cost, $prices['detal'] ) ); ?>%</span>
                                            <span>Mayor: <?php echo esc_html( $this->calculate_margin_percent_safe( $cost, $prices['mayor'] ) ); ?>%</span>
                                            <span>G. mayor: <?php echo esc_html( $this->calculate_margin_percent_safe( $cost, $prices['gran_mayor'] ) ); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $can_edit ) : ?>
                    <div class="mm-multi-prices-actions">
                        <button type="submit" class="mm-mini-primary">Guardar precios múltiples</button>
                        <span>Regla recomendada: detal ≥ mayor ≥ gran mayor ≥ costo.</span>
                    </div>
                    <div class="mm-multi-prices-msg" hidden></div>
                <?php endif; ?>
            </form>
        </section>
        <?php return ob_get_clean();
    }

    private function mm_marcar_lote_pendiente_precios( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) {
            return;
        }

        update_post_meta( $lote_id, '_mm_lote_estado', 'pendiente_precios' );
        update_post_meta( $lote_id, '_mm_precios_estado', 'pendiente' );
        update_post_meta( $lote_id, '_mm_bodega_finalizada', current_time( 'mysql' ) );

        $pendientes = get_option( 'mm_lotes_pendientes_precios', array() );
        if ( ! is_array( $pendientes ) ) {
            $pendientes = array();
        }

        $pendientes[] = $lote_id;
        update_option( 'mm_lotes_pendientes_precios', array_values( array_unique( array_map( 'intval', $pendientes ) ) ) );
    }

    private function mm_get_lotes_pendientes_precios() {
        $pendientes = get_option( 'mm_lotes_pendientes_precios', array() );
        return is_array( $pendientes ) ? array_values( array_unique( array_map( 'intval', $pendientes ) ) ) : array();
    }

    private function mm_get_productos_lote_para_precios( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) {
            return array();
        }

        foreach ( array(
            'mm_lote_' . $lote_id . '_received_products',
            'mm_lote_' . $lote_id . '_expected_products',
            'mm_lote_expected_products_' . $lote_id,
        ) as $key ) {
            $items = get_option( $key, array() );
            if ( is_array( $items ) && ! empty( $items ) ) {
                return $items;
            }
        }

        foreach ( array(
            '_mm_pedido_productos_internos_lote',
            '_mm_lote_productos_esperados',
            '_mm_productos_esperados',
            '_mm_pedido_productos_internos',
        ) as $key ) {
            $items = get_post_meta( $lote_id, $key, true );
            if ( is_array( $items ) && ! empty( $items ) ) {
                return $items;
            }
        }

        return array();
    }

    private function mm_render_precios_pendientes_panel() {
        $lotes = $this->mm_get_lotes_pendientes_precios();

        ob_start(); ?>
        <section class="mm-platform-section mm-precios-pendientes-panel">
            <div class="mm-section-head">
                <h2>🏷️ Lotes pendientes de precios</h2>
                <span>Lotes finalizados por Bodega listos para asignar precio detal, mayor y gran mayor.</span>
            </div>

            <?php if ( empty( $lotes ) ) : ?>
                <div class="mm-empty-state">No hay lotes pendientes de precios.</div>
            <?php else : ?>
                <div class="mm-precios-lotes-grid">
                    <?php foreach ( $lotes as $lote_id ) :
                        $productos = $this->mm_get_productos_lote_para_precios( $lote_id );
                        ?>
                        <article class="mm-precios-lote-card">
                            <small>Lote</small>
                            <strong>#<?php echo esc_html( $lote_id ); ?></strong>
                            <span><?php echo esc_html( count( $productos ) ); ?> producto(s)</span>
                            <a class="mm-mini-primary" href="<?php echo esc_url( add_query_arg( array( 'mm_logistica_app' => 'precios', 'lote_id' => $lote_id ), home_url( '/' ) ) ); ?>">Asignar precios</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }

    private function mm_render_precios_lote_actual_panel() {
        $lote_id = isset( $_GET['lote_id'] ) ? intval( $_GET['lote_id'] ) : 0;
        if ( $lote_id <= 0 ) {
            return '';
        }

        $productos = $this->mm_get_productos_lote_para_precios( $lote_id );

        ob_start(); ?>
        <section class="mm-platform-section mm-precios-lote-actual-panel">
            <div class="mm-section-head">
                <h2>Asignar precios al lote #<?php echo esc_html( $lote_id ); ?></h2>
                <span>Completa precio detal, mayor y gran mayor.</span>
            </div>

            <?php if ( empty( $productos ) ) : ?>
                <div class="mm-empty-state">Este lote no tiene productos cargados para precios.</div>
            <?php else : ?>
                <div class="mm-precios-table-wrap">
                    <table class="mm-precios-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Costo</th>
                                <th>Detal</th>
                                <th>Mayor</th>
                                <th>Gran mayor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $productos as $index => $product ) :
                                if ( ! is_array( $product ) ) { continue; }
                                $codigo = $product['codigo'] ?? $product['sku'] ?? '';
                                $nombre = $product['nombre'] ?? $product['producto'] ?? $product['descripcion'] ?? '';
                                $cantidad = intval( $product['recibido'] ?? $product['cantidad'] ?? $product['esperado'] ?? 0 );
                                $costo = floatval( $product['costo'] ?? 0 );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $codigo ); ?></td>
                                    <td><?php echo $this->mm_render_bodega_img_nombre( $product, $nombre ); ?></td>
                                    <td><?php echo esc_html( $cantidad ); ?></td>
                                    <td><?php echo esc_html( number_format( $costo, 0, ',', '.' ) ); ?></td>
                                    <td><input class="mm-input" type="number" min="0" step="1" name="productos[<?php echo esc_attr( $index ); ?>][precio_detal]"></td>
                                    <td><input class="mm-input" type="number" min="0" step="1" name="productos[<?php echo esc_attr( $index ); ?>][precio_mayor]"></td>
                                    <td><input class="mm-input" type="number" min="0" step="1" name="productos[<?php echo esc_attr( $index ); ?>][precio_gran_mayor]"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }

    private function render_precios_dashboard() {
        $pendientes_precios = $this->lote_repo->find_by_status( 'mm_p_precios' );
        $conteo             = $this->lote_repo->find_by_status( 'draft' );
        $publicados         = $this->lote_repo->find_by_status( 'publish' );

        // En el panel de precios deben aparecer los lotes que aún necesitan liquidación:
        // borrador/en conteo, pendiente de precios y publicados que todavía no estén cargados.
        $lotes_para_liquidar = array();
        foreach ( array_merge( $pendientes_precios, $conteo, $publicados ) as $lote ) {
            if ( ! isset( $lote->ID ) ) {
                continue;
            }
            $lotes_para_liquidar[ intval( $lote->ID ) ] = $lote;
        }
        $pendientes = array_values( $lotes_para_liquidar );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'precios' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header">
                    <div><span class="mm-eyebrow">Panel de Precios</span><h1>Liquidación de mercancía</h1><p>Revisa lotes cerrados por bodega, completa costos, precios y márgenes antes de enviarlos a jefatura.</p></div>
                </header>
                <div class="mm-role-summary-grid">
                    <div class="mm-role-summary-card"><small>Por liquidar</small><strong><?php echo esc_html( count( $pendientes ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>En borrador</small><strong><?php echo esc_html( count( $conteo ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Pendiente precios</small><strong><?php echo esc_html( count( $pendientes_precios ) ); ?></strong></div>
                </div>
                <section class="mm-platform-section">
                    <div class="mm-section-head"><h2>Lotes para liquidar</h2><span>Aquí aparecen lotes en borrador, publicados o pendientes de precios para asignar costos y precios.</span></div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $pendientes ) ) : ?><div class="mm-empty-state">No hay lotes disponibles para liquidar.</div><?php endif; ?>
                        <?php foreach ( $pendientes as $lote ) { echo $this->render_lote_card( $lote, 'precios' ); } ?>
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

    private function price_app_allowed_statuses() {
        return array( 'draft', 'publish', 'mm_p_precios' );
    }

    private function user_can_edit_prices_in_app( $lote_id ) {
        if ( $this->lote_repo->is_synchronized( $lote_id ) ) {
            return false;
        }

        $status = $this->lote_repo->get_status( $lote_id );

        // Precios: liquida costos y precio propuesto antes de aprobación.
        if ( $this->permission_guard->can_edit_finance() && in_array( $status, $this->price_app_allowed_statuses(), true ) ) {
            return true;
        }

        // Jefatura: puede ajustar el precio final antes de aprobar/cargar.
        if ( $this->permission_guard->is_admin() && 'mm_p_aprobacion' === $status ) {
            return true;
        }

        return false;
    }

    private function user_can_send_to_approval_in_app( $lote_id ) {
        if ( ! $this->permission_guard->can_edit_finance() || $this->permission_guard->is_admin() ) {
            return false;
        }
        $status = $this->lote_repo->get_status( $lote_id );
        return in_array( $status, $this->price_app_allowed_statuses(), true ) && ! $this->lote_repo->is_synchronized( $lote_id );
    }

    public function ajax_guardar_precios_app() {
        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_app_precios_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            wp_send_json_error( array( 'message' => 'El lote no existe.' ), 404 );
        }

        if ( ! $this->user_can_edit_prices_in_app( $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'No puedes editar precios en este lote o el lote ya fue cargado.' ), 403 );
        }

        $items_post = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? $_POST['items'] : array();
        if ( empty( $items_post ) ) {
            wp_send_json_error( array( 'message' => 'No se recibieron productos para actualizar.' ), 400 );
        }

        $old_items = $this->item_repo->get_by_lote( $lote_id );
        $old_by_id = array();
        foreach ( $old_items as $old_item ) {
            $old_by_id[ intval( $old_item->id ) ] = $old_item;
        }

        $updated = 0;
        foreach ( $items_post as $item_id => $values ) {
            $item_id = absint( $item_id );
            if ( ! isset( $old_by_id[ $item_id ] ) ) {
                continue;
            }
            $costo  = isset( $values['costo_ia'] ) ? max( 0, floatval( $values['costo_ia'] ) ) : 0;
            $precio = isset( $values['precio_propuesto'] ) ? max( 0, floatval( $values['precio_propuesto'] ) ) : 0;

            $old = $old_by_id[ $item_id ];
            $old_costo = floatval( $old->costo_ia );
            $old_precio = floatval( $old->precio_propuesto );

            if ( abs( $old_costo - $costo ) > 0.0001 ) {
                if ( property_exists( $this, 'audit_repo' ) && $this->audit_repo ) {
                    $this->audit_repo->add_log( $lote_id, 'costo_modificado', sprintf( 'Costo del producto SKU %s modificado desde la plataforma', $old->sku ), $item_id, $old->producto_id, $old->sku, $old_costo, $costo );
                }
            }
            if ( abs( $old_precio - $precio ) > 0.0001 ) {
                if ( property_exists( $this, 'audit_repo' ) && $this->audit_repo ) {
                    $this->audit_repo->add_log( $lote_id, 'precio_modificado', sprintf( 'Precio propuesto del producto SKU %s modificado desde la plataforma', $old->sku ), $item_id, $old->producto_id, $old->sku, $old_precio, $precio );
                }
            }
            $this->item_repo->update_prices( $item_id, $lote_id, $costo, $precio );
            $updated++;
        }

        wp_send_json_success( array( 'message' => 'Precios guardados correctamente.', 'updated' => $updated ) );
    }

    public function ajax_enviar_aprobacion_app() {
        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_app_precios_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }
        if ( ! $this->user_can_edit_prices_in_app( $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para enviar este lote a aprobación.' ), 403 );
        }
        if ( $this->item_repo->count_distinct_items( $lote_id ) === 0 ) {
            wp_send_json_error( array( 'message' => 'No puedes enviar un lote sin productos.' ), 400 );
        }
        $sin_precio = $this->item_repo->count_items_without_price( $lote_id );
        if ( $sin_precio > 0 ) {
            wp_send_json_error( array( 'message' => 'Faltan precios en ' . $sin_precio . ' producto(s).' ), 400 );
        }

        $this->lote_repo->update_status( $lote_id, 'mm_p_aprobacion' );
        wp_send_json_success( array( 'message' => 'Lote enviado a aprobación correctamente.' ) );
    }
}
