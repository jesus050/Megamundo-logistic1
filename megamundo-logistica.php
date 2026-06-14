<?php
/**
 * Plugin Name: MegaMundo - Control Logístico e Inventario
 * Description: Sistema modular para gestión de bodega por lotes con flujo de aprobación, IA y tickets automáticos.
 * Version: 4.8.1
 * Author: Jesus Diaz | Creative Invasion
 * License: GPL2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MM_LOGISTICA_VERSION', '4.8.1' );
define( 'MM_LOGISTICA_PATH', plugin_dir_path( __FILE__ ) );

// Cargar Autoloader de PSR-4
require_once MM_LOGISTICA_PATH . 'src/Core/Autoloader.php';
\MegaMundo\Logistica\Core\Autoloader::register();

// Inicialización segura del plugin tras cargar todos los plugins (asegura WooCommerce activo)
function mm_logistica_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'mm_logistica_missing_woocommerce_notice' );
        return;
    }

    // Inicializar Bootstrap de la nueva arquitectura OOP
    \MegaMundo\Logistica\Core\Bootstrap::init();
}
add_action( 'plugins_loaded', 'mm_logistica_init' );

// Registro seguro de hooks de activación y desactivación
register_activation_hook( __FILE__, 'mm_logistica_activar' );
function mm_logistica_activar() {
    require_once MM_LOGISTICA_PATH . 'src/Core/Autoloader.php';
    \MegaMundo\Logistica\Core\Autoloader::register();

    $installer = new \MegaMundo\Logistica\Database\DatabaseInstaller();
    $installer->install();

    $roles_manager = new \MegaMundo\Logistica\Infrastructure\Security\RolesManager();
    $roles_manager->crear_roles();
}

register_deactivation_hook( __FILE__, 'mm_logistica_desactivar' );
function mm_logistica_desactivar() {
    require_once MM_LOGISTICA_PATH . 'src/Core/Autoloader.php';
    \MegaMundo\Logistica\Core\Autoloader::register();

    $roles_manager = new \MegaMundo\Logistica\Infrastructure\Security\RolesManager();
    $roles_manager->remover_roles();
}

// Mensaje de advertencia si WooCommerce no está activo
function mm_logistica_missing_woocommerce_notice() {
    ?>
    <div class="error notice">
        <p><?php _e( '<strong>MegaMundo Logística:</strong> Este plugin requiere que WooCommerce esté instalado y activo.', 'megamundo-logistica' ); ?></p>
    </div>
    <?php
}



/**
 * MegaMundo Premium UI: solo en páginas del plugin (app standalone, shortcode del escáner o admin de lotes).
 */
add_action( 'wp_enqueue_scripts', function() {
    $is_app_page   = isset( $_GET['mm_logistica_app'] );
    $post          = get_post();
    $has_shortcode = $post instanceof WP_Post && has_shortcode( $post->post_content, 'mm_escaner_bodega' );

    if ( ! $is_app_page && ! $has_shortcode ) {
        return;
    }

    wp_enqueue_style(
        'megamundo-premium-ui-force',
        plugin_dir_url( __FILE__ ) . 'assets/css/megamundo-premium-ui-force.css',
        array(),
        MM_LOGISTICA_VERSION
    );
}, 999 );

add_action( 'admin_enqueue_scripts', function() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( ! $screen || 'lotes_ingreso' !== $screen->post_type ) {
        return;
    }

    wp_enqueue_style(
        'megamundo-premium-ui-force-admin',
        plugin_dir_url( __FILE__ ) . 'assets/css/megamundo-premium-ui-force.css',
        array(),
        MM_LOGISTICA_VERSION
    );
}, 999 );

