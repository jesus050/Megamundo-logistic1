<?php
namespace MegaMundo\Logistica\Presentation\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_REST_Request;
use WP_Error;
use MegaMundo\Logistica\Application\Scanner\ScannerService;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;

class ScannerController {

    private $scanner_service;
    private $permission_guard;

    public function __construct( ScannerService $scanner_service, PermissionGuard $permission_guard ) {
        $this->scanner_service  = $scanner_service;
        $this->permission_guard = $permission_guard;
    }

    public function register_routes() {
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

        if ( ! $this->permission_guard->can_scan() ) {
            return new WP_Error( 'rest_forbidden', 'No tienes permisos para usar el escáner.', array( 'status' => 403 ) );
        }

        return true;
    }

    public function agregar_item_escaneado( WP_REST_Request $request ) {
        $params = $request->get_params();
        $files  = $request->get_file_params();

        $lote_id       = isset( $params['lote_id'] ) ? intval( $params['lote_id'] ) : 0;
        $sku_producto  = isset( $params['sku_producto'] ) ? sanitize_text_field( $params['sku_producto'] ) : '';
        $cantidad      = isset( $params['cantidad'] ) ? intval( $params['cantidad'] ) : 0;
        $nombre_nuevo  = isset( $params['nombre_nuevo'] ) ? sanitize_text_field( $params['nombre_nuevo'] ) : '';
        $foto_producto = isset( $files['foto_producto'] ) ? $files['foto_producto'] : null;

        if ( ! $lote_id || empty( $sku_producto ) || ! $cantidad ) {
            return new WP_Error( 'datos_invalidos', 'Faltan datos obligatorios en el escáner.', array( 'status' => 400 ) );
        }

        $result = $this->scanner_service->registrar_escaneo(
            $lote_id,
            $sku_producto,
            $cantidad,
            $nombre_nuevo,
            $foto_producto,
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( $result );
    }
}
