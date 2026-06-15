<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait PedidosTrait {
    public function ajax_analizar_pedido_ia() {
        try {
            if ( ! $this->can_access_pedidos_panel() ) { wp_send_json_error( array( 'message' => 'No tienes permiso para analizar pedidos con IA.' ), 403 ); }
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_pedido_ia' ) ) { wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 ); }
            $texto_manual = isset( $_POST['texto_factura'] ) ? sanitize_textarea_field( wp_unslash( $_POST['texto_factura'] ) ) : '';
            $result = $this->openai_service->analizar_pedido_con_openai_directo( $texto_manual );
            if ( ! is_array( $result ) || ! empty( $result['error'] ) ) { wp_send_json_error( array( 'message' => is_array($result)&&!empty($result['error'])?$result['error']:'No se pudo analizar.', 'data'=>$result ), 500 ); }
            wp_send_json_success( array( 'message'=>'Pedido analizado con OpenAI directo. Revisa antes de guardar.', 'data'=>$result ) );
        } catch ( \Throwable $e ) { error_log('[MegaMundo] Error OpenAI pedido: '.$e->getMessage()); wp_send_json_error( array( 'message'=> current_user_can('manage_options') ? 'Error OpenAI directo: '.$e->getMessage() : 'No se pudo analizar automáticamente.' ), 500 ); }
    }

    public function register_pedidos_post_type() {
        register_post_type( 'mm_pedido_compra', array(
            'labels' => array(
                'name'          => 'Pedidos / Compras',
                'singular_name' => 'Pedido / Compra',
            ),
            'public'       => false,
            'show_ui'      => false,
            'show_in_menu' => false,
            'supports'     => array( 'title', 'author' ),
            'capability_type' => 'post',
        ) );
    }

    private function can_access_pedidos_panel() {
        return is_user_logged_in() && (
            $this->permission_guard->can_access_bodega_panel()
            || $this->permission_guard->can_access_precios_panel()
            || $this->permission_guard->can_access_jefatura_panel()
        );
    }

    private function pedido_status_label( $status ) {
        $labels = array(
            'pedido_creado'      => 'Pedido creado',
            'esperando_factura'  => 'Esperando factura',
            'factura_cargada'    => 'Factura cargada',
            'mercancia_camino'   => 'Mercancía en camino',
            'lote_creado'        => 'Lote creado',
            'en_bodega'          => 'En bodega',
            'bodega_finalizada'  => 'Bodega finalizada',
            'en_precios'         => 'En precios',
            'en_jefatura'        => 'En jefatura',
            'cargado_sistema'    => 'Cargado al sistema',
            'cerrado'            => 'Cerrado',
        );

        return $labels[ $status ] ?? 'Pedido creado';
    }

    private function get_pedidos_compra() {
        return get_posts( array(
            'post_type'      => 'mm_pedido_compra',
            'post_status'    => array( 'publish', 'draft' ),
            'posts_per_page' => 80,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
    }

    public function ajax_guardar_pedido_compra() {
        try {
            if ( ! $this->can_access_pedidos_panel() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para crear pedidos.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_guardar_pedido_compra' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $proveedor = isset( $_POST['proveedor'] ) ? sanitize_text_field( wp_unslash( $_POST['proveedor'] ) ) : '';
            $fecha = isset( $_POST['fecha_pedido'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha_pedido'] ) ) : current_time( 'Y-m-d' );
            $valor = isset( $_POST['valor_estimado'] ) ? floatval( preg_replace( '/[^0-9\.]/', '', wp_unslash( $_POST['valor_estimado'] ) ) ) : 0;
            $estado_factura = isset( $_POST['estado_factura'] ) ? sanitize_key( wp_unslash( $_POST['estado_factura'] ) ) : 'pendiente';
            $factura_numero = isset( $_POST['factura_numero'] ) ? sanitize_text_field( wp_unslash( $_POST['factura_numero'] ) ) : '';
            $factura_total = isset( $_POST['factura_total'] ) ? floatval( preg_replace( '/[^0-9\.]/', '', wp_unslash( $_POST['factura_total'] ) ) ) : 0;
            $observaciones = isset( $_POST['observaciones'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ) ) : '';
            $productos_texto = isset( $_POST['productos_pedido'] ) ? sanitize_textarea_field( wp_unslash( $_POST['productos_pedido'] ) ) : '';
            $productos_internos = $this->parse_pedido_productos_internos( $productos_texto );
            $crear_lote = ! empty( $_POST['crear_lote'] ) || ! empty( $_POST['crear_lote_bodega'] ) || ! empty( $_POST['crear_lote_provisional'] );

            if ( empty( $proveedor ) ) {
                wp_send_json_error( array( 'message' => 'Escribe el proveedor del pedido.' ), 400 );
            }

            $pedido_id = wp_insert_post( array(
                'post_type'   => 'mm_pedido_compra',
                'post_status' => 'publish',
                'post_title'  => 'Pedido - ' . $proveedor . ' - ' . $fecha,
                'post_author' => get_current_user_id(),
            ) );

            if ( is_wp_error( $pedido_id ) || ! $pedido_id ) {
                wp_send_json_error( array( 'message' => 'No se pudo crear el pedido.' ), 500 );
            }

            $estado_pedido = 'pendiente' === $estado_factura ? 'esperando_factura' : 'factura_cargada';

            update_post_meta( $pedido_id, '_mm_pedido_proveedor', $proveedor );
            update_post_meta( $pedido_id, '_mm_pedido_fecha', $fecha );
            update_post_meta( $pedido_id, '_mm_pedido_valor_estimado', $valor );
            update_post_meta( $pedido_id, '_mm_pedido_estado_factura', $estado_factura );
            update_post_meta( $pedido_id, '_mm_pedido_factura_numero', $factura_numero );
            update_post_meta( $pedido_id, '_mm_pedido_factura_total', $factura_total );
            update_post_meta( $pedido_id, '_mm_pedido_observaciones', $observaciones );
            update_post_meta( $pedido_id, '_mm_pedido_productos_texto', $productos_texto );
            update_post_meta( $pedido_id, '_mm_pedido_productos_internos', $productos_internos );
            update_post_meta( $pedido_id, '_mm_pedido_productos_count', count( $productos_internos ) );
            update_post_meta( $pedido_id, '_mm_pedido_estado', $estado_pedido );
            update_post_meta( $pedido_id, '_mm_pedido_created_at', current_time( 'mysql' ) );

            $upload = array( 'url' => '', 'attachment_id' => 0 );
            if ( ! empty( $_FILES['soporte_pedido']['name'] ) && method_exists( $this, 'mm_handle_factura_soporte_upload' ) ) {
                $upload = $this->mm_handle_factura_soporte_upload( 'soporte_pedido' );
                if ( empty( $upload['error'] ) ) {
                    update_post_meta( $pedido_id, '_mm_pedido_soporte_url', $upload['url'] ?? '' );
                    update_post_meta( $pedido_id, '_mm_pedido_soporte_id', $upload['attachment_id'] ?? 0 );
                }
            }

            $lote_id = 0;
            if ( $crear_lote ) {
                $lote_id = $this->crear_lote_interno_desde_pedido( $pedido_id );
            }

            wp_send_json_success( array(
                'message'  => $lote_id ? 'Pedido creado y lote provisional enviado a bodega.' : 'Pedido creado correctamente.',
                'pedido_id' => $pedido_id,
                'lote_id'  => $lote_id,
                'redirect' => home_url( '/?mm_logistica_app=pedidos' ),
                'bodega_url' => $lote_id ? home_url( '/?mm_logistica_app=bodega&lote_id=' . intval( $lote_id ) ) : '',
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error creando pedido: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => current_user_can( 'manage_options' ) ? 'Error creando pedido: ' . $e->getMessage() : 'No se pudo crear el pedido.',
            ), 500 );
        }
    }

    private function crear_lote_interno_desde_pedido( $pedido_id ) {
        $pedido_id = intval( $pedido_id );
        $existing = intval( get_post_meta( $pedido_id, '_mm_pedido_lote_id', true ) );
        if ( $existing > 0 ) {
            return $existing;
        }

        $proveedor = get_post_meta( $pedido_id, '_mm_pedido_proveedor', true );
        $factura_numero = get_post_meta( $pedido_id, '_mm_pedido_factura_numero', true );
        $factura_total = get_post_meta( $pedido_id, '_mm_pedido_factura_total', true );
        $observaciones = get_post_meta( $pedido_id, '_mm_pedido_observaciones', true );
        $soporte_url = get_post_meta( $pedido_id, '_mm_pedido_soporte_url', true );
        $soporte_id = get_post_meta( $pedido_id, '_mm_pedido_soporte_id', true );
        $productos_internos = get_post_meta( $pedido_id, '_mm_pedido_productos_internos', true );
        $productos_internos = is_array( $productos_internos ) ? $productos_internos : array();
        $productos_bodega = $this->build_bodega_safe_expected_products( $productos_internos );

        $lote_id = wp_insert_post( array(
            'post_type'   => 'lotes_ingreso',
            'post_title'  => 'Lote desde pedido #' . $pedido_id . ' - ' . $proveedor,
            'post_status' => 'draft',
            'post_author' => get_current_user_id(),
        ) );

        if ( is_wp_error( $lote_id ) || ! $lote_id ) {
            return 0;
        }

        update_post_meta( $lote_id, '_mm_pedido_origen_id', $pedido_id );
        update_post_meta( $lote_id, '_mm_pedido_origen_proveedor', $proveedor );
        update_post_meta( $lote_id, '_mm_factura_origen_numero', $factura_numero );
        update_post_meta( $lote_id, '_mm_factura_origen_total', $factura_total );
        update_post_meta( $lote_id, '_mm_factura_origen_observaciones', $observaciones );
        update_post_meta( $lote_id, '_mm_factura_origen_soporte_url', $soporte_url );
        update_post_meta( $lote_id, '_mm_factura_origen_soporte_id', $soporte_id );
        update_post_meta( $lote_id, '_mm_tipo_movimiento', 'sumar' );
        update_post_meta( $lote_id, '_mm_pedido_productos_internos_lote', $productos_internos );
        if ( ! empty( $productos_bodega ) ) {
            update_post_meta( $lote_id, '_mm_factura_productos_esperados', $productos_bodega );
            update_post_meta( $lote_id, '_mm_factura_productos_esperados_count', count( $productos_bodega ) );
        }

        update_post_meta( $pedido_id, '_mm_pedido_lote_id', $lote_id );
        update_post_meta( $pedido_id, '_mm_pedido_estado', 'lote_creado' );

        return intval( $lote_id );
    }

    public function ajax_crear_lote_desde_pedido() {
        try {
            if ( ! $this->can_access_pedidos_panel() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para crear lote desde pedido.' ), 403 );
            }

            $pedido_id = isset( $_POST['pedido_id'] ) ? absint( $_POST['pedido_id'] ) : 0;
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

            if ( ! $pedido_id || ! wp_verify_nonce( $nonce, 'mm_pedido_accion_' . $pedido_id ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $lote_id = $this->crear_lote_interno_desde_pedido( $pedido_id );

            if ( ! $lote_id ) {
                wp_send_json_error( array( 'message' => 'No se pudo crear el lote provisional.' ), 500 );
            }

            wp_send_json_success( array(
                'message' => 'Lote provisional creado para bodega.',
                'lote_id' => $lote_id,
                'redirect' => home_url( '/?mm_logistica_app=bodega&lote_id=' . $lote_id ),
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error creando lote desde pedido: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => 'No se pudo crear el lote desde pedido.' ), 500 );
        }
    }

    public function ajax_actualizar_factura_pedido() {
        try {
            if ( ! $this->can_access_pedidos_panel() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para actualizar factura del pedido.' ), 403 );
            }

            $pedido_id = isset( $_POST['pedido_id'] ) ? absint( $_POST['pedido_id'] ) : 0;
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

            if ( ! $pedido_id || ! wp_verify_nonce( $nonce, 'mm_pedido_accion_' . $pedido_id ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $numero = isset( $_POST['factura_numero'] ) ? sanitize_text_field( wp_unslash( $_POST['factura_numero'] ) ) : '';
            $total = isset( $_POST['factura_total'] ) ? floatval( preg_replace( '/[^0-9\.]/', '', wp_unslash( $_POST['factura_total'] ) ) ) : 0;

            update_post_meta( $pedido_id, '_mm_pedido_factura_numero', $numero );
            update_post_meta( $pedido_id, '_mm_pedido_factura_total', $total );
            update_post_meta( $pedido_id, '_mm_pedido_estado_factura', 'recibida' );
            update_post_meta( $pedido_id, '_mm_pedido_estado', 'factura_cargada' );

            $lote_id = intval( get_post_meta( $pedido_id, '_mm_pedido_lote_id', true ) );
            if ( $lote_id ) {
                update_post_meta( $lote_id, '_mm_factura_origen_numero', $numero );
                update_post_meta( $lote_id, '_mm_factura_origen_total', $total );
            }

            wp_send_json_success( array( 'message' => 'Factura asociada al pedido correctamente.' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => 'No se pudo actualizar la factura del pedido.' ), 500 );
        }
    }

    public function ajax_cerrar_pedido_compra() {
        try {
            if ( ! $this->can_access_pedidos_panel() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para cerrar pedidos.' ), 403 );
            }

            $pedido_id = isset( $_POST['pedido_id'] ) ? absint( $_POST['pedido_id'] ) : 0;
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

            if ( ! $pedido_id || ! wp_verify_nonce( $nonce, 'mm_pedido_accion_' . $pedido_id ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            update_post_meta( $pedido_id, '_mm_pedido_estado', 'cerrado' );
            wp_send_json_success( array( 'message' => 'Pedido cerrado correctamente.' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => 'No se pudo cerrar el pedido.' ), 500 );
        }
    }

    private function render_pedidos_dashboard() {
        if ( ! $this->can_access_pedidos_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para ver pedidos.' );
        }

        $pedidos = $this->get_pedidos_compra();

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'pedidos' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Control interno</span>
                        <h1>Pedidos / Compras</h1>
                        <p>Registra compras antes de bodega, con o sin factura. Mekano solo se usa para exportar la plantilla de carga masiva.</p>
                    </div>
                </header>

                <section class="mm-platform-section mm-pedidos-form-panel">
                    <div class="mm-section-head">
                        <h2>Crear pedido interno</h2>
                        <span>Usa esto cuando se hace una compra, aunque la factura llegue después.</span>
                    </div>

                    <form class="mm-pedido-compra-form mm-pedido-form" method="post" enctype="multipart/form-data" action="javascript:void(0);">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_guardar_pedido_compra' ) ); ?>">
                        <div class="mm-factura-form-grid">
                            <label>Proveedor
                                <input class="mm-input" type="text" name="proveedor" placeholder="Nombre del proveedor" required>
                            </label>
                            <label>Fecha del pedido
                                <input class="mm-input" type="date" name="fecha_pedido" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
                            </label>
                            <label>Valor estimado
                                <input class="mm-input" type="number" name="valor_estimado" min="0" step="1" placeholder="0">
                            </label>
                            <label>Estado factura
                                <select class="mm-input" name="estado_factura">
                                    <option value="pendiente">Factura pendiente</option>
                                    <option value="recibida">Factura recibida</option>
                                </select>
                            </label>
                            <label>Número factura
                                <input class="mm-input" type="text" name="factura_numero" placeholder="Opcional">
                            </label>
                            <label>Total factura
                                <input class="mm-input" type="number" name="factura_total" min="0" step="1" placeholder="0">
                            </label>
                        </div>

                        <label>Soporte
                            <input class="mm-input" type="file" name="soporte_pedido" accept="application/pdf,image/jpeg,image/png,image/webp">
                        </label>

                        <label>Observaciones
                            <textarea class="mm-input mm-textarea" name="observaciones" rows="3" placeholder="Ejemplo: proveedor entrega factura cuando llegue mercancía."></textarea>
                        </label>
                                                <section class="mm-pedido-ia-panel">
                            <div class="mm-section-head mm-section-head-compact">
                                <h3>🤖 Analizar soporte con IA</h3>
                                <span>Opcional: sube factura, remisión, cotización o captura. Se analizará directamente con OpenAI, sin n8n.</span>
                            </div>

                            <div class="mm-pedido-ia-grid">
                                <label>Archivo para IA
                                    <input class="mm-input" type="file" name="pedido_ia_archivo" accept="application/pdf,image/jpeg,image/png,image/webp" capture="environment">
                                </label>
                                <label>Texto manual para IA
                                    <textarea class="mm-input mm-textarea" name="pedido_ia_texto" rows="4" placeholder="Pega aquí texto de WhatsApp, cotización o lista de productos."></textarea>
                                </label>
                            </div>

                            <button type="button" class="mm-mini-secondary mm-pedido-ia-analizar" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mm_pedido_ia' ) ); ?>">Analizar y llenar productos</button>
                            <div class="mm-pedido-ia-msg" hidden></div>

                            <div class="mm-safe-note mm-pedido-ia-note">
                                <strong>No afecta Bodega:</strong>
                                <p>La IA solo ayuda a llenar el pedido. Para capturas/fotos usa OpenAI directo. Al crear el lote, Bodega seguirá viendo únicamente producto, código, cantidad y observación. Costos y precios quedan privados.</p>
                            </div>
                        </section>

<div class="mm-pedido-productos-panel">
                            <div class="mm-section-head mm-section-head-compact">
                                <h3>Detalle del pedido</h3>
                                <span>Agrega productos, cantidades y datos internos. Bodega no verá costos ni precios.</span>
                            </div>

                            <div class="mm-pedido-productos-table-wrap">
                                <table class="mm-pedido-productos-table">
                                    <thead>
                                        <tr>
                                            <th>Código / SKU</th>
                                            <th>Producto</th>
                                            <th>Cantidad</th>
                                            <th>Costo interno</th>
                                            <th>Precio interno</th>
                                            <th>Observación</th>
                                            <th>Imagen</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody class="mm-pedido-productos-rows">
                                        <tr>
                                            <td><input class="mm-input" type="text" name="pedido_producto_codigo[]" placeholder="770..."></td>
                                            <td><input class="mm-input" type="text" name="pedido_producto_nombre[]" placeholder="Nombre del producto"></td>
                                            <td><input class="mm-input" type="number" name="pedido_producto_cantidad[]" min="0" step="1" placeholder="0"></td>
                                            <td><input class="mm-input" type="number" name="pedido_producto_costo[]" min="0" step="1" placeholder="Privado"></td>
                                            <td><input class="mm-input" type="number" name="pedido_producto_precio[]" min="0" step="1" placeholder="Privado"></td>
                                            <td><input class="mm-input" type="text" name="pedido_producto_observacion[]" placeholder="Color, referencia, nota"></td>
                                            <td class="mm-pedido-image-cell"><input class="mm-input mm-pedido-product-image-input" type="file" name="pedido_producto_imagen[]" accept="image/jpeg,image/png,image/webp" capture="environment"><div class="mm-pedido-image-preview">Sin imagen</div></td>
                                            <td><button type="button" class="mm-mini-secondary mm-remove-pedido-product-row">Eliminar</button></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <button type="button" class="mm-mini-secondary mm-add-pedido-product-row">+ Agregar producto</button>

                            <input type="hidden" name="productos_pedido" value="">
                            <div class="mm-safe-note mm-pedido-private-price-note">
                                <strong>Privado para administración:</strong>
                                <p>Los campos de costo y precio quedan guardados para control interno, pero no se muestran en Bodega. La imagen es opcional; si un producto queda sin foto aparecerá como pendiente de imagen.</p>
                            </div>
                        </div>

                        <label class="mm-check-row">
                            <input type="checkbox" name="crear_lote" value="1">
                            <span>Crear lote provisional para bodega de una vez</span>
                        </label>

                        <button type="submit" class="mm-mini-primary">Guardar pedido</button>
                        <div class="mm-pedido-form-msg" hidden></div>
                    </form>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Pedidos registrados</h2>
                        <span>Seguimiento interno antes y después de bodega.</span>
                    </div>

                    <div class="mm-pedidos-grid">
                        <?php if ( empty( $pedidos ) ) : ?>
                            <div class="mm-empty-state">Todavía no hay pedidos registrados.</div>
                        <?php endif; ?>

                        <?php foreach ( $pedidos as $pedido ) :
                            $pedido_id = intval( $pedido->ID );
                            $proveedor = get_post_meta( $pedido_id, '_mm_pedido_proveedor', true );
                            $fecha = get_post_meta( $pedido_id, '_mm_pedido_fecha', true );
                            $valor = floatval( get_post_meta( $pedido_id, '_mm_pedido_valor_estimado', true ) );
                            $estado = get_post_meta( $pedido_id, '_mm_pedido_estado', true ) ?: 'pedido_creado';
                            $factura_estado = get_post_meta( $pedido_id, '_mm_pedido_estado_factura', true ) ?: 'pendiente';
                            $factura_numero = get_post_meta( $pedido_id, '_mm_pedido_factura_numero', true );
                            $lote_id = intval( get_post_meta( $pedido_id, '_mm_pedido_lote_id', true ) );
                            $nonce = wp_create_nonce( 'mm_pedido_accion_' . $pedido_id );
                        ?>
                            <article class="mm-pedido-card">
                                <div class="mm-pedido-card-head">
                                    <div>
                                        <small>Pedido #<?php echo esc_html( $pedido_id ); ?></small>
                                        <h3><?php echo esc_html( $proveedor ?: get_the_title( $pedido_id ) ); ?></h3>
                                    </div>
                                    <span class="mm-expected-status <?php echo 'cerrado' === $estado ? 'is-ok' : ( 'pendiente' === $factura_estado ? 'is-warning' : 'is-extra' ); ?>">
                                        <?php echo esc_html( $this->pedido_status_label( $estado ) ); ?>
                                    </span>
                                </div>

                                <div class="mm-pedido-meta">
                                    <span><strong><?php echo esc_html( $fecha ); ?></strong><small>Fecha</small></span>
                                    <span><strong>$<?php echo esc_html( number_format( $valor, 0, ',', '.' ) ); ?></strong><small>Valor estimado</small></span>
                                    <span><strong><?php echo esc_html( $factura_numero ?: 'Pendiente' ); ?></strong><small>Factura</small></span>
                                    <span><strong><?php echo $lote_id ? '#' . esc_html( $lote_id ) : 'Sin lote'; ?></strong><small>Lote</small></span>
                                </div>

                                <div class="mm-pedido-actions">
                                    <?php if ( $lote_id ) : ?>
                                        <a class="mm-mini-primary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=bodega&lote_id=' . $lote_id ) ); ?>">Ir a bodega</a>
                                        <a class="mm-mini-secondary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=facturas&lote_id=' . $lote_id ) ); ?>">Ver facturas</a>
                                    <?php else : ?>
                                        <button type="button" class="mm-mini-primary mm-pedido-create-lote" data-pedido="<?php echo esc_attr( $pedido_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Crear lote</button>
                                    <?php endif; ?>
                                    <button type="button" class="mm-mini-secondary mm-pedido-close" data-pedido="<?php echo esc_attr( $pedido_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Cerrar</button>
                                </div>

                                <form class="mm-pedido-factura-mini">
                                    <input type="hidden" name="pedido_id" value="<?php echo esc_attr( $pedido_id ); ?>">
                                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                                    <input class="mm-input" type="text" name="factura_numero" placeholder="Número factura">
                                    <input class="mm-input" type="number" name="factura_total" placeholder="Total factura">
                                    <button type="submit" class="mm-mini-secondary">Asociar factura</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }



    private function render_bodega_pedido_context_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( ! $lote_id ) {
            return '';
        }

        $pedido_id = intval( get_post_meta( $lote_id, '_mm_pedido_origen_id', true ) );
        $proveedor = get_post_meta( $lote_id, '_mm_pedido_origen_proveedor', true );
        if ( ! $proveedor ) {
            $proveedor = get_post_meta( $lote_id, '_mm_factura_origen_proveedor', true );
        }

        $factura = get_post_meta( $lote_id, '_mm_factura_origen_numero', true );
        $soporte_url = get_post_meta( $lote_id, '_mm_factura_origen_soporte_url', true );

        if ( ! $pedido_id && ! $proveedor && ! $factura && ! $soporte_url ) {
            return '';
        }

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-pedido-context">
            <div class="mm-section-head">
                <h2>Pedido interno asociado</h2>
                <span>Bodega registra productos y cantidades. Los costos y precios se completan después en Precios.</span>
            </div>

            <div class="mm-bodega-pedido-grid">
                <div>
                    <small>Pedido</small>
                    <strong><?php echo $pedido_id ? '#' . esc_html( $pedido_id ) : 'Sin pedido'; ?></strong>
                </div>
                <div>
                    <small>Proveedor</small>
                    <strong><?php echo esc_html( $proveedor ?: 'No registrado' ); ?></strong>
                </div>
                <div>
                    <small>Factura</small>
                    <strong><?php echo esc_html( $factura ?: 'Pendiente' ); ?></strong>
                </div>
                <div>
                    <small>Acción de bodega</small>
                    <strong>Escanear / crear productos</strong>
                </div>
            </div>

            <div class="mm-safe-note mm-bodega-no-cost-note">
                <strong>Información protegida:</strong>
                <p>Bodega no ve costo, precio de venta, precio mayorista ni margen. Solo debe confirmar productos físicos, fotos y cantidades recibidas.</p>
            </div>

            <?php if ( $soporte_url ) : ?>
                <a class="mm-mini-secondary" href="<?php echo esc_url( $soporte_url ); ?>" target="_blank" rel="noopener">Ver soporte</a>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }




    private function mm_handle_pedido_producto_imagenes() {
        $result = array();

        if ( empty( $_FILES['pedido_producto_imagen'] ) || empty( $_FILES['pedido_producto_imagen']['name'] ) || ! is_array( $_FILES['pedido_producto_imagen']['name'] ) ) {
            return $result;
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $files = $_FILES['pedido_producto_imagen'];

        foreach ( $files['name'] as $index => $name ) {
            if ( empty( $name ) || empty( $files['tmp_name'][ $index ] ) ) {
                continue;
            }

            if ( ! empty( $files['error'][ $index ] ) ) {
                continue;
            }

            $_FILES['mm_pedido_producto_imagen_tmp'] = array(
                'name'     => sanitize_file_name( $files['name'][ $index ] ),
                'type'     => sanitize_text_field( $files['type'][ $index ] ?? '' ),
                'tmp_name' => $files['tmp_name'][ $index ],
                'error'    => intval( $files['error'][ $index ] ?? 0 ),
                'size'     => intval( $files['size'][ $index ] ?? 0 ),
            );

            $attachment_id = media_handle_upload( 'mm_pedido_producto_imagen_tmp', 0 );

            unset( $_FILES['mm_pedido_producto_imagen_tmp'] );

            if ( is_wp_error( $attachment_id ) ) {
                continue;
            }

            $result[ intval( $index ) ] = array(
                'attachment_id' => intval( $attachment_id ),
                'url'           => wp_get_attachment_url( $attachment_id ),
            );
        }

        return $result;
    }


    private function parse_pedido_productos_internos( $raw ) {
        $products = array();

        $codigos = isset( $_POST['pedido_producto_codigo'] ) && is_array( $_POST['pedido_producto_codigo'] ) ? wp_unslash( $_POST['pedido_producto_codigo'] ) : array();
        $nombres = isset( $_POST['pedido_producto_nombre'] ) && is_array( $_POST['pedido_producto_nombre'] ) ? wp_unslash( $_POST['pedido_producto_nombre'] ) : array();
        $cantidades = isset( $_POST['pedido_producto_cantidad'] ) && is_array( $_POST['pedido_producto_cantidad'] ) ? wp_unslash( $_POST['pedido_producto_cantidad'] ) : array();
        $costos = isset( $_POST['pedido_producto_costo'] ) && is_array( $_POST['pedido_producto_costo'] ) ? wp_unslash( $_POST['pedido_producto_costo'] ) : array();
        $precios = isset( $_POST['pedido_producto_precio'] ) && is_array( $_POST['pedido_producto_precio'] ) ? wp_unslash( $_POST['pedido_producto_precio'] ) : array();
        $observaciones = isset( $_POST['pedido_producto_observacion'] ) && is_array( $_POST['pedido_producto_observacion'] ) ? wp_unslash( $_POST['pedido_producto_observacion'] ) : array();
        $imagenes_productos = $this->mm_handle_pedido_producto_imagenes();

        $max = max( count( $codigos ), count( $nombres ), count( $cantidades ) );

        for ( $i = 0; $i < $max; $i++ ) {
            $codigo = sanitize_text_field( $codigos[ $i ] ?? '' );
            $nombre = sanitize_text_field( $nombres[ $i ] ?? '' );
            $cantidad = isset( $cantidades[ $i ] ) ? intval( preg_replace( '/[^0-9]/', '', (string) $cantidades[ $i ] ) ) : 0;
            $costo = isset( $costos[ $i ] ) ? floatval( preg_replace( '/[^0-9\.]/', '', (string) $costos[ $i ] ) ) : 0;
            $precio = isset( $precios[ $i ] ) ? floatval( preg_replace( '/[^0-9\.]/', '', (string) $precios[ $i ] ) ) : 0;
            $observacion = sanitize_text_field( $observaciones[ $i ] ?? '' );
            $imagen_data = $imagenes_productos[ $i ] ?? array();
            $imagen_url = ! empty( $imagen_data['url'] ) ? esc_url_raw( $imagen_data['url'] ) : '';
            $imagen_attachment_id = ! empty( $imagen_data['attachment_id'] ) ? intval( $imagen_data['attachment_id'] ) : 0;

            if ( '' === $codigo && '' === $nombre ) {
                continue;
            }

            $products[] = array(
                'item'        => count( $products ) + 1,
                'codigo'      => $codigo,
                'nombre'      => $nombre,
                'cantidad'    => max( 0, $cantidad ),
                'costo'       => max( 0, $costo ),
                'precio'      => max( 0, $precio ),
                'observacion' => $observacion,
                'imagen_url'   => $imagen_url,
                'imagen_id'    => $imagen_attachment_id,
                'sin_imagen'   => empty( $imagen_url ),
            );

            if ( empty( $imagen_url ) && method_exists( $this, 'mm_registrar_producto_sin_imagen' ) ) {
                $this->mm_registrar_producto_sin_imagen( $codigo, $nombre, 'pedido' );
            }
        }

        if ( ! empty( $products ) ) {
            return $products;
        }

        $raw = (string) $raw;
        $lines = preg_split( '/\r\n|\r|\n/', $raw );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) {
                continue;
            }

            $parts = array_map( 'trim', explode( '|', $line ) );

            $codigo = sanitize_text_field( $parts[0] ?? '' );
            $nombre = sanitize_text_field( $parts[1] ?? '' );
            $cantidad = isset( $parts[2] ) ? intval( preg_replace( '/[^0-9]/', '', $parts[2] ) ) : 0;
            $costo = isset( $parts[3] ) ? floatval( preg_replace( '/[^0-9\.]/', '', $parts[3] ) ) : 0;
            $precio = isset( $parts[4] ) ? floatval( preg_replace( '/[^0-9\.]/', '', $parts[4] ) ) : 0;
            $observacion = sanitize_text_field( $parts[5] ?? '' );

            if ( '' === $codigo && '' === $nombre ) {
                continue;
            }

            $products[] = array(
                'item'        => count( $products ) + 1,
                'codigo'      => $codigo,
                'nombre'      => $nombre,
                'cantidad'    => max( 0, $cantidad ),
                'costo'       => max( 0, $costo ),
                'precio'      => max( 0, $precio ),
                'observacion' => $observacion,
                'imagen_url'   => '',
                'imagen_id'    => 0,
                'sin_imagen'   => true,
            );
        }

        return $products;
    }


    private function build_bodega_safe_expected_products( $products ) {
        $safe = array();

        foreach ( (array) $products as $product ) {
            $safe[] = array(
                'item'        => intval( $product['item'] ?? count( $safe ) + 1 ),
                'codigo'      => sanitize_text_field( $product['codigo'] ?? '' ),
                'nombre'      => sanitize_text_field( $product['nombre'] ?? '' ),
                'cantidad'    => intval( $product['cantidad'] ?? 0 ),
                'observacion' => sanitize_text_field( $product['observacion'] ?? '' ),
                'imagen_url'   => esc_url_raw( $product['imagen_url'] ?? '' ),
                'imagen_id'    => intval( $product['imagen_id'] ?? 0 ),
                // No enviar costo/precio a la vista de bodega.
                'costo'       => 0,
                'precio'      => 0,
            );
        }

        return $safe;
    }





    private function analizar_pedido_con_webhook_ia( $texto_manual = '', $file_payload = null ) {
        return $this->openai_service->analizar_pedido_con_webhook_ia( $texto_manual, $file_payload );
    }
}
