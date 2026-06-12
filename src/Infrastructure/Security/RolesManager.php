<?php
namespace MegaMundo\Logistica\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class RolesManager {

    public function crear_roles() {
        // 1. Contador: Solo interactúa con el escaneo físico y cantidades
        add_role( 'mm_contador', 'MM Contador de Bodega', array(
            'read'         => true,
            'edit_posts'   => false,
        ) );

        // 2. Ingresador: Modifica costos, precios y ejecuta la carga a WooCommerce
        add_role( 'mm_ingresador', 'MM Ingresador de Bodega', array(
            'read'         => true,
            'edit_posts'   => true,
            'upload_files' => true, // Para subir la foto de la factura
        ) );
    }

    public function remover_roles() {
        remove_role( 'mm_contador' );
        remove_role( 'mm_ingresador' );
    }
}
