<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait ConfigIaTrait {
    private function can_manage_ia_config() {
        return is_user_logged_in() && current_user_can( 'manage_options' );
    }

    private function get_ia_webhook_config() {
        return array(
            'webhook_url' => esc_url_raw( get_option( 'mm_factura_ai_webhook_url', '' ) ),
            'token'       => get_option( 'mm_factura_ai_webhook_token', '' ),
        );
    }

    private function render_ia_config_panel() {
        if ( ! $this->can_manage_ia_config() ) {
            return '';
        }

        $config = $this->get_ia_webhook_config();
        $has_url = ! empty( $config['webhook_url'] );

        ob_start(); ?>
        <section class="mm-platform-section mm-ia-config-panel">
            <div class="mm-section-head">
                <h2>🤖 Configuración IA OpenAI</h2>
                <span>OpenAI directo para leer facturas, fotos, capturas y PDF escaneados.</span>
            </div>

            <form class="mm-ia-config-form">
                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_guardar_config_ia' ) ); ?>">

                <label>API Key de OpenAI
                    <input class="mm-input" type="password" name="openai_api_key" placeholder="sk-..." value="<?php echo esc_attr( get_option( 'mm_openai_api_key', '' ) ); ?>">
                </label>

                <label>URL Webhook n8n (desactivado)
                    <input class="mm-input" type="url" name="webhook_url" placeholder="https://megamundo.app.n8n.cloud/webhook/megamundo-pedidos" value="<?php echo esc_attr( $config['webhook_url'] ); ?>">
                </label>

                <label>Token opcional
                    <input class="mm-input" type="text" name="webhook_token" placeholder="MGM_PEDIDOS_IA_2026_x7Kp92Lm" value="<?php echo esc_attr( $config['token'] ); ?>">
                </label>

                <div class="mm-ai-model-grid">
                    <label>Modelo OpenAI para facturas
                        <select class="mm-input" name="openai_invoice_model">
                            <?php $invoice_model = method_exists( $this, 'mm_get_ai_model_for_invoices_direct' ) ? $this->mm_get_ai_model_for_invoices_direct() : ( method_exists( $this, 'mm_get_ai_model_for_invoices' ) ? $this->mm_get_ai_model_for_invoices() : 'gpt-4o-mini' ); ?>
                            <option value="gpt-4o-mini" <?php selected( $invoice_model, 'gpt-4o-mini' ); ?>>gpt-4o-mini — Recomendado / económico</option>
                            <option value="gpt-4o" <?php selected( $invoice_model, 'gpt-4o' ); ?>>gpt-4o — Mejor visión</option>
                            <option value="gpt-5.4-mini" <?php selected( $invoice_model, 'gpt-5.4-mini' ); ?>>gpt-5.4-mini — Más precisión</option>
                            <option value="gpt-5.4" <?php selected( $invoice_model, 'gpt-5.4' ); ?>>gpt-5.4 — Avanzado</option>
                            <option value="gpt-5.5" <?php selected( $invoice_model, 'gpt-5.5' ); ?>>gpt-5.5 — Máxima precisión</option>
                        </select>
                    </label>
                    <label>Razonamiento
                        <select class="mm-input" name="openai_reasoning_effort">
                            <?php $reasoning_effort = $this->mm_get_ai_reasoning_effort(); ?>
                            <option value="low" <?php selected( $reasoning_effort, 'low' ); ?>>low — rápido</option>
                            <option value="medium" <?php selected( $reasoning_effort, 'medium' ); ?>>medium — recomendado</option>
                            <option value="high" <?php selected( $reasoning_effort, 'high' ); ?>>high — más profundo</option>
                        </select>
                    </label>
                    <label>Temperatura
                        <input class="mm-input" type="number" name="openai_temperature" min="0" max="1" step="0.1" value="<?php echo esc_attr( $this->mm_get_ai_temperature() ); ?>">
                    </label>
                </div>


                <div class="mm-ia-config-actions">
                    <button type="submit" class="mm-mini-primary">Guardar configuración</button>
                    <button type="button" class="mm-mini-secondary mm-ia-test-btn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mm_probar_config_ia' ) ); ?>">Probar conexión</button>
                    <button type="button" class="mm-mini-secondary mm-ia-product-test-btn" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mm_probar_ia_producto' ) ); ?>">Probar análisis con producto</button>
                    <span class="mm-ia-status <?php echo $has_url ? 'is-ok' : 'is-warning'; ?>">
                        <?php echo $has_url ? 'OpenAI listo' : 'API Key pendiente'; ?>
                    </span>
                </div>

                <div class="mm-ia-config-msg" hidden></div>
            </form>

            <div class="mm-safe-note">
                <strong>Uso:</strong>
                <p>Esta URL será usada por Pedidos, Facturas y Bodega cuando se necesite leer una imagen, captura o PDF escaneado. Si no configuras webhook, el sistema solo podrá trabajar bien con texto pegado manualmente o PDF con texto seleccionable.</p>
            </div>

            <?php echo $this->mm_render_ai_usage_panel(); ?>

            <details class="mm-ia-help">
                <summary>Ver estructura que recibe n8n</summary>
                <pre>{
  "origen": "pedidos",
  "tipo": "pedido_compra",
  "texto_manual": "...",
  "token": "MGM_PEDIDOS_IA_2026_x7Kp92Lm",
  "token_ia": "MGM_PEDIDOS_IA_2026_x7Kp92Lm",
  "archivo": {
    "filename": "factura.jpg",
    "mime": "image/jpeg",
    "base64": "..."
  },
  "instruccion": "Analiza este soporte..."
}</pre>
            </details>
        </section>
        <?php return ob_get_clean();
    }

    public function ajax_guardar_config_ia() {
        try {
            if ( ! $this->can_manage_ia_config() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para configurar IA.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_guardar_config_ia' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $openai_api_key = isset( $_POST['openai_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ) ) : '';
            $webhook_url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
            $webhook_token = isset( $_POST['webhook_token'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_token'] ) ) : '';

            $invoice_model = isset( $_POST['openai_invoice_model'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_invoice_model'] ) ) : 'gpt-4o-mini';
            $allowed_models = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-5.4-mini', 'gpt-5.4', 'gpt-5.5' );
            if ( ! in_array( $invoice_model, $allowed_models, true ) ) {
                $invoice_model = 'gpt-4o-mini';
            }

            $reasoning_effort = isset( $_POST['openai_reasoning_effort'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_reasoning_effort'] ) ) : 'medium';
            if ( ! in_array( $reasoning_effort, array( 'low', 'medium', 'high' ), true ) ) {
                $reasoning_effort = 'medium';
            }

            $temperature = isset( $_POST['openai_temperature'] ) ? floatval( wp_unslash( $_POST['openai_temperature'] ) ) : 0;
            $temperature = max( 0, min( 1, $temperature ) );

            if ( ! empty( $openai_api_key ) ) {
                update_option( 'mm_openai_api_key', $openai_api_key );
            }

            update_option( 'mm_openai_invoice_model', $invoice_model );
            update_option( 'mm_openai_reasoning_effort', $reasoning_effort );
            update_option( 'mm_openai_temperature', (string) $temperature );

            if ( ! empty( $webhook_url ) ) { update_option( 'mm_factura_ai_webhook_url', $webhook_url ); }
            if ( ! empty( $webhook_token ) ) { update_option( 'mm_factura_ai_webhook_token', $webhook_token ); }

            wp_send_json_success( array(
                'message' => 'Configuración OpenAI guardada correctamente.',
                'openai_configured' => ! empty( $this->openai_service->mm_get_openai_api_key_direct() ) || ! empty( $openai_api_key ),
                'model' => $invoice_model,
                'reasoning' => $reasoning_effort,
                'temperature' => $temperature,
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error guardando config OpenAI: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => current_user_can( 'manage_options' ) ? 'No se pudo guardar OpenAI: ' . $e->getMessage() : 'No se pudo guardar la configuración OpenAI.',
            ), 500 );
        }
    }


    public function ajax_probar_config_ia() {
        try {
            if ( ! $this->can_manage_ia_config() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para probar IA/OCR.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_probar_config_ia' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : $this->openai_service->get_factura_ai_webhook_url();
            $token = isset( $_POST['webhook_token'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_token'] ) ) : get_option( 'mm_factura_ai_webhook_token', '' );

            if ( empty( $url ) ) {
                wp_send_json_error( array( 'message' => 'Primero pega la URL del webhook de n8n.' ), 400 );
            }

            $headers = array( 'Content-Type' => 'application/json' );
            if ( ! empty( $token ) ) {
                $headers['x-mm-token'] = $token;
                $headers['x-megamundo-token'] = $token;
                $headers['X-Megamundo-Token'] = $token;
                $headers['X-MM-Token'] = $token;
            }

            $payload = array(
                'origen' => 'sistema',
                'tipo' => 'test_conexion',
                'texto_manual' => 'Prueba de conexión desde MegaMundo Logística.',
                'archivo' => null,
                'file' => null,
                'image' => null,
                'documento' => null,
                'pdf' => null,
                'token' => $token,
                'token_ia' => $token,
                'instruccion' => 'Prueba de conexión. Responde JSON válido.',
            );

            $response = wp_remote_post( $url, array(
                'timeout' => 35,
                'headers' => $headers,
                'body' => wp_json_encode( $payload ),
            ) );

            if ( is_wp_error( $response ) ) {
                wp_send_json_error( array( 'message' => 'No se pudo conectar: ' . $response->get_error_message() ), 500 );
            }

            $code = intval( wp_remote_retrieve_response_code( $response ) );
            $body = trim( wp_remote_retrieve_body( $response ) );

            if ( 401 === $code ) {
                wp_send_json_error( array( 'message' => 'n8n respondió 401 Unauthorized. Revisa que el token sea correcto.' ), 401 );
            }

            if ( $code < 200 || $code >= 300 ) {
                wp_send_json_error( array(
                    'message' => 'El webhook respondió HTTP ' . $code . '. Revisa que el workflow esté activo.',
                    'body' => current_user_can( 'manage_options' ) ? wp_strip_all_tags( $body ) : '',
                ), 500 );
            }

            wp_send_json_success( array(
                'message' => 'Conexión exitosa con n8n.',
                'response_code' => $code,
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error probando config IA: ' . $e->getMessage() );
            wp_send_json_error( array( 'message' => 'No se pudo probar la conexión IA.' ), 500 );
        }
    }



    public function ajax_probar_ia_producto() {
        try {
            if ( ! $this->can_manage_ia_config() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para probar IA/OCR.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_probar_ia_producto' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
            $token = isset( $_POST['webhook_token'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_token'] ) ) : '';

            if ( ! empty( $url ) ) {
                update_option( 'mm_factura_ai_webhook_url', $url );
            }

            if ( ! empty( $token ) ) {
                update_option( 'mm_factura_ai_webhook_token', $token );
            }

            $texto_prueba = "Proveedor: Distribuidora Prueba\nFactura: FV-123\nFecha: 25/05/2026\nTotal: 100000\n\nProductos:\n7701234567890 Adaptador de viaje 2 en 1 cantidad 5 costo 8000 precio 20000\n7709876543210 Linterna LED cantidad 3 costo 12000 precio 25000";

            $result = $this->analizar_pedido_con_webhook_ia( $texto_prueba, null );

            if ( ! is_array( $result ) ) {
                wp_send_json_error( array( 'message' => 'n8n no devolvió una respuesta válida.' ), 500 );
            }

            if ( ! empty( $result['error'] ) ) {
                wp_send_json_error( array(
                    'message' => $result['error'],
                    'raw' => $result,
                ), 500 );
            }

            $productos = isset( $result['productos_detectados'] ) && is_array( $result['productos_detectados'] ) ? $result['productos_detectados'] : array();

            if ( empty( $productos ) ) {
                wp_send_json_error( array(
                    'message' => 'n8n respondió, pero no devolvió productos_detectados. Revisa el nodo final Respond to Webhook.',
                    'raw' => $result,
                ), 422 );
            }

            
            if ( ! $this->mm_producto_tiene_imagen_upload() ) {
                $mm_codigo_tmp = isset( $_POST['codigo'] ) ? sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) : ( isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '' );
                $mm_producto_tmp = isset( $_POST['producto'] ) ? sanitize_text_field( wp_unslash( $_POST['producto'] ) ) : ( isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '' );
                $this->mm_registrar_producto_sin_imagen( $mm_codigo_tmp, $mm_producto_tmp, 'bodega' );
            }

wp_send_json_success( array(
                'message' => 'Diagnóstico correcto: n8n devolvió ' . count( $productos ) . ' producto(s).',
                'productos' => count( $productos ),
                'raw' => $result,
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error diagnóstico IA producto: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => current_user_can( 'manage_options' ) ? 'Error diagnóstico IA: ' . $e->getMessage() : 'No se pudo probar la IA.',
            ), 500 );
        }
    }






    private function mm_get_ai_model_for_invoices() {
        $model = get_option( 'mm_openai_invoice_model', 'gpt-4o-mini' );
        $allowed = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-5.4-mini', 'gpt-5.4', 'gpt-5.5' );
        return in_array( $model, $allowed, true ) ? $model : 'gpt-4o-mini';
    }

    private function mm_get_ai_reasoning_effort() {
        $effort = get_option( 'mm_openai_reasoning_effort', 'medium' );
        $allowed = array( 'low', 'medium', 'high' );
        return in_array( $effort, $allowed, true ) ? $effort : 'medium';
    }

    private function mm_get_ai_temperature() {
        $temperature = get_option( 'mm_openai_temperature', '0' );
        return is_numeric( $temperature ) ? max( 0, min( 1, floatval( $temperature ) ) ) : 0;
    }

    private function mm_get_invoice_vision_prompt() {
        return 'Analiza este documento comercial de MegaMundo. Devuelve SOLO JSON válido, sin markdown ni explicación. Estructura exacta: {"proveedor":"","numero_factura":"","fecha_factura":"","total_factura":0,"moneda":"COP","productos_detectados":[{"codigo":"","nombre":"","cantidad":0,"costo":0,"precio":0,"observacion":""}],"observaciones_ia":""}. No inventes productos. Si no ves un dato, déjalo vacío o en 0. Une productos repetidos sumando cantidades.';
    }





    private function mm_render_ai_usage_panel() {
        if ( ! current_user_can( 'manage_options' ) ) { return ''; }
        $last = get_option( 'mm_ai_usage_last', array() );
        $month = get_option( 'mm_ai_usage_' . gmdate( 'Y_m' ), array() );
        ob_start(); ?>
        <section class="mm-platform-section mm-ai-usage-panel">
            <div class="mm-section-head"><h2>📊 Consumo IA</h2><span>Tokens y costos estimados por análisis con OpenAI.</span></div>
            <div class="mm-ai-usage-grid">
                <div><small>Último análisis</small><strong><?php echo esc_html( $last['total_tokens'] ?? 0 ); ?> tokens</strong><span><?php echo esc_html( $last['model'] ?? '—' ); ?></span></div>
                <div><small>Costo último</small><strong>$<?php echo esc_html( number_format( floatval( $last['cost_usd'] ?? 0 ), 6, '.', ',' ) ); ?> USD</strong><span><?php echo esc_html( $last['processed_at'] ?? 'Sin registros' ); ?></span></div>
                <div><small>Mes actual</small><strong><?php echo esc_html( $month['total_tokens'] ?? 0 ); ?> tokens</strong><span><?php echo esc_html( $month['requests'] ?? 0 ); ?> solicitudes</span></div>
                <div><small>Costo mes</small><strong>$<?php echo esc_html( number_format( floatval( $month['cost_usd'] ?? 0 ), 6, '.', ',' ) ); ?> USD</strong><span>Estimado</span></div>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    private function mm_build_openai_uploaded_file_payload( $field_name = 'archivo_ia' ) {
        if ( empty( $_FILES[ $field_name ]['name'] ) || empty( $_FILES[ $field_name ]['tmp_name'] ) ) {
            return array();
        }

        $tmp = $_FILES[ $field_name ]['tmp_name'];
        if ( ! is_uploaded_file( $tmp ) && ! file_exists( $tmp ) ) {
            return array();
        }

        $raw = file_get_contents( $tmp );
        if ( false === $raw || '' === $raw ) {
            return array();
        }

        $mime = ! empty( $_FILES[ $field_name ]['type'] ) ? sanitize_text_field( $_FILES[ $field_name ]['type'] ) : '';
        if ( empty( $mime ) && function_exists( 'mime_content_type' ) ) {
            $mime = mime_content_type( $tmp );
        }
        if ( empty( $mime ) ) {
            $mime = 'application/octet-stream';
        }

        $base64 = base64_encode( $raw );

        return array(
            'filename' => sanitize_file_name( $_FILES[ $field_name ]['name'] ),
            'mime'     => $mime,
            'data_url' => 'data:' . $mime . ';base64,' . $base64,
        );
    }
}
