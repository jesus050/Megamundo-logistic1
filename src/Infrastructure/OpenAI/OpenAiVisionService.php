<?php
namespace MegaMundo\Logistica\Infrastructure\OpenAI;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class OpenAiVisionService {

    public function mm_get_openai_api_key_direct() {
        $key = trim( (string) get_option( 'mm_openai_api_key', '' ) );
        if ( '' !== $key ) { return $key; }
        if ( defined( 'MM_OPENAI_API_KEY' ) ) { return trim( (string) MM_OPENAI_API_KEY ); }
        if ( defined( 'OPENAI_API_KEY' ) ) { return trim( (string) OPENAI_API_KEY ); }
        return '';
    }

    public function mm_get_ai_model_for_invoices_direct() {
        $model = get_option( 'mm_openai_invoice_model', 'gpt-4o-mini' );
        $allowed = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-5.4-mini', 'gpt-5.4', 'gpt-5.5' );
        return in_array( $model, $allowed, true ) ? $model : 'gpt-4o-mini';
    }

    public function mm_build_openai_file_data_url( $field_name = 'archivo_ia' ) {
        if ( empty( $_FILES[ $field_name ]['name'] ) || empty( $_FILES[ $field_name ]['tmp_name'] ) ) { return ''; }
        $tmp = $_FILES[ $field_name ]['tmp_name'];
        if ( ! is_uploaded_file( $tmp ) && ! file_exists( $tmp ) ) { return ''; }
        $raw = file_get_contents( $tmp );
        if ( false === $raw || '' === $raw ) { return ''; }
        $mime = ! empty( $_FILES[ $field_name ]['type'] ) ? sanitize_text_field( $_FILES[ $field_name ]['type'] ) : '';
        if ( empty( $mime ) && function_exists( 'mime_content_type' ) ) { $mime = mime_content_type( $tmp ); }
        if ( empty( $mime ) ) { $mime = 'image/jpeg'; }
        return 'data:' . $mime . ';base64,' . base64_encode( $raw );
    }

    public function mm_extract_json_from_openai_direct( $value ) {
        if ( is_array( $value ) ) {
            return $value;
        }

        $value = trim( (string) $value );

        if ( '' === $value ) {
            return null;
        }

        $value = preg_replace( '/^```(?:json)?\s*/i', '', $value );
        $value = preg_replace( '/\s*```$/', '', $value );
        $value = trim( $value );

        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) {
            return $decoded;
        }

        $first = strpos( $value, '{' );
        $last = strrpos( $value, '}' );
        if ( false !== $first && false !== $last && $last > $first ) {
            $json = substr( $value, $first, $last - $first + 1 );
            $decoded = json_decode( $json, true );
            if ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) {
                return $decoded;
            }
        }

        return null;
    }

    public function mm_normalize_openai_invoice_json( $decoded ) {
        foreach ( array( 'productos', 'items', 'products', 'line_items' ) as $alt_key ) {
            if ( empty( $decoded['productos_detectados'] ) && ! empty( $decoded[ $alt_key ] ) ) { $decoded['productos_detectados'] = $decoded[ $alt_key ]; }
        }
        $products = array();
        $raw_products = isset( $decoded['productos_detectados'] ) && is_array( $decoded['productos_detectados'] ) ? $decoded['productos_detectados'] : array();
        foreach ( $raw_products as $product ) {
            if ( ! is_array( $product ) ) { continue; }
            $codigo = sanitize_text_field( $product['codigo'] ?? $product['sku'] ?? $product['referencia'] ?? '' );
            $nombre = sanitize_text_field( $product['nombre'] ?? $product['descripcion'] ?? $product['producto'] ?? '' );
            if ( '' === $codigo && '' === $nombre ) { continue; }
            $cantidad = isset( $product['cantidad'] ) ? intval( $product['cantidad'] ) : 0;
            $costo = isset( $product['costo'] ) ? floatval( $product['costo'] ) : ( isset( $product['costo_unitario'] ) ? floatval( $product['costo_unitario'] ) : 0 );
            $precio = isset( $product['precio'] ) ? floatval( $product['precio'] ) : 0;
            $observacion = sanitize_text_field( $product['observacion'] ?? $product['nota'] ?? '' );
            $key = $codigo ? 'codigo:' . $codigo : 'nombre:' . strtolower( $nombre );
            if ( ! isset( $products[ $key ] ) ) {
                $products[ $key ] = array( 'codigo'=>$codigo, 'nombre'=>$nombre, 'cantidad'=>max(0,$cantidad), 'costo'=>max(0,$costo), 'precio'=>max(0,$precio), 'observacion'=>$observacion );
            } else {
                $products[ $key ]['cantidad'] += max(0,$cantidad);
                if ( empty( $products[ $key ]['costo'] ) && $costo > 0 ) { $products[ $key ]['costo'] = $costo; }
                if ( empty( $products[ $key ]['precio'] ) && $precio > 0 ) { $products[ $key ]['precio'] = $precio; }
            }
        }
        return array(
            'proveedor'=>sanitize_text_field($decoded['proveedor']??''),
            'numero_factura'=>sanitize_text_field($decoded['numero_factura']??$decoded['factura']??''),
            'fecha_factura'=>sanitize_text_field($decoded['fecha_factura']??$decoded['fecha']??''),
            'total_factura'=>isset($decoded['total_factura'])?floatval($decoded['total_factura']):(isset($decoded['total'])?floatval($decoded['total']):0),
            'moneda'=>sanitize_text_field($decoded['moneda']??'COP'),
            'productos_detectados'=>array_values($products),
            'observaciones_ia'=>sanitize_textarea_field($decoded['observaciones_ia']??'Lectura realizada con OpenAI directo.'),
            'total_productos'=>count($products),'processed_at'=>current_time('mysql') );
    }

    public function analizar_pedido_con_openai_directo( $texto_manual = '' ) {
        $api_key = $this->mm_get_openai_api_key_direct();
        if ( empty( $api_key ) ) {
            return array( 'error' => 'No hay API Key de OpenAI configurada. Guarda la API Key en Sistema.' );
        }

        $uploaded_file_payload = $this->mm_build_openai_uploaded_file_payload( 'archivo_ia' );
        $data_url = ! empty( $uploaded_file_payload['data_url'] ) ? $uploaded_file_payload['data_url'] : '';
        $model = $this->mm_get_ai_model_for_invoices_direct();
        if ( ! empty( $_POST['usar_maxima_precision'] ) ) {
            $model = 'gpt-5.5';
        }

        if ( empty( $uploaded_file_payload ) && empty( $texto_manual ) ) {
            return array( 'error' => 'Sube una imagen/PDF o pega texto para analizar.' );
        }

        $system_prompt = 'Eres un analizador profesional de facturas, pedidos, remisiones, cotizaciones y capturas comerciales. Debes devolver UNICAMENTE JSON valido. No expliques nada. No uses markdown. No uses ```json. No agregues texto fuera del JSON. Si no encuentras un dato, usa string vacio o 0. No inventes productos, codigos, cantidades, costos ni precios.';

        $user_prompt = 'Analiza este documento comercial de MegaMundo. Extrae los datos visibles y devuelve exactamente esta estructura JSON: {"proveedor":"","numero_factura":"","fecha_factura":"","total_factura":0,"moneda":"COP","productos_detectados":[{"codigo":"","nombre":"","cantidad":0,"costo":0,"precio":0,"observacion":""}],"observaciones_ia":""}. Reglas: fecha_factura en formato YYYY-MM-DD si es posible; total_factura sin simbolos ni puntos; cantidad como numero; costo como valor unitario de compra si aparece; precio solo si aparece explicitamente; si un producto se repite, unirlo y sumar cantidades.';

        if ( ! empty( $texto_manual ) ) {
            $user_prompt .= "\n\nTexto manual:\n" . $texto_manual;
        }

        $content = array(
            array(
                'type' => 'input_text',
                'text' => $user_prompt,
            ),
        );

        if ( ! empty( $uploaded_file_payload ) ) {
            $mime = strtolower( $uploaded_file_payload['mime'] ?? '' );

            if ( false !== strpos( $mime, 'pdf' ) ) {
                $content[] = array(
                    'type' => 'input_file',
                    'filename' => ! empty( $uploaded_file_payload['filename'] ) ? $uploaded_file_payload['filename'] : 'documento.pdf',
                    'file_data' => $uploaded_file_payload['data_url'],
                );
            } elseif ( 0 === strpos( $mime, 'image/' ) ) {
                $content[] = array(
                    'type' => 'input_image',
                    'image_url' => $uploaded_file_payload['data_url'],
                );
            } else {
                return array( 'error' => 'Formato no soportado: ' . $mime . '. Usa PDF, JPG, PNG o WEBP.' );
            }
        }

        $payload = array(
            'model' => $model,
            'input' => array(
                array(
                    'role' => 'system',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => $system_prompt,
                        ),
                    ),
                ),
                array(
                    'role' => 'user',
                    'content' => $content,
                ),
            ),
            'text' => array(
                'format' => array(
                    'type' => 'json_object',
                ),
            ),
        );

        $response = wp_remote_post( 'https://api.openai.com/v1/responses', array(
            'timeout' => 120,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }

        $code = intval( wp_remote_retrieve_response_code( $response ) );
        $body = trim( wp_remote_retrieve_body( $response ) );

        if ( $code < 200 || $code >= 300 ) {
            $plain_error = strtolower( wp_strip_all_tags( $body ) );
            $should_retry = 'gpt-4o-mini' !== $model && (
                false !== strpos( $plain_error, 'high demand' ) ||
                false !== strpos( $plain_error, 'overloaded' ) ||
                false !== strpos( $plain_error, 'temporarily' ) ||
                false !== strpos( $plain_error, 'rate_limit' ) ||
                false !== strpos( $plain_error, 'model' )
            );

            if ( $should_retry ) {
                $payload['model'] = 'gpt-4o-mini';
                $model = 'gpt-4o-mini';

                $response = wp_remote_post( 'https://api.openai.com/v1/responses', array(
                    'timeout' => 120,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $api_key,
                    ),
                    'body' => wp_json_encode( $payload ),
                ) );

                if ( is_wp_error( $response ) ) {
                    return array( 'error' => $response->get_error_message() );
                }

                $code = intval( wp_remote_retrieve_response_code( $response ) );
                $body = trim( wp_remote_retrieve_body( $response ) );
            }

            if ( $code < 200 || $code >= 300 ) {
                return array(
                    'error' => ( 401 === $code ? 'API Key de OpenAI incorrecta. Revisa la clave guardada en Sistema.' : 'OpenAI respondió HTTP ' . $code . '. Revisa la configuración o intenta de nuevo.' ),
                );
            }
        }

        $decoded_response = json_decode( $body, true );
        if ( ! is_array( $decoded_response ) ) {
            return array(
                'error' => 'OpenAI no devolvió una respuesta JSON válida.',
            );
        }

        $output_text = '';

        if ( isset( $decoded_response['output_text'] ) && is_string( $decoded_response['output_text'] ) ) {
            $output_text = $decoded_response['output_text'];
        }

        if ( empty( $output_text ) && ! empty( $decoded_response['output'] ) && is_array( $decoded_response['output'] ) ) {
            foreach ( $decoded_response['output'] as $output_item ) {
                if ( empty( $output_item['content'] ) || ! is_array( $output_item['content'] ) ) {
                    continue;
                }

                foreach ( $output_item['content'] as $content_item ) {
                    if ( isset( $content_item['text'] ) && is_string( $content_item['text'] ) ) {
                        $output_text .= $content_item['text'] . "\n";
                    } elseif ( isset( $content_item['json'] ) && is_array( $content_item['json'] ) ) {
                        $normalized_json_response = $this->mm_normalize_openai_invoice_json( $content_item['json'] );
                        $normalized_json_response['modelo_usado'] = $model;
                        if ( isset( $decoded_response['usage'] ) ) {
                            $usage_summary = $this->mm_register_ai_usage( $model, $decoded_response['usage'] );
                            $normalized_json_response['_ai_usage'] = $usage_summary;
                        }
                        return $normalized_json_response;
                    }
                }
            }
        }

        $invoice_json = $this->mm_extract_json_from_openai_direct( $output_text );

        if ( ! is_array( $invoice_json ) ) {
            return array(
                'error' => 'OpenAI respondió, pero el plugin no pudo leer el JSON. Se activó modo debug.',
                '_debug_output_text' => substr( wp_strip_all_tags( $output_text ), 0, 1500 ),
            );
        }

        $normalized = $this->mm_normalize_openai_invoice_json( $invoice_json );
        $normalized['modelo_usado'] = $model;

        if ( ! isset( $normalized['productos_detectados'] ) || ! is_array( $normalized['productos_detectados'] ) ) {
            $normalized['productos_detectados'] = array();
        }

        if ( isset( $decoded_response['usage'] ) ) {
            $usage_summary = $this->mm_register_ai_usage( $model, $decoded_response['usage'] );
            $normalized['_ai_usage'] = $usage_summary;
        }
        return $normalized;
    }

    public function mm_get_openai_price_table() {
        return array(
            'gpt-5.5' => array( 'input' => 5.00, 'output' => 15.00 ),
            'gpt-5.4' => array( 'input' => 3.00, 'output' => 10.00 ),
            'gpt-5.4-mini' => array( 'input' => 0.30, 'output' => 1.20 ),
            'gpt-4o' => array( 'input' => 5.00, 'output' => 15.00 ),
            'gpt-4o-mini' => array( 'input' => 0.15, 'output' => 0.60 ),
        );
    }

    public function mm_estimate_openai_cost_usd( $model, $input_tokens, $output_tokens ) {
        $prices = $this->mm_get_openai_price_table();
        $price = isset( $prices[ $model ] ) ? $prices[ $model ] : $prices['gpt-5.5'];
        return round( ( intval( $input_tokens ) / 1000000 ) * floatval( $price['input'] ) + ( intval( $output_tokens ) / 1000000 ) * floatval( $price['output'] ), 6 );
    }

    public function mm_register_ai_usage( $model, $usage, $context = 'pedido_factura' ) {
        $input_tokens = intval( $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0 );
        $output_tokens = intval( $usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0 );
        $total_tokens = intval( $usage['total_tokens'] ?? ( $input_tokens + $output_tokens ) );
        $cost = $this->mm_estimate_openai_cost_usd( $model, $input_tokens, $output_tokens );
        $key = 'mm_ai_usage_' . gmdate( 'Y_m' );
        $month = get_option( $key, array( 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cost_usd' => 0, 'requests' => 0 ) );
        $month['input_tokens'] = intval( $month['input_tokens'] ?? 0 ) + $input_tokens;
        $month['output_tokens'] = intval( $month['output_tokens'] ?? 0 ) + $output_tokens;
        $month['total_tokens'] = intval( $month['total_tokens'] ?? 0 ) + $total_tokens;
        $month['cost_usd'] = round( floatval( $month['cost_usd'] ?? 0 ) + $cost, 6 );
        $month['requests'] = intval( $month['requests'] ?? 0 ) + 1;
        update_option( $key, $month );
        $last = array( 'model' => $model, 'context' => $context, 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens, 'total_tokens' => $total_tokens, 'cost_usd' => $cost, 'processed_at' => current_time( 'mysql' ) );
        update_option( 'mm_ai_usage_last', $last );
        return array( 'last' => $last, 'month' => $month );
    }

    public function mm_build_openai_uploaded_file_payload( $field_name = 'archivo_ia' ) {
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

    public function get_factura_ai_webhook_url() {
        return trim( (string) get_option( 'mm_factura_ai_webhook_url', '' ) );
    }

    public function get_factura_ai_webhook_token() {
        return trim( (string) get_option( 'mm_factura_ai_webhook_token', '' ) );
    }

    public function normalize_factura_ai_response( $response ) {
        if ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $response = $response['data'];
        }

        $products = array();
        $raw_products = $response['productos_detectados'] ?? $response['productos'] ?? $response['items'] ?? array();

        if ( is_array( $raw_products ) ) {
            foreach ( $raw_products as $index => $product ) {
                if ( ! is_array( $product ) ) {
                    continue;
                }

                $products[] = array(
                    'item'        => isset( $product['item'] ) ? intval( $product['item'] ) : $index + 1,
                    'codigo'      => sanitize_text_field( $product['codigo'] ?? $product['sku'] ?? '' ),
                    'nombre'      => sanitize_text_field( $product['nombre'] ?? $product['descripcion'] ?? $product['producto'] ?? '' ),
                    'iva'         => sanitize_text_field( $product['iva'] ?? '' ),
                    'cantidad'    => isset( $product['cantidad'] ) ? intval( $product['cantidad'] ) : 0,
                    'costo'       => isset( $product['costo'] ) ? intval( round( floatval( $product['costo'] ) ) ) : ( isset( $product['valor_unitario'] ) ? intval( round( floatval( $product['valor_unitario'] ) ) ) : 0 ),
                    'valor_total' => isset( $product['valor_total'] ) ? intval( round( floatval( $product['valor_total'] ) ) ) : 0,
                );
            }
        }

        $data = array(
            'numero_factura'       => sanitize_text_field( $response['numero_factura'] ?? $response['factura'] ?? $response['numero'] ?? '' ),
            'proveedor'            => sanitize_text_field( $response['proveedor'] ?? $response['empresa'] ?? '' ),
            'nit_proveedor'        => sanitize_text_field( $response['nit_proveedor'] ?? $response['nit'] ?? '' ),
            'fecha_factura'        => sanitize_text_field( $response['fecha_factura'] ?? $response['fecha'] ?? current_time( 'Y-m-d' ) ),
            'total_factura'        => sanitize_text_field( $response['total_factura'] ?? $response['total'] ?? '' ),
            'iva_detectado'        => sanitize_text_field( $response['iva_detectado'] ?? $response['iva'] ?? '' ),
            'pedido_numero'        => sanitize_text_field( $response['pedido_numero'] ?? $response['pedido'] ?? '' ),
            'productos_detectados' => $products,
            'confianza'            => sanitize_text_field( $response['confianza'] ?? 'alta' ),
            'observaciones_ia'     => sanitize_textarea_field( $response['observaciones_ia'] ?? $response['observaciones'] ?? 'Datos extraídos por IA externa. Revisa antes de confirmar.' ),
            'texto_extraido'       => sanitize_textarea_field( $response['texto_extraido'] ?? '' ),
        );

        if ( ! empty( $data['productos_detectados'] ) ) {
            $data['observaciones_ia'] .= "\nProductos detectados por IA: " . count( $data['productos_detectados'] );
        }

        return $data;
    }

    public function analizar_factura_con_webhook_ia( $texto_manual = '' ) {
        $url = $this->get_factura_ai_webhook_url();
        if ( empty( $url ) ) {
            return null;
        }

        $file_payload = null;

        if ( ! empty( $_FILES['archivo_ia']['tmp_name'] ) && is_readable( $_FILES['archivo_ia']['tmp_name'] ) ) {
            $name = sanitize_file_name( $_FILES['archivo_ia']['name'] ?? 'factura' );
            $type = sanitize_text_field( $_FILES['archivo_ia']['type'] ?? 'application/octet-stream' );
            $raw = file_get_contents( $_FILES['archivo_ia']['tmp_name'] );

            if ( false !== $raw ) {
                $file_payload = array(
                    'filename' => $name,
                    'mime_type' => $type,
                    'base64' => base64_encode( $raw ),
                );
            }
        }

        $payload = array(
            'source' => 'megamundo-logistica',
            'task' => 'analizar_factura_proveedor',
            'texto_manual' => $texto_manual,
            'file' => $file_payload,
            'schema' => array(
                'numero_factura' => 'string',
                'proveedor' => 'string',
                'nit_proveedor' => 'string',
                'fecha_factura' => 'YYYY-MM-DD',
                'total_factura' => 'number',
                'iva_detectado' => 'number',
                'pedido_numero' => 'string',
                'productos_detectados' => array(
                    array(
                        'item' => 'number',
                        'codigo' => 'string',
                        'nombre' => 'string',
                        'iva' => 'number|string',
                        'cantidad' => 'number',
                        'costo' => 'number',
                        'valor_total' => 'number',
                    ),
                ),
                'confianza' => 'alta|media|baja',
                'observaciones_ia' => 'string',
            ),
        );

        $headers = array(
            'Content-Type' => 'application/json',
        );

        $token = $this->get_factura_ai_webhook_token();
        if ( ! empty( $token ) ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_post( $url, array(
            'timeout' => 60,
            'headers' => $headers,
            'body' => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'error' => $response->get_error_message(),
            );
        }

        $code = intval( wp_remote_retrieve_response_code( $response ) );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return array(
                'error' => 'El webhook respondió con código ' . $code . '.',
                'body'  => $body,
            );
        }

        $json = json_decode( $body, true );
        if ( ! is_array( $json ) ) {
            return array(
                'error' => 'El webhook no devolvió JSON válido.',
                'body'  => $body,
            );
        }

        return $this->normalize_factura_ai_response( $json );
    }

    public function build_pedido_ia_file_payload( $field_name = 'archivo_ia' ) {
        if ( empty( $_FILES[ $field_name ]['name'] ) || empty( $_FILES[ $field_name ]['tmp_name'] ) ) {
            return null;
        }

        $tmp = $_FILES[ $field_name ]['tmp_name'];
        if ( ! is_uploaded_file( $tmp ) && ! file_exists( $tmp ) ) {
            return null;
        }

        $raw = file_get_contents( $tmp );
        if ( false === $raw || '' === $raw ) {
            return null;
        }

        $mime = ! empty( $_FILES[ $field_name ]['type'] ) ? sanitize_text_field( $_FILES[ $field_name ]['type'] ) : '';
        if ( empty( $mime ) && function_exists( 'mime_content_type' ) ) {
            $mime = mime_content_type( $tmp );
        }

        return array(
            'filename' => sanitize_file_name( $_FILES[ $field_name ]['name'] ),
            'mime'     => $mime ?: 'application/octet-stream',
            'base64'   => base64_encode( $raw ),
            'size'     => intval( $_FILES[ $field_name ]['size'] ?? strlen( $raw ) ),
        );
    }

    public function analizar_pedido_con_webhook_ia( $texto_manual = '', $file_payload = null ) {
        $webhook_url = $this->get_factura_ai_webhook_url();
        if ( empty( $webhook_url ) ) {
            return array( 'error' => 'No hay webhook de IA configurado.' );
        }

        $token = get_option( 'mm_factura_ai_webhook_token', '' );

        $payload = array(
            'origen'       => 'pedidos',
            'tipo'         => 'pedido_compra',
            'texto_manual' => $texto_manual,
            'archivo'      => $file_payload,
            'file'         => $file_payload,
            'image'        => $file_payload,
            'documento'    => $file_payload,
            'pdf'          => $file_payload,
            'token'        => $token,
            'token_ia'     => $token,
            'instruccion'  => 'Analiza este soporte comercial de MegaMundo Logística. Puede ser factura, pedido, remisión, remito, cotización, captura de WhatsApp, foto o PDF. Devuelve JSON válido con proveedor, numero_factura, fecha_factura, total_factura, moneda y productos_detectados. Cada producto debe tener codigo, nombre, cantidad, costo, precio y observacion. No inventes productos.',
        );

        $headers = array( 'Content-Type' => 'application/json' );
        if ( ! empty( $token ) ) {
            $headers['x-mm-token'] = $token;
            $headers['x-megamundo-token'] = $token;
            $headers['x-megamundo-token'] = $token;
            $headers['X-Megamundo-Token'] = $token;
            $headers['X-MM-Token'] = $token;
        }

        $response = wp_remote_post( $webhook_url, array(
            'timeout' => 90,
            'headers' => $headers,
            'body' => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }

        $code = intval( wp_remote_retrieve_response_code( $response ) );
        $body = trim( wp_remote_retrieve_body( $response ) );

        if ( 401 === $code ) {
            return array( 'error' => 'Token inválido o no autorizado en n8n. Revisa el token guardado en Configuración IA/OCR.' );
        }

        if ( $code < 200 || $code >= 300 ) {
            return array( 'error' => 'Webhook respondió HTTP ' . $code . ': ' . wp_strip_all_tags( $body ) );
        }

        $decoded = $this->mm_extract_json_from_mixed_response( $body );

        if ( ! is_array( $decoded ) ) {
            return array(
                'error' => 'El webhook respondió, pero no devolvió JSON válido.',
                '_debug_body' => substr( wp_strip_all_tags( $body ), 0, 1200 ),
            );
        }

        $decoded = $this->mm_normalize_n8n_ai_response( $decoded );

        if ( empty( $decoded['productos_detectados'] ) ) {
            $decoded['_debug_body'] = substr( wp_strip_all_tags( $body ), 0, 1200 );
        }

        return $decoded;
    }

    public function mm_extract_json_from_mixed_response( $value ) {
        if ( is_array( $value ) ) { return $value; }
        if ( ! is_string( $value ) ) { return null; }
        $value = trim( $value );
        $value = preg_replace( '/^```(?:json)?\s*/i', '', $value );
        $value = preg_replace( '/\s*```$/', '', $value );
        $decoded = json_decode( trim( $value ), true );
        if ( is_array( $decoded ) ) { return $decoded; }
        if ( preg_match( '/\{.*\}/s', $value, $match ) ) {
            $decoded = json_decode( $match[0], true );
            if ( is_array( $decoded ) ) { return $decoded; }
        }
        return null;
    }

    public function mm_normalize_n8n_ai_response( $decoded ) {
        $raw_keys = is_array( $decoded ) ? implode( ', ', array_keys( $decoded ) ) : '';
        foreach ( array( 'data', 'output', 'text', 'content', 'message', 'result', 'response', 'json' ) as $key ) {
            if ( isset( $decoded[ $key ] ) ) {
                $candidate = $this->mm_extract_json_from_mixed_response( $decoded[ $key ] );
                if ( is_array( $candidate ) ) { $decoded = $candidate; break; }
                if ( is_array( $decoded[ $key ] ) ) { $decoded = $decoded[ $key ]; break; }
            }
        }
        if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
            $candidate = $this->mm_extract_json_from_mixed_response( $decoded['choices'][0]['message']['content'] );
            if ( is_array( $candidate ) ) { $decoded = $candidate; }
        }
        if ( isset( $decoded[0] ) && is_array( $decoded[0] ) ) { $decoded = $decoded[0]; }
        if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) { $decoded = $decoded['data']; }
        foreach ( array( 'productos', 'items', 'products', 'line_items', 'productosDetectados' ) as $alt_key ) {
            if ( empty( $decoded['productos_detectados'] ) && ! empty( $decoded[ $alt_key ] ) ) { $decoded['productos_detectados'] = $decoded[ $alt_key ]; }
        }
        $raw_products = isset( $decoded['productos_detectados'] ) && is_array( $decoded['productos_detectados'] ) ? $decoded['productos_detectados'] : array();
        $deduped = array();
        foreach ( $raw_products as $product ) {
            if ( ! is_array( $product ) ) { continue; }
            $codigo = sanitize_text_field( $product['codigo'] ?? $product['sku'] ?? $product['referencia'] ?? $product['barcode'] ?? '' );
            $nombre = sanitize_text_field( $product['nombre'] ?? $product['descripcion'] ?? $product['producto'] ?? $product['name'] ?? '' );
            if ( '' === $codigo && '' === $nombre ) { continue; }
            $cantidad = isset( $product['cantidad'] ) ? intval( $product['cantidad'] ) : ( isset( $product['qty'] ) ? intval( $product['qty'] ) : 0 );
            $costo = isset( $product['costo'] ) ? floatval( $product['costo'] ) : ( isset( $product['costo_unitario'] ) ? floatval( $product['costo_unitario'] ) : ( isset( $product['valor_unitario'] ) ? floatval( $product['valor_unitario'] ) : 0 ) );
            $precio = isset( $product['precio'] ) ? floatval( $product['precio'] ) : ( isset( $product['precio_sugerido'] ) ? floatval( $product['precio_sugerido'] ) : 0 );
            $observacion = sanitize_text_field( $product['observacion'] ?? $product['observación'] ?? $product['iva'] ?? $product['nota'] ?? '' );
            $key = $codigo ? 'codigo:' . $codigo : 'nombre:' . strtolower( $nombre );
            if ( ! isset( $deduped[ $key ] ) ) {
                $deduped[ $key ] = array('codigo'=>$codigo,'nombre'=>$nombre,'cantidad'=>max(0,$cantidad),'costo'=>max(0,$costo),'precio'=>max(0,$precio),'observacion'=>$observacion);
            } else {
                $deduped[ $key ]['cantidad'] += max(0,$cantidad);
                if ( empty( $deduped[ $key ]['costo'] ) && $costo > 0 ) { $deduped[ $key ]['costo'] = $costo; }
                if ( empty( $deduped[ $key ]['precio'] ) && $precio > 0 ) { $deduped[ $key ]['precio'] = $precio; }
            }
        }
        $decoded['productos_detectados'] = array_values( $deduped );
        $decoded['proveedor'] = sanitize_text_field( $decoded['proveedor'] ?? $decoded['supplier'] ?? $decoded['negocio'] ?? '' );
        $decoded['numero_factura'] = sanitize_text_field( $decoded['numero_factura'] ?? $decoded['factura'] ?? $decoded['numero_documento'] ?? $decoded['documento'] ?? '' );
        $decoded['fecha_factura'] = sanitize_text_field( $decoded['fecha_factura'] ?? $decoded['fecha'] ?? '' );
        $decoded['total_factura'] = isset( $decoded['total_factura'] ) ? floatval( $decoded['total_factura'] ) : ( isset( $decoded['total'] ) ? floatval( $decoded['total'] ) : 0 );
        $decoded['moneda'] = sanitize_text_field( $decoded['moneda'] ?? 'COP' );
        $decoded['observaciones_ia'] = sanitize_textarea_field( $decoded['observaciones_ia'] ?? $decoded['observacion'] ?? 'Lectura realizada desde n8n.' );
        $decoded['total_productos'] = count( $decoded['productos_detectados'] );
        $decoded['processed_at'] = sanitize_text_field( $decoded['processed_at'] ?? current_time( 'mysql' ) );
        $decoded['_debug_raw_keys'] = $raw_keys;
        return $decoded;
    }
}
