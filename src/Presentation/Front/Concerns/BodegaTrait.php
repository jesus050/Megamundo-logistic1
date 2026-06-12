<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait BodegaTrait {
    private function mm_get_lote_expected_products_for_bodega( $lote_id ) {
        global $wpdb;

        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) {
            return array();
        }

        $expected = array();

        // 1. Intentar desde tabla de pedidos si existe.
        $tables = array(
            $wpdb->prefix . 'mm_pedidos',
            $wpdb->prefix . 'mm_pedidos_internos',
            $wpdb->prefix . 'mm_lote_pedidos',
        );

        foreach ( $tables as $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists !== $table ) {
                continue;
            }

            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE lote_id = %d OR id_lote = %d ORDER BY id DESC LIMIT 20", $lote_id, $lote_id ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                foreach ( array( 'productos', 'productos_pedido', 'detalle_productos', 'items', 'metadata' ) as $field ) {
                    if ( empty( $row[ $field ] ) ) {
                        continue;
                    }
                    $decoded = json_decode( $row[ $field ], true );
                    if ( is_array( $decoded ) ) {
                        $expected = isset( $decoded['productos_detectados'] ) ? $decoded['productos_detectados'] : $decoded;
                        break 3;
                    }
                }
            }
        }

        // 2. Intentar desde opción auxiliar si fue guardada por el flujo de pedidos.
        if ( empty( $expected ) ) {
            $option_products = get_option( 'mm_lote_' . $lote_id . '_expected_products', array() );
            if ( is_array( $option_products ) ) {
                $expected = $option_products;
            }
        }

        return is_array( $expected ) ? $expected : array();
    }

    private function mm_render_bodega_expected_products_panel( $lote_id ) {
        $products = $this->mm_get_lote_expected_products_for_bodega( $lote_id );

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-expected-products-panel">
            <div class="mm-section-head">
                <h2>📋 Productos esperados del pedido</h2>
                <span>Esta lista viene del pedido. Bodega solo ve código, producto, cantidad y observación.</span>
            </div>

            <?php if ( empty( $products ) ) : ?>
                <div class="mm-empty-state">Este lote todavía no tiene productos esperados asociados al pedido.</div>
            <?php else : ?>
                <div class="mm-bodega-expected-table-wrap">
                    <table class="mm-bodega-expected-table">
                        <thead>
                            <tr>
                                <th>Código / SKU</th>
                                <th>Producto</th>
                                <th>Cantidad esperada</th>
                                <th>Observación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $products as $product ) :
                                if ( ! is_array( $product ) ) {
                                    continue;
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $product['codigo'] ?? $product['sku'] ?? '' ); ?></td>
                                    <td><strong><?php echo esc_html( $product['nombre'] ?? $product['producto'] ?? $product['descripcion'] ?? '' ); ?></strong></td>
                                    <td><?php echo esc_html( intval( $product['cantidad'] ?? 0 ) ); ?></td>
                                    <td><?php echo esc_html( $product['observacion'] ?? $product['nota'] ?? '' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }

    private function mm_get_expected_products_for_selected_lote( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) {
            return array();
        }

        $keys = array(
            'mm_lote_' . $lote_id . '_expected_products',
            'mm_lote_' . $lote_id . '_received_products',
            'mm_lote_expected_products_' . $lote_id,
        );

        foreach ( $keys as $key ) {
            $items = get_option( $key, array() );
            if ( is_array( $items ) && ! empty( $items ) ) {
                return $items;
            }
        }

        $meta_keys = array(
            '_mm_pedido_productos_internos_lote',
            '_mm_productos_esperados',
            '_mm_lote_productos_esperados',
            '_mm_pedido_productos_internos',
        );

        foreach ( $meta_keys as $key ) {
            $items = get_post_meta( $lote_id, $key, true );
            if ( is_array( $items ) && ! empty( $items ) ) {
                return $items;
            }
        }

        return array();
    }

    private function mm_render_bodega_selected_lote_products( $lote_id ) {
        $products = $this->mm_get_expected_products_for_selected_lote( $lote_id );

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-selected-products">
            <div class="mm-section-head">
                <h2>📋 Productos del lote seleccionado</h2>
                <span>Se cargan automáticamente al seleccionar el lote.</span>
            </div>

            <?php if ( empty( $products ) ) : ?>
                <div class="mm-empty-state">Este lote no tiene productos asociados todavía. Revisa que el pedido haya creado el lote con productos.</div>
            <?php else : ?>
                <div class="mm-bodega-selected-table-wrap">
                    <table class="mm-bodega-selected-table">
                        <thead>
                            <tr>
                                <th>Código / SKU</th>
                                <th>Producto</th>
                                <th>Cantidad esperada</th>
                                <th>Observación</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $products as $product ) :
                            if ( ! is_array( $product ) ) { continue; }
                            $codigo = $product['codigo'] ?? $product['sku'] ?? '';
                            $nombre = $product['nombre'] ?? $product['producto'] ?? $product['descripcion'] ?? '';
                            $cantidad = intval( $product['cantidad'] ?? $product['esperado'] ?? $product['recibido'] ?? 0 );
                            $observacion = $product['observacion'] ?? $product['nota'] ?? '';
                            ?>
                            <tr>
                                <td><?php echo esc_html( $codigo ); ?></td>
                                <td><span class="mm-bodega-product-with-image"><?php echo $this->mm_render_bodega_producto_imagen( $product ); ?><strong><?php echo esc_html( $nombre ); ?></strong></span></td>
                                <td><?php echo esc_html( $cantidad ); ?></td>
                                <td><?php echo esc_html( $observacion ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }


    private function mm_get_current_lote_id_from_request() {
        foreach ( array( 'lote_id', 'lote', 'id_lote', 'mm_lote_id' ) as $key ) {
            if ( isset( $_GET[ $key ] ) ) {
                return intval( $_GET[ $key ] );
            }
        }
        return 0;
    }

    private function mm_validar_productos_bodega_para_finalizar( $products ) {
        $errors = array();
        $complete = 0;
        $pending = 0;

        foreach ( (array) $products as $index => $product ) {
            if ( ! is_array( $product ) ) {
                continue;
            }

            $codigo = trim( sanitize_text_field( $product['codigo'] ?? $product['sku'] ?? '' ) );
            $nombre = trim( sanitize_text_field( $product['nombre'] ?? $product['producto'] ?? $product['descripcion'] ?? '' ) );
            $recibido_raw = $product['recibido'] ?? $product['cantidad_recibida'] ?? '';
            $recibido = is_numeric( $recibido_raw ) ? intval( $recibido_raw ) : 0;

            $row_errors = array();

            if ( '' === $codigo ) {
                $row_errors[] = 'SKU vacío';
            }

            if ( '' === $nombre ) {
                $row_errors[] = 'producto vacío';
            }

            if ( $recibido <= 0 ) {
                $row_errors[] = 'cantidad recibida sin confirmar';
            }

            if ( empty( $row_errors ) ) {
                $complete++;
            } else {
                $pending++;
                $errors[] = array(
                    'index' => $index + 1,
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'errores' => $row_errors,
                );
            }
        }

        return array(
            'ok' => empty( $errors ),
            'complete' => $complete,
            'pending' => $pending,
            'errors' => $errors,
        );
    }

    private function mm_build_bodega_validation_message( $validation ) {
        $message = "No se puede finalizar Bodega todavía.\n\n";
        $message .= 'Productos completos: ' . intval( $validation['complete'] ?? 0 ) . "\n";
        $message .= 'Productos pendientes: ' . intval( $validation['pending'] ?? 0 ) . "\n\n";
        $message .= "Corrige estos productos:\n";

        foreach ( array_slice( (array) ( $validation['errors'] ?? array() ), 0, 12 ) as $error ) {
            $label = ! empty( $error['nombre'] ) ? $error['nombre'] : ( ! empty( $error['codigo'] ) ? $error['codigo'] : 'Producto #' . intval( $error['index'] ?? 0 ) );
            $message .= '• ' . $label . ': ' . implode( ', ', (array) $error['errores'] ) . "\n";
        }

        if ( count( (array) ( $validation['errors'] ?? array() ) ) > 12 ) {
            $message .= '• Y más productos pendientes...' . "\n";
        }

        return $message;
    }



    private function mm_render_bodega_producto_imagen( $product ) {
        $url = '';
        if ( is_array( $product ) ) {
            $url = esc_url( $product['imagen_url'] ?? '' );
            if ( empty( $url ) && ! empty( $product['imagen_id'] ) ) {
                $url = esc_url( wp_get_attachment_url( intval( $product['imagen_id'] ) ) );
            }
        }

        if ( empty( $url ) ) {
            return '<span class="mm-bodega-product-img is-empty">Sin imagen</span>';
        }

        return '<span class="mm-bodega-product-img" style="background-image:url(' . esc_url( $url ) . ');"></span>';
    }



    private function mm_get_product_image_from_product( $product ) {
        if ( ! is_array( $product ) ) {
            return '';
        }

        $url = '';
        foreach ( array( 'imagen_url', 'image_url', 'foto_url', 'thumbnail', 'imagen' ) as $key ) {
            if ( ! empty( $product[ $key ] ) && is_string( $product[ $key ] ) ) {
                $url = esc_url_raw( $product[ $key ] );
                break;
            }
        }

        foreach ( array( 'imagen_id', 'image_id', 'attachment_id', 'foto_id' ) as $key ) {
            if ( empty( $url ) && ! empty( $product[ $key ] ) ) {
                $maybe = wp_get_attachment_url( intval( $product[ $key ] ) );
                if ( $maybe ) {
                    $url = esc_url_raw( $maybe );
                    break;
                }
            }
        }

        return $url;
    }

    private function mm_render_bodega_img_nombre( $product, $nombre ) {
        $url = $this->mm_get_product_image_from_product( $product );
        $nombre = esc_html( $nombre );

        if ( empty( $url ) ) {
            return '<span class="mm-bodega-name-with-img"><span class="mm-bodega-img-mini is-empty">Sin imagen</span><strong>' . $nombre . '</strong></span>';
        }

        return '<span class="mm-bodega-name-with-img"><span class="mm-bodega-img-mini" style="background-image:url(' . esc_url( $url ) . ');"></span><strong>' . $nombre . '</strong></span>';
    }

    private function mm45_expected_products_from_lote( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) { return array(); }
        $keys = array('_mm_pedido_productos_internos_lote','_mm_factura_productos_esperados','_mm_productos_esperados','_mm_lote_productos_esperados');
        foreach ( $keys as $key ) {
            $items = get_post_meta( $lote_id, $key, true );
            if ( is_array( $items ) && ! empty( $items ) ) { return $items; }
        }
        $pedido_id = intval( get_post_meta( $lote_id, '_mm_pedido_origen_id', true ) );
        if ( $pedido_id > 0 ) {
            $items = get_post_meta( $pedido_id, '_mm_pedido_productos_internos', true );
            if ( is_array( $items ) && ! empty( $items ) ) { return $items; }
        }
        $items = get_option( 'mm_lote_' . $lote_id . '_expected_products', array() );
        return is_array( $items ) ? $items : array();
    }

    private function mm45_received_products_from_lote( $lote_id ) {
        $saved = get_option( 'mm_lote_' . intval( $lote_id ) . '_received_products', array() );
        if ( is_array( $saved ) && ! empty( $saved ) ) { return $saved; }
        $expected = $this->mm45_expected_products_from_lote( $lote_id );
        $out = array();
        foreach ( $expected as $p ) {
            if ( ! is_array( $p ) ) { continue; }
            $out[] = array(
                'codigo' => sanitize_text_field( $p['codigo'] ?? $p['sku'] ?? '' ),
                'nombre' => sanitize_text_field( $p['nombre'] ?? $p['producto'] ?? $p['descripcion'] ?? '' ),
                'esperado' => intval( $p['cantidad'] ?? 0 ),
                'recibido' => 0,
                'observacion' => sanitize_text_field( $p['observacion'] ?? $p['nota'] ?? '' ),
            );
        }
        return $out;
    }

    private function mm45_render_bodega_receipt_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) { return ''; }
        $products = $this->mm45_received_products_from_lote( $lote_id );
        $applied = get_option( 'mm_lote_' . $lote_id . '_inventario_aplicado', '' );
        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-receipt-panel">
            <div class="mm-section-head"><h2>✅ Confirmar cantidades recibidas</h2><span>Al finalizar, estas cantidades pasan al stock en bodega de Exhibición.</span></div>
            <?php if ( $applied ) : ?><div class="mm-safe-note"><strong>Inventario aplicado:</strong><p>Este lote ya alimentó el inventario físico el <?php echo esc_html( $applied ); ?>.</p></div><?php endif; ?>
            <?php if ( empty( $products ) ) : ?>
                <div class="mm-empty-state">Este lote todavía no tiene productos esperados asociados al pedido.</div>
            <?php else : ?>
                <form class="mm-bodega-receipt-form">
                    <div class="mm-bodega-validation-summary">
                        <span>🟢 Completos: <strong data-complete-count>0</strong></span>
                        <span>🔴 Pendientes: <strong data-pending-count>0</strong></span>
                    </div>
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_bodega_receipt_' . $lote_id ) ); ?>">
                    <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">
                    <div class="mm-bodega-receipt-table-wrap"><table class="mm-bodega-receipt-table"><thead><tr><th>Código</th><th>Producto</th><th>Pedido</th><th>Recibido real</th><th>Diferencia</th><th>Observación</th></tr></thead><tbody>
                    <?php foreach ( $products as $i => $p ) : $esp=intval($p['esperado'] ?? 0); $rec=intval($p['recibido'] ?? 0); ?>
                        <tr><td><input type="hidden" name="productos[<?php echo esc_attr($i); ?>][codigo]" value="<?php echo esc_attr($p['codigo'] ?? ''); ?>"><?php echo esc_html($p['codigo'] ?? ''); ?></td><td><input type="hidden" name="productos[<?php echo esc_attr($i); ?>][nombre]" value="<?php echo esc_attr($p['nombre'] ?? ''); ?>"><strong><?php echo esc_html($p['nombre'] ?? ''); ?></strong></td><td><input type="hidden" name="productos[<?php echo esc_attr($i); ?>][esperado]" value="<?php echo esc_attr($esp); ?>"><?php echo esc_html($esp); ?></td><td><input class="mm-input mm-received-input" type="number" min="0" step="1" name="productos[<?php echo esc_attr($i); ?>][recibido]" value="<?php echo esc_attr($rec); ?>" data-esperado="<?php echo esc_attr($esp); ?>"></td><td><span class="mm-receipt-diff"><?php echo esc_html($rec-$esp); ?></span></td><td><input class="mm-input" type="text" name="productos[<?php echo esc_attr($i); ?>][observacion]" value="<?php echo esc_attr($p['observacion'] ?? ''); ?>"></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div><div class="mm-bodega-receipt-actions"><button type="submit" class="mm-mini-secondary">Guardar cantidades</button><button type="button" class="mm-mini-primary mm-finalizar-bodega-lote">Finalizar Bodega y alimentar inventario</button></div><div class="mm-bodega-receipt-msg" hidden></div>
                </form>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }

    public function ajax_guardar_bodega_receipt() {
        if ( ! is_user_logged_in() || ! $this->permission_guard->can_access_bodega_panel() ) { wp_send_json_error(array('message'=>'No tienes permiso para registrar recepción de bodega.'),403); }
        $lote_id = isset($_POST['lote_id']) ? intval($_POST['lote_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ( $lote_id <= 0 || ! wp_verify_nonce( $nonce, 'mm_bodega_receipt_' . $lote_id ) ) { wp_send_json_error(array('message'=>'Sesión vencida o lote inválido.'),403); }
        $raw = isset($_POST['productos']) && is_array($_POST['productos']) ? wp_unslash($_POST['productos']) : array();
        $products = array();
        foreach ($raw as $p) { if (is_array($p)) $products[] = array('codigo'=>sanitize_text_field($p['codigo']??''),'nombre'=>sanitize_text_field($p['nombre']??''),'esperado'=>intval($p['esperado']??0),'recibido'=>intval($p['recibido']??0),'observacion'=>sanitize_text_field($p['observacion']??'')); }
        update_option('mm_lote_'.$lote_id.'_received_products',$products);
        wp_send_json_success(array('message'=>'Cantidades recibidas guardadas.'));
    }

    public function ajax_finalizar_bodega_lote() {
        $this->ajax_guardar_bodega_receipt_no_exit();
    }

    private function ajax_guardar_bodega_receipt_no_exit() {
        if ( ! is_user_logged_in() || ! $this->permission_guard->can_access_bodega_panel() ) { wp_send_json_error(array('message'=>'No tienes permiso para finalizar bodega.'),403); }
        $lote_id = isset($_POST['lote_id']) ? intval($_POST['lote_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ( $lote_id <= 0 || ! wp_verify_nonce( $nonce, 'mm_bodega_receipt_' . $lote_id ) ) { wp_send_json_error(array('message'=>'Sesión vencida o lote inválido.'),403); }
        $raw = isset($_POST['productos']) && is_array($_POST['productos']) ? wp_unslash($_POST['productos']) : array();
        $products = array();
        foreach ($raw as $p) { if (is_array($p)) $products[] = array('codigo'=>sanitize_text_field($p['codigo']??''),'nombre'=>sanitize_text_field($p['nombre']??''),'esperado'=>intval($p['esperado']??0),'recibido'=>intval($p['recibido']??0),'observacion'=>sanitize_text_field($p['observacion']??'')); }
        update_option('mm_lote_'.$lote_id.'_received_products',$products);
        $updated = $this->mm45_apply_lote_to_exhibicion_stock($lote_id);
        wp_send_json_success(array('message'=>'Inventario físico actualizado. Productos actualizados: '.$updated,'redirect'=>add_query_arg('mm_logistica_app','exhibicion',home_url('/'))));
    }
}
