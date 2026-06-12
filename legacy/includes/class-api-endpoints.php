<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_API {

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'registrar_rutas' ) );
    }

    public function registrar_rutas() {
        // Ruta: misitio.com/wp-json/megamundo/v1/escaner/agregar
        register_rest_route( 'megamundo/v1', '/escaner/agregar', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'agregar_item_escaneado' ),
            'permission_callback' => array( $this, 'verificar_permisos' ), 
        ) );
    }

    public function verificar_permisos() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Debes iniciar sesión para acceder.', array( 'status' => 401 ) );
        }
        
        $user = wp_get_current_user();
        $roles_permitidos = array( 'mm_contador', 'mm_ingresador', 'administrator' );
        
        if ( empty( array_intersect( $roles_permitidos, (array) $user->roles ) ) ) {
            return new WP_Error( 'rest_forbidden', 'No tienes permisos para usar el escáner.', array( 'status' => 403 ) );
        }
        
        return true;
    }

    public function agregar_item_escaneado( WP_REST_Request $request ) {
        global $wpdb;
        
        // Al usar FormData in JS, los textos llegan por get_params() y los archivos por get_file_params()
        $parametros = $request->get_params();
        $archivos   = $request->get_file_params();

        $lote_id      = isset( $parametros['lote_id'] ) ? intval( $parametros['lote_id'] ) : 0;
        $sku_producto = isset( $parametros['sku_producto'] ) ? sanitize_text_field( $parametros['sku_producto'] ) : '';
        $cantidad     = isset( $parametros['cantidad'] ) ? intval( $parametros['cantidad'] ) : 0;
        $nombre_nuevo = isset( $parametros['nombre_nuevo'] ) ? sanitize_text_field( $parametros['nombre_nuevo'] ) : '';

        if ( ! $lote_id || empty( $sku_producto ) || ! $cantidad ) {
            return new WP_Error( 'datos_invalidos', 'Faltan datos en el escáner', array( 'status' => 400 ) );
        }

        // VALIDACIÓN: Verificar que el Lote exista y sea del CPT correcto
        if ( get_post_type( $lote_id ) !== 'lotes_ingreso' ) {
            return new WP_Error( 'lote_invalido', 'El lote especificado no existe o es inválido.', array( 'status' => 400 ) );
        }

        // VALIDACIÓN: Verificar que el lote no esté ya cerrado/cargado (impedir cambios post-sincronización)
        $estado_lote = get_post_status( $lote_id );
        if ( 'mm_cargado' === $estado_lote ) {
            return new WP_Error( 'lote_cerrado', 'El lote ya ha sido procesado e inyectado. No se permiten más escaneos.', array( 'status' => 400 ) );
        }

        $producto_id = wc_get_product_id_by_sku( $sku_producto );
        $es_nuevo = false;

        // Si el producto no existe...
        if ( ! $producto_id ) {
            if ( empty( $nombre_nuevo ) ) {
                return new WP_Error( 'requiere_nombre', 'Este código no existe. Digita su nombre.', array( 'status' => 404 ) );
            }

            // Creamos el producto
            $nuevo_producto = array(
                'post_title'   => $nombre_nuevo,
                'post_status'  => 'draft',
                'post_type'    => 'product',
            );
            
            $producto_id = wp_insert_post( $nuevo_producto );
            update_post_meta( $producto_id, '_sku', $sku_producto );
            update_post_meta( $producto_id, '_manage_stock', 'yes' );
            
            // MAGIA MULTIMEDIA: Procesar, Redimensionar y Convertir a WebP
            if ( ! empty( $archivos['foto_producto'] ) ) {
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );

                $archivo_temp    = $archivos['foto_producto']['tmp_name'];
                $nombre_original = pathinfo( $archivos['foto_producto']['name'], PATHINFO_FILENAME );

                // SEGURIDAD: Validar tipo de archivo y mime type
                $check = wp_check_filetype( $archivos['foto_producto']['name'] );
                $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
                if ( ! in_array( $check['type'], $allowed_mimes, true ) ) {
                    return new WP_Error( 'formato_invalido', 'El archivo no es una imagen válida.', array( 'status' => 400 ) );
                }

                // SEGURIDAD: Validar peso máximo (5MB)
                $max_size = 5 * 1024 * 1024;
                if ( $archivos['foto_producto']['size'] > $max_size ) {
                    return new WP_Error( 'archivo_muy_grande', 'La foto no debe superar los 5MB.', array( 'status' => 400 ) );
                }

                // Instanciamos el motor de imágenes nativo de WordPress
                $editor = wp_get_image_editor( $archivo_temp );

                if ( ! is_wp_error( $editor ) ) {
                    // 1. Redimensionar a un máximo de 1200px (mantiene proporción)
                    $editor->resize( 1200, 1200, false );

                    // 2. Definir la nueva ruta temporal y guardar como formato WebP
                    $nuevo_temp = $archivo_temp . '.webp';
                    $guardado   = $editor->save( $nuevo_temp, 'image/webp' );

                    // 3. Si se comprimió con éxito, engañamos a WordPress pasándole el archivo ligero
                    if ( ! is_wp_error( $guardado ) ) {
                        $archivos['foto_producto']['tmp_name'] = $nuevo_temp;
                        $archivos['foto_producto']['type']     = 'image/webp';
                        $archivos['foto_producto']['name']     = sanitize_title( $nombre_original ) . '.webp';
                        $archivos['foto_producto']['size']     = filesize( $nuevo_temp );
                    }
                }

                // Subimos la imagen final optimizada y la asociamos al producto
                $attachment_id = media_handle_sideload( $archivos['foto_producto'], $producto_id );
                
                if ( ! is_wp_error( $attachment_id ) ) {
                    set_post_thumbnail( $producto_id, $attachment_id );
                }
            }

            $es_nuevo = true;
        }

        // Insertar en el Lote de Ingreso con trazabilidad y auditoría
        $tabla = $wpdb->prefix . 'mm_lote_items';
        $current_user_id = get_current_user_id();
        $now = current_time( 'mysql' );

        $insertado = $wpdb->insert(
            $tabla,
            array(
                'lote_id'          => $lote_id,
                'producto_id'      => $producto_id,
                'sku'              => $sku_producto,
                'cantidad_contada' => $cantidad,
                'created_by'       => $current_user_id,
                'created_at'       => $now,
                'updated_at'       => $now,
            ),
            array( '%d', '%d', '%s', '%d', '%d', '%s', '%s' )
        );

        if ( $insertado ) {
            $nombre_oficial = get_the_title( $producto_id );
            $mensaje = '✅ ' . $cantidad . 'x ' . $nombre_oficial . ' guardado.';
            if ( $es_nuevo ) {
                $mensaje = '✨ Creado con éxito: ' . $nombre_oficial;
            }
            return rest_ensure_response( array( 'success' => true, 'mensaje' => $mensaje ) );
        }

        return new WP_Error( 'db_error', 'Error guardando en base de datos', array( 'status' => 500 ) );
    }
}

new MM_Logistica_API();
