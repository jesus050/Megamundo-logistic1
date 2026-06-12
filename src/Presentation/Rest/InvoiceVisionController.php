<?php
namespace MegaMundo\Logistica\Presentation\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_REST_Request;
use WP_Error;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;

class InvoiceVisionController {

    private $permission_guard;

    public function __construct( PermissionGuard $permission_guard ) {
        $this->permission_guard = $permission_guard;
    }

    public function register_routes() {
        register_rest_route( 'megamundo/v1', '/ia/leer-factura', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'procesar_imagen_factura' ),
            'permission_callback' => array( $this, 'verificar_permisos' ),
        ) );

        register_rest_route( 'megamundo/v1', '/ia/analizar-producto', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'analizar_producto_nuevo' ),
            'permission_callback' => array( $this, 'verificar_permisos_producto' ),
        ) );
    }

    public function verificar_permisos() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Debes iniciar sesión para acceder.', array( 'status' => 401 ) );
        }

        if ( ! $this->permission_guard->can_view_finance() ) {
            return new WP_Error( 'rest_forbidden', 'No tienes permisos para procesar facturas con IA.', array( 'status' => 403 ) );
        }

        return true;
    }

    public function verificar_permisos_producto() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Debes iniciar sesión para acceder.', array( 'status' => 401 ) );
        }

        if ( ! $this->permission_guard->can_access_bodega_panel() && ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->is_admin() ) {
            return new WP_Error( 'rest_forbidden', 'No tienes permisos para usar el asistente IA.', array( 'status' => 403 ) );
        }

        return true;
    }

    private function get_api_key() {
        return trim( (string) get_option( 'mm_ia_openai_api_key', '' ) );
    }

    private function get_model() {
        $model = trim( (string) get_option( 'mm_ia_modelo', 'gpt-5.5' ) );
        return $model ? $model : 'gpt-5.5';
    }

    public function procesar_imagen_factura( WP_REST_Request $request ) {
        $files = $request->get_file_params();

        if ( empty( $files['factura'] ) ) {
            return new WP_Error( 'sin_imagen', 'No se recibió ninguna imagen de factura.', array( 'status' => 400 ) );
        }

        $archivo = $files['factura'];
        $check = wp_check_filetype( $archivo['name'] );
        $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        if ( ! in_array( $check['type'], $allowed_mimes, true ) ) {
            return new WP_Error( 'formato_invalido', 'El archivo de la factura no es una imagen válida.', array( 'status' => 400 ) );
        }

        $max_size = 5 * 1024 * 1024;
        if ( $archivo['size'] > $max_size ) {
            return new WP_Error( 'archivo_muy_grande', 'La foto de la factura no debe superar los 5MB.', array( 'status' => 400 ) );
        }

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'sin_api_key', 'Falta configurar la API Key de OpenAI en Ajustes.', array( 'status' => 400 ) );
        }

        $datos_imagen  = file_get_contents( $archivo['tmp_name'] );
        $imagen_base64 = base64_encode( $datos_imagen );
        $mime_type     = mime_content_type( $archivo['tmp_name'] );

        $system_prompt = 'Eres un asistente de logística experto. Analiza la imagen de esta factura de proveedor. Extrae los productos y devuelve ÚNICAMENTE un objeto JSON con la estructura exacta: {"productos": [{"nombre": "string", "cantidad": int, "costo_unitario": float}]}. Ignora totales, impuestos, fechas y textos publicitarios. No uses markdown, solo JSON puro.';

        $cuerpo_peticion = array(
            'model' => $this->get_model(),
            'response_format' => array( 'type' => 'json_object' ),
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => $system_prompt,
                ),
                array(
                    'role'    => 'user',
                    'content' => array(
                        array( 'type' => 'text', 'text' => 'Extrae los productos de esta factura.' ),
                        array( 'type' => 'image_url', 'image_url' => array( 'url' => "data:{$mime_type};base64,{$imagen_base64}" ) ),
                    ),
                ),
            ),
            'max_tokens'  => 1500,
            'temperature' => 0.1,
        );

        $respuesta = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'method'  => 'POST',
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $cuerpo_peticion ),
        ) );

        if ( is_wp_error( $respuesta ) ) {
            return new WP_Error( 'error_api', 'No se pudo conectar con OpenAI: ' . $respuesta->get_error_message(), array( 'status' => 500 ) );
        }

        $cuerpo_respuesta = json_decode( wp_remote_retrieve_body( $respuesta ), true );
        if ( isset( $cuerpo_respuesta['error'] ) ) {
            return new WP_Error( 'error_openai', $cuerpo_respuesta['error']['message'], array( 'status' => 500 ) );
        }

        $json_extraido = $cuerpo_respuesta['choices'][0]['message']['content'];
        $datos_factura = json_decode( $json_extraido, true );

        return rest_ensure_response( array(
            'success' => true,
            'mensaje' => 'Factura procesada con éxito.',
            'datos'   => isset( $datos_factura['productos'] ) ? $datos_factura['productos'] : array(),
        ) );
    }

    public function analizar_producto_nuevo( WP_REST_Request $request ) {
        if ( ! get_option( 'mm_ia_habilitada', 0 ) ) {
            return new WP_Error( 'ia_deshabilitada', 'El Asistente IA no está activado en Ajustes.', array( 'status' => 400 ) );
        }

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            return new WP_Error( 'sin_api_key', 'Falta configurar la API Key de OpenAI en Ajustes.', array( 'status' => 400 ) );
        }

        $files = $request->get_file_params();
        if ( empty( $files['foto_producto'] ) ) {
            return new WP_Error( 'sin_imagen', 'Debes subir una foto del producto.', array( 'status' => 400 ) );
        }

        $archivo = $files['foto_producto'];
        $check = wp_check_filetype( $archivo['name'] );
        $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        if ( ! in_array( $check['type'], $allowed_mimes, true ) ) {
            return new WP_Error( 'formato_invalido', 'La foto del producto no es una imagen válida.', array( 'status' => 400 ) );
        }

        $datos_imagen  = file_get_contents( $archivo['tmp_name'] );
        $imagen_base64 = base64_encode( $datos_imagen );
        $mime_type     = mime_content_type( $archivo['tmp_name'] );

        $system_prompt = 'Eres un asistente de catalogación de productos para una bodega. Analiza la imagen del producto o su empaque y devuelve ÚNICAMENTE un objeto JSON con esta estructura exacta: {"nombre_sugerido":"string","categoria_sugerida":"string","descripcion_corta":"string","palabras_clave":"string","marca_detectada":"string","observaciones":"string","confianza":"alta|media|baja"}. Responde en español, sin markdown.';

        $cuerpo_peticion = array(
            'model' => $this->get_model(),
            'response_format' => array( 'type' => 'json_object' ),
            'messages' => array(
                array( 'role' => 'system', 'content' => $system_prompt ),
                array(
                    'role' => 'user',
                    'content' => array(
                        array( 'type' => 'text', 'text' => 'Analiza esta foto del producto y sugiere nombre, categoría, descripción y palabras clave.' ),
                        array( 'type' => 'image_url', 'image_url' => array( 'url' => "data:{$mime_type};base64,{$imagen_base64}" ) ),
                    ),
                ),
            ),
            'max_tokens'  => 900,
            'temperature' => 0.2,
        );

        $respuesta = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'method'  => 'POST',
            'timeout' => 45,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $cuerpo_peticion ),
        ) );

        if ( is_wp_error( $respuesta ) ) {
            return new WP_Error( 'error_api', 'No se pudo conectar con OpenAI: ' . $respuesta->get_error_message(), array( 'status' => 500 ) );
        }

        $cuerpo_respuesta = json_decode( wp_remote_retrieve_body( $respuesta ), true );
        if ( isset( $cuerpo_respuesta['error'] ) ) {
            return new WP_Error( 'error_openai', $cuerpo_respuesta['error']['message'], array( 'status' => 500 ) );
        }

        $json_extraido = $cuerpo_respuesta['choices'][0]['message']['content'];
        $datos = json_decode( $json_extraido, true );
        if ( ! is_array( $datos ) ) {
            return new WP_Error( 'json_invalido', 'La IA devolvió una respuesta inválida.', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'mensaje' => 'Producto analizado con IA.',
            'datos'   => array(
                'nombre_sugerido'    => sanitize_text_field( $datos['nombre_sugerido'] ?? '' ),
                'categoria_sugerida' => sanitize_text_field( $datos['categoria_sugerida'] ?? '' ),
                'descripcion_corta'  => sanitize_textarea_field( $datos['descripcion_corta'] ?? '' ),
                'palabras_clave'     => sanitize_text_field( $datos['palabras_clave'] ?? '' ),
                'observaciones'      => sanitize_textarea_field( $datos['observaciones'] ?? '' ),
                'marca_detectada'   => sanitize_text_field( $datos['marca_detectada'] ?? '' ),
                'confianza'         => sanitize_text_field( $datos['confianza'] ?? '' ),
            ),
        ) );
    }
}
