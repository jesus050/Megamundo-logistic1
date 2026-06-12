<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_OpenAI {

    // Asegúrate de reemplazar esto con tu clave real de OpenAI más adelante
    private $api_key = 'TU_CLAVE_API_DE_OPENAI_AQUI'; 

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'registrar_ruta_ia' ) );
    }

    public function registrar_ruta_ia() {
        // Ruta: misitio.com/wp-json/megamundo/v1/ia/leer-factura
        register_rest_route( 'megamundo/v1', '/ia/leer-factura', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'procesar_imagen_factura' ),
            'permission_callback' => array( $this, 'verificar_permisos' ), 
        ) );
    }

    public function verificar_permisos() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Debes iniciar sesión para acceder.', array( 'status' => 401 ) );
        }
        
        $user = wp_get_current_user();
        $roles_permitidos = array( 'mm_ingresador', 'administrator' );
        
        if ( empty( array_intersect( $roles_permitidos, (array) $user->roles ) ) ) {
            return new WP_Error( 'rest_forbidden', 'No tienes permisos para procesar facturas con IA.', array( 'status' => 403 ) );
        }
        
        return true;
    }

    public function procesar_imagen_factura( WP_REST_Request $request ) {
        // 1. Recibir el archivo de imagen desde la tablet
        $archivos = $request->get_file_params();
        
        if ( empty( $archivos['factura'] ) ) {
            return new WP_Error( 'sin_imagen', 'No se recibió ninguna imagen de factura.', array( 'status' => 400 ) );
        }

        $archivo = $archivos['factura'];

        // SEGURIDAD: Validar tipo de archivo y mime type de la factura
        $check = wp_check_filetype( $archivo['name'] );
        $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        if ( ! in_array( $check['type'], $allowed_mimes, true ) ) {
            return new WP_Error( 'formato_invalido', 'El archivo de la factura no es una imagen válida.', array( 'status' => 400 ) );
        }

        // SEGURIDAD: Validar peso máximo (5MB)
        $max_size = 5 * 1024 * 1024;
        if ( $archivo['size'] > $max_size ) {
            return new WP_Error( 'archivo_muy_grande', 'La foto de la factura no debe superar los 5MB.', array( 'status' => 400 ) );
        }
        
        // 2. Convertir la imagen a Base64 para que la API de OpenAI pueda leerla
        $datos_imagen  = file_get_contents( $archivo['tmp_name'] );
        $imagen_base64 = base64_encode( $datos_imagen );
        $mime_type     = mime_content_type( $archivo['tmp_name'] );

        // 3. El Prompt Maestro (La instrucción estricta)
        $system_prompt = "Eres un asistente de logística experto. Analiza la imagen de esta factura de proveedor. Extrae los productos y devuelve ÚNICAMENTE un objeto JSON con la siguiente estructura exacta: {\"productos\": [{\"nombre\": \"string\", \"cantidad\": int, \"costo_unitario\": float}]}. Ignora totales, impuestos, fechas y textos publicitarios. No uses markdown, solo JSON puro.";

        // 4. Armar el cuerpo de la petición para GPT-4o
        $cuerpo_peticion = array(
            'model' => get_option( 'mm_openai_invoice_model', 'gpt-5.5' ),
            'response_format' => array( 'type' => 'json_object' ), // Forzamos salida JSON
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => $system_prompt
                ),
                array(
                    'role'    => 'user',
                    'content' => array(
                        array( 'type' => 'text', 'text' => 'Extrae los productos de esta factura.' ),
                        array( 'type' => 'image_url', 'image_url' => array( 'url' => "data:{$mime_type};base64,{$imagen_base64}" ) )
                    )
                )
            ),
            'max_tokens' => 1500, // Margen amplio por si la factura es muy larga
            'temperature' => 0.1  // Temperatura baja para que sea preciso y no invente datos
        );

        // 5. Enviar la petición a OpenAI
        $respuesta = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'method'  => 'POST',
            'timeout' => 45, // Le damos 45 segundos porque leer imágenes toma tiempo
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $cuerpo_peticion ),
        ) );

        // 6. Manejo de errores de conexión
        if ( is_wp_error( $respuesta ) ) {
            return new WP_Error( 'error_api', 'No se pudo conectar con OpenAI: ' . $respuesta->get_error_message(), array( 'status' => 500 ) );
        }

        // 7. Decodificar la respuesta JSON que nos entrega la IA
        $cuerpo_respuesta = json_decode( wp_remote_retrieve_body( $respuesta ), true );
        
        if ( isset( $cuerpo_respuesta['error'] ) ) {
            return new WP_Error( 'error_openai', $cuerpo_respuesta['error']['message'], array( 'status' => 500 ) );
        }

        // Extraer el texto generado por el modelo y convertirlo a un array de PHP
        $json_extraido = $cuerpo_respuesta['choices'][0]['message']['content'];
        $datos_factura = json_decode( $json_extraido, true );

        // 8. Devolver los datos limpios a nuestra aplicación
        return rest_ensure_response( array(
            'success' => true,
            'mensaje' => 'Factura procesada con éxito.',
            'datos'   => $datos_factura['productos']
        ) );
    }
}

new MM_Logistica_OpenAI();
