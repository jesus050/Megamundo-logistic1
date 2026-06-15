<?php
namespace MegaMundo\Logistica\Presentation\Front;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

class ScannerViewController {
    use Concerns\NotificacionesTrait;
    use Concerns\ReportesTrait;
    use Concerns\ProductosNuevosTrait;
    use Concerns\UsuariosTrait;
    use Concerns\SincronizacionTrait;
    use Concerns\HistorialTrait;
    use Concerns\SistemaTrait;
    use Concerns\FacturasTrait;
    use Concerns\PedidosTrait;
    use Concerns\PreciosTrait;
    use Concerns\MekanoTrait;
    use Concerns\ConfigIaTrait;
    use Concerns\ExhibicionTrait;
    use Concerns\BodegaTrait;
    use Concerns\ProductosSinImagenTrait;
    use Concerns\EtiquetasTrait;
    use Concerns\JefaturaTrait;
    use Concerns\LoteDetailTrait;
    use Concerns\RotacionTrait;

    private $lote_repo;
    private $item_repo;
    private $permission_guard;
    private $openai_service;
    private $mekano_service;

    public function __construct(
        LoteRepository $lote_repo,
        LoteItemRepository $item_repo = null,
        PermissionGuard $permission_guard = null,
        OpenAiVisionService $openai_service = null,
        MekanoExportService $mekano_service = null
    ) {
        $this->lote_repo        = $lote_repo;
        $this->item_repo        = $item_repo;
        $this->permission_guard = $permission_guard ?: new PermissionGuard();
        $this->openai_service   = $openai_service ?: new OpenAiVisionService();
        $this->mekano_service   = $mekano_service ?: new MekanoExportService( $this->lote_repo, $this->item_repo );
    }

    public function register() {
        add_shortcode( 'mm_escaner_bodega', array( $this, 'render_escaner' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'cargar_scripts' ) );
        add_action( 'template_redirect', array( $this, 'render_standalone_app' ) );
        add_action( 'wp_ajax_mm_app_resolver_producto_sin_imagen', array( $this, 'ajax_resolver_producto_sin_imagen' ) );
        add_action( 'wp_ajax_mm_app_guardar_exhibicion', array( $this, 'ajax_guardar_exhibicion' ) );
        add_action( 'wp_ajax_mm_app_mover_exhibicion', array( $this, 'ajax_mover_exhibicion' ) );
        add_action( 'wp_ajax_mm_app_guardar_config_ia', array( $this, 'ajax_guardar_config_ia' ) );
        add_action( 'wp_ajax_mm_app_probar_config_ia', array( $this, 'ajax_probar_config_ia' ) );
        add_action( 'wp_ajax_mm_app_probar_ia_producto', array( $this, 'ajax_probar_ia_producto' ) );
        add_action( 'wp_ajax_mm_app_analizar_pedido_ia', array( $this, 'ajax_analizar_pedido_ia' ) );
        add_action( 'init', array( $this, 'register_pedidos_post_type' ) );
        add_action( 'wp_ajax_mm_app_exportar_mekano_lote', array( $this, 'ajax_exportar_mekano_lote' ) );
        add_action( 'wp_ajax_mm_app_exportar_mekano_xlsx_lote', array( $this, 'ajax_exportar_mekano_xlsx_lote' ) );
        add_action( 'wp_ajax_mm_app_guardar_precios_multiples', array( $this, 'ajax_guardar_precios_multiples' ) );

        // Acciones AJAX de la plataforma interna.
        add_action( 'wp_ajax_mm_app_guardar_precios', array( $this, 'ajax_guardar_precios_app' ) );
        add_action( 'wp_ajax_mm_app_enviar_aprobacion', array( $this, 'ajax_enviar_aprobacion_app' ) );
        add_action( 'wp_ajax_mm_app_aprobar_cargar', array( $this, 'ajax_aprobar_cargar_app' ) );
        add_action( 'wp_ajax_mm_app_marcar_etiqueta_impresa', array( $this, 'ajax_marcar_etiqueta_impresa_app' ) );
        add_action( 'wp_ajax_mm_app_marcar_notificacion_vista', array( $this, 'ajax_marcar_notificacion_vista_app' ) );
        add_action( 'wp_ajax_mm_app_guardar_revision_producto_nuevo', array( $this, 'ajax_guardar_revision_producto_nuevo' ) );
        add_action( 'wp_ajax_mm_app_reintentar_sincronizacion_lote', array( $this, 'ajax_reintentar_sincronizacion_lote' ) );
        add_action( 'wp_ajax_mm_app_guardar_factura_lote', array( $this, 'ajax_guardar_factura_lote' ) );
        add_action( 'wp_ajax_mm_app_analizar_factura_ia', array( $this, 'ajax_analizar_factura_ia' ) );
        add_action( 'wp_ajax_mm_app_bodega_guardar_factura_ia', array( $this, 'ajax_bodega_guardar_factura_ia' ) );
        add_action( 'wp_ajax_mm_app_guardar_factura_ai_webhook', array( $this, 'ajax_guardar_factura_ai_webhook' ) );
        add_action( 'wp_ajax_mm_app_crear_lote_desde_factura', array( $this, 'ajax_crear_lote_desde_factura' ) );
        add_action( 'wp_ajax_mm_app_eliminar_factura_lote', array( $this, 'ajax_eliminar_factura_lote' ) );
        add_action( 'wp_ajax_mm_app_guardar_pedido_compra', array( $this, 'ajax_guardar_pedido_compra' ) );
        add_action( 'wp_ajax_mm_app_guardar_bodega_receipt', array( $this, 'ajax_guardar_bodega_receipt' ) );
        add_action( 'wp_ajax_mm_app_finalizar_bodega_lote', array( $this, 'ajax_finalizar_bodega_lote' ) );
        add_action( 'wp_ajax_mm_app_crear_lote_desde_pedido', array( $this, 'ajax_crear_lote_desde_pedido' ) );
        add_action( 'wp_ajax_mm_app_actualizar_factura_pedido', array( $this, 'ajax_actualizar_factura_pedido' ) );
        add_action( 'wp_ajax_mm_app_cerrar_pedido_compra', array( $this, 'ajax_cerrar_pedido_compra' ) );
        add_action( 'wp_ajax_mm_app_rotacion_preview', array( $this, 'ajax_rotacion_importar_preview' ) );
        add_action( 'wp_ajax_mm_app_rotacion_confirmar', array( $this, 'ajax_rotacion_importar_confirmar' ) );
    }

    private function plugin_file() {
        return dirname( dirname( dirname( __DIR__ ) ) ) . '/megamundo-logistica.php';
    }

    private function app_slug() {
        return isset( $_GET['mm_logistica_app'] ) ? sanitize_key( wp_unslash( $_GET['mm_logistica_app'] ) ) : '';
    }

    public function cargar_scripts() {
        global $post;

        $is_shortcode = is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'mm_escaner_bodega' );
        $is_app_route = ! empty( $this->app_slug() );

        if ( ! $is_shortcode && ! $is_app_route ) {
            return;
        }

        wp_enqueue_style(
            'mm-app-bodega',
            plugins_url( 'assets/css/app-bodega.css', $this->plugin_file() ),
            array(),
            MM_LOGISTICA_VERSION
        );

        wp_enqueue_script(
            'mm-app-bodega',
            plugins_url( 'assets/js/app-bodega.js', $this->plugin_file() ),
            array(),
            MM_LOGISTICA_VERSION,
            true
        );

        wp_localize_script( 'mm-app-bodega', 'mmApiSettings', array(
            'root'      => esc_url_raw( rest_url() ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'isLogged'  => is_user_logged_in(),
            'loginUrl'  => wp_login_url( esc_url_raw( home_url( add_query_arg( null, null ) ) ) ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'zxingUrl'  => plugins_url( 'assets/vendor/zxing/zxing-browser.min.js', $this->plugin_file() ),
        ) );
    }

    public function render_standalone_app() {
        if ( isset( $_GET['mm_export_report'] ) ) {
            $this->export_report_csv();
        }

        $app = $this->app_slug();
        if ( empty( $app ) ) {
            return;
        }

        // Ruta vieja compatible: escaner => bodega.
        if ( 'escaner' === $app ) {
            $app = 'bodega';
        }

        if ( 'panel' === $app ) {
            if ( ! is_user_logged_in() ) {
                wp_safe_redirect( wp_login_url( home_url( '/?mm_logistica_app=panel' ) ) );
                exit;
            }
            wp_safe_redirect( home_url( '/?mm_logistica_app=dashboard' ) );
            exit;
        }

        if ( ! in_array( $app, array( 'dashboard', 'pedidos', 'bodega', 'exhibicion', 'precios', 'jefatura', 'etiquetas', 'notificaciones', 'reportes', 'reports', 'productos-nuevos', 'sincronizacion', 'usuarios', 'historial', 'sistema', 'facturas', 'mekano', 'rotacion', 'exhibicion'), true ) ) {
            return;
        }

        show_admin_bar( false );
        $this->cargar_scripts();

        status_header( 200 );
        nocache_headers();
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
            <meta name="robots" content="noindex,nofollow">
            <title><?php echo esc_html( $this->get_page_title( $app ) ); ?></title>
            <?php wp_head(); ?>
        </head>
        <body class="mm-logistica-standalone mm-logistica-role-app mm-app-<?php echo esc_attr( $app ); ?>">
            <?php echo $this->render_app_by_role( $app ); ?>
            <?php wp_footer(); ?>
        </body>
        </html><?php
        exit;
    }

    private function get_page_title( $app ) {
        $titles = array(
            'dashboard'=> 'MegaMundo Logística | Dashboard',
            'notificaciones' => 'MegaMundo Logística | Notificaciones',
            'reportes' => 'MegaMundo Logística | Reportes',
            'productos-nuevos' => 'MegaMundo Logística | Productos nuevos',
            'sincronizacion' => 'MegaMundo Logística | Sincronización',
            'usuarios' => 'MegaMundo Logística | Usuarios',
            'historial' => 'MegaMundo Logística | Historial',
            'sistema' => 'MegaMundo Logística | Sistema',
            'facturas' => 'MegaMundo Logística | Facturas',
            'mekano' => 'MegaMundo Logística | Mekano',
            'rotacion' => 'MegaMundo Logística | Rotación',
            'bodega'   => 'MegaMundo Bodega | Escáner',
            'exhibicion' => 'MegaMundo Logística | Exhibición',
            'precios'  => 'MegaMundo Precios | Liquidación',
            'jefatura' => 'MegaMundo Jefatura | Control Logístico',
            'etiquetas' => 'MegaMundo Etiquetas | Impresión',
        );
        return $titles[ $app ] ?? 'MegaMundo Logística';
    }


    private function render_safe_app_section( $section_label, $callback ) {
        try {
            if ( is_callable( $callback ) ) {
                return call_user_func( $callback );
            }

            return $this->render_internal_error_app(
                $section_label,
                'La pantalla solicitada no está disponible en esta versión del plugin.'
            );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error en sección ' . $section_label . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine() );

            return $this->render_internal_error_app(
                $section_label,
                $e->getMessage()
            );
        }
    }

    private function render_internal_error_app( $section_label, $message = '' ) {
        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'dashboard' ); ?>
            <section class="mm-platform-main">
                <section class="mm-safe-error-card">
                    <div class="mm-safe-error-icon">⚠️</div>
                    <div>
                        <span class="mm-eyebrow">Error controlado</span>
                        <h1>No se pudo cargar <?php echo esc_html( $section_label ); ?></h1>
                        <p>La plataforma sigue activa. Esta sección necesita revisión técnica, pero no se cayó todo el sistema.</p>
                        <?php if ( current_user_can( 'manage_options' ) && ! empty( $message ) ) : ?>
                            <details>
                                <summary>Ver detalle técnico</summary>
                                <pre><?php echo esc_html( $message ); ?></pre>
                            </details>
                        <?php endif; ?>
                        <div class="mm-safe-error-actions">
                            <a class="mm-mini-primary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=dashboard' ) ); ?>">Volver al Dashboard</a>
                            <a class="mm-mini-secondary" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Ver plugins</a>
                        </div>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }


    private function render_app_by_role( $app ) {
        if ( ! is_user_logged_in() ) {
            return $this->render_login_app();
        }

        if ( 'bodega' === $app && ! $this->permission_guard->can_access_bodega_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para entrar al panel de bodega.' );
        }
        if ( 'precios' === $app && ! $this->permission_guard->can_access_precios_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para entrar al panel de precios.' );
        }
        if ( 'jefatura' === $app && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'Solo jefatura puede entrar a este panel.' );
        }
        if ( 'etiquetas' === $app && ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para entrar al panel de etiquetas.' );
        }

        if ( 'pedidos' === $app ) {
            if ( ! method_exists( $this, 'render_pedidos_dashboard' ) ) {
                return $this->render_internal_error_app( 'Pedidos / Compras', 'La pantalla de Pedidos no está disponible en esta versión.' );
            }
            return $this->render_safe_app_section( 'Pedidos / Compras', array( $this, 'render_pedidos_dashboard' ) );
        }

        if ( 'mekano' === $app ) {
            if ( ! method_exists( $this, 'render_mekano_dashboard' ) ) {
                return $this->render_internal_error_app( 'Exportar Mekano', 'La pantalla de Mekano no está disponible en esta versión.' );
            }
            return $this->render_safe_app_section( 'Exportar Mekano', array( $this, 'render_mekano_dashboard' ) );
        }

        if ( 'facturas' === $app ) {
            return $this->render_safe_app_section( 'Facturas', array( $this, 'render_facturas_dashboard' ) );
        }

        if ( 'sistema' === $app ) {
            return $this->render_safe_app_section( 'Sistema', array( $this, 'render_sistema_dashboard' ) );
        }

        if ( 'rotacion' === $app ) {
            if ( ! $this->permission_guard->can_access_jefatura_panel() && ! $this->permission_guard->is_admin() ) {
                return $this->render_denied_app( 'No tienes permiso para ver el módulo de rotación.' );
            }
            return $this->render_safe_app_section( 'Rotación', array( $this, 'render_rotacion_dashboard' ) );
        }

        if ( 'dashboard' === $app ) {
            return $this->render_safe_app_section( 'Dashboard', array( $this, 'render_dashboard_general' ) );
        }

        if ( 'notificaciones' === $app ) {
            return $this->render_safe_app_section( 'Notificaciones', array( $this, 'render_notificaciones_dashboard' ) );
        }

        if ( 'reportes' === $app || 'reports' === $app ) {
            return $this->render_safe_app_section( 'Reportes', array( $this, 'render_reportes_dashboard' ) );
        }

        if ( 'productos-nuevos' === $app ) {
            return $this->render_safe_app_section( 'Productos nuevos', array( $this, 'render_productos_nuevos_dashboard' ) );
        }

        if ( 'sincronizacion' === $app ) {
            return $this->render_safe_app_section( 'Sincronización', array( $this, 'render_sincronizacion_dashboard' ) );
        }

        if ( 'usuarios' === $app ) {
            return $this->render_safe_app_section( 'Usuarios', array( $this, 'render_usuarios_dashboard' ) );
        }

        if ( 'historial' === $app ) {
            return $this->render_safe_app_section( 'Historial', array( $this, 'render_historial_producto_dashboard' ) );
        }

        if ( 'exhibicion' === $app ) {
            return $this->render_safe_app_section( 'Exhibición', array( $this, 'render_exhibicion' ) );
        }

        if ( 'bodega' === $app ) {
            $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
            return $this->render_safe_app_section( 'Bodega', function() use ( $lote_id ) {
                return $this->render_escaner( array( 'lote_id' => $lote_id ), true );
            } );
        }

        if ( 'precios' === $app ) {
            $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
            if ( $lote_id > 0 ) {
                return $this->render_lote_detail_app( 'precios', $lote_id );
            }
            return $this->render_safe_app_section( 'Precios', array( $this, 'render_precios_dashboard' ) );
        }

        if ( 'etiquetas' === $app ) {
            return $this->render_safe_app_section( 'Etiquetas', array( $this, 'render_etiquetas_dashboard' ) );
        }

        $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
        if ( $lote_id > 0 ) {
            return $this->render_lote_detail_app( 'jefatura', $lote_id );
        }

        return $this->render_jefatura_dashboard();
    }

    private function render_login_app() {
        ob_start(); ?>
        <main class="mm-app-shell mm-app-login-shell">
            <section class="mm-login-card">
                <div class="mm-brand-mark">M</div>
                <h1>MegaMundo Logística</h1>
                <p>Acceso privado para bodega, precios y jefatura.</p>
                <a class="mm-primary-action" href="<?php echo esc_url( wp_login_url( home_url( '/?mm_logistica_app=panel' ) ) ); ?>">Iniciar sesión</a>
            </section>
        </main>
        <?php return ob_get_clean();
    }

    private function render_denied_app( $message ) {
        ob_start(); ?>
        <main class="mm-app-shell mm-app-login-shell">
            <section class="mm-login-card mm-denied-card">
                <div class="mm-brand-mark">!</div>
                <h1>Acceso restringido</h1>
                <p><?php echo esc_html( $message ); ?></p>
                <a class="mm-primary-action" href="<?php echo esc_url( home_url( '/?mm_logistica_app=panel' ) ); ?>">Ir a mi panel</a>
            </section>
        </main>
        <?php return ob_get_clean();
    }


    private function mm_dashboard_lotes_by_status() {
        return array(
            'draft'           => $this->lote_repo->find_by_status( 'draft' ),
            'mm_p_precios'    => $this->lote_repo->find_by_status( 'mm_p_precios' ),
            'mm_p_aprobacion' => $this->lote_repo->find_by_status( 'mm_p_aprobacion' ),
            'mm_cargado'      => $this->lote_repo->find_by_status( 'mm_cargado' ),
        );
    }

    private function mm_dashboard_label_stats() {
        $lotes = array();
        if ( method_exists( $this, 'get_lotes_listos_para_etiquetas' ) ) {
            $lotes = $this->get_lotes_listos_para_etiquetas();
        }

        $total_products = 0;
        $pending_products = 0;
        $printed_products = 0;

        foreach ( $lotes as $lote ) {
            $lote_id = intval( $lote->ID );
            $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
            $total_products += count( $items );

            $statuses = get_post_meta( $lote_id, '_mm_etiquetas_items_status', true );
            $statuses = is_array( $statuses ) ? $statuses : array();

            foreach ( $items as $item ) {
                $item_id = intval( $item->id );
                $status = isset( $statuses[ $item_id ]['status'] ) ? $statuses[ $item_id ]['status'] : 'pendiente';
                if ( 'pendiente' === $status || empty( $status ) ) {
                    $pending_products++;
                } else {
                    $printed_products++;
                }
            }
        }

        return array(
            'ready_lotes'      => count( $lotes ),
            'total_products'   => $total_products,
            'pending_products' => $pending_products,
            'printed_products' => $printed_products,
        );
    }

    private function mm_dashboard_new_products_count() {
        $statuses = array( 'draft', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
        $product_ids = array();

        foreach ( $statuses as $status ) {
            $lotes = $this->lote_repo->find_by_status( $status );
            foreach ( $lotes as $lote ) {
                $items = $this->item_repo ? $this->item_repo->get_items( intval( $lote->ID ) ) : array();
                foreach ( $items as $item ) {
                    $pid = intval( $item->producto_id );
                    if ( $pid > 0 && 'draft' === get_post_status( $pid ) ) {
                        $product_ids[ $pid ] = true;
                    }
                }
            }
        }

        return count( $product_ids );
    }

    private function mm_dashboard_recent_lotes_html( $lotes, $context ) {
        if ( empty( $lotes ) ) {
            return '<div class="mm-empty-state">No hay lotes en esta etapa.</div>';
        }

        $html = '<div class="mm-dashboard-mini-list">';
        foreach ( array_slice( $lotes, 0, 5 ) as $lote ) {
            $lote_id = intval( $lote->ID );
            $url = home_url( '/?mm_logistica_app=' . $context . '&lote_id=' . $lote_id );
            $html .= '<a class="mm-dashboard-mini-item" href="' . esc_url( $url ) . '">';
            $html .= '<span><strong>' . esc_html( get_the_title( $lote_id ) ) . '</strong><small>Lote #' . esc_html( $lote_id ) . ' · ' . esc_html( $this->status_label( $this->lote_repo->get_status( $lote_id ) ) ) . '</small></span>';
            $html .= '<b>Ver</b>';
            $html .= '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    private function render_dashboard_general() {
        $by_status = $this->mm_dashboard_lotes_by_status();
        $label_stats = $this->mm_dashboard_label_stats();
        $new_products = $this->mm_dashboard_new_products_count();

        $en_conteo = count( $by_status['draft'] );
        $en_precios = count( $by_status['mm_p_precios'] );
        $en_aprobacion = count( $by_status['mm_p_aprobacion'] );
        $cargados = count( $by_status['mm_cargado'] );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'dashboard' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Dashboard general</span>
                        <h1>Control completo de logística</h1>
                        <p>Resumen rápido del flujo: bodega, precios, jefatura y etiquetas. Usa esta pantalla para saber qué necesita atención.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <a class="mm-role-summary-card mm-dashboard-card-link" href="<?php echo esc_url( home_url( '/?mm_logistica_app=bodega' ) ); ?>"><small>En conteo</small><strong><?php echo esc_html( $en_conteo ); ?></strong><span>Bodega</span></a>
                    <a class="mm-role-summary-card mm-dashboard-card-link" href="<?php echo esc_url( home_url( '/?mm_logistica_app=precios' ) ); ?>"><small>Pendientes de precios</small><strong><?php echo esc_html( $en_precios ); ?></strong><span>Liquidación</span></a>
                    <a class="mm-role-summary-card mm-dashboard-card-link is-warning" href="<?php echo esc_url( home_url( '/?mm_logistica_app=jefatura' ) ); ?>"><small>Por aprobar</small><strong><?php echo esc_html( $en_aprobacion ); ?></strong><span>Jefatura</span></a>
                    <a class="mm-role-summary-card mm-dashboard-card-link is-success" href="<?php echo esc_url( home_url( '/?mm_logistica_app=etiquetas' ) ); ?>"><small>Listos para etiquetas</small><strong><?php echo esc_html( $label_stats['ready_lotes'] ); ?></strong><span>Impresión</span></a>
                </div>

                <section class="mm-pwa-safe-card">
                    <div>
                        <span class="mm-eyebrow">Acceso rápido móvil</span>
                        <h2>Usar como app en celular</h2>
                        <p>Para evitar errores de caché, esta versión usa acceso directo seguro sin Service Worker. En iPhone: Compartir → Agregar a pantalla de inicio. En Android: Menú ⋮ → Agregar a pantalla principal.</p>
                    </div>
                </section>

                <div class="mm-dashboard-alert-grid">
                    <div class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Alertas rápidas</h3><span>Prioridad operativa</span></div>
                        <ul class="mm-summary-list">
                            <li><span>Productos pendientes de imprimir</span><strong><?php echo esc_html( $label_stats['pending_products'] ); ?></strong></li>
                            <li><span>Productos impresos / reimpresos</span><strong><?php echo esc_html( $label_stats['printed_products'] ); ?></strong></li>
                            <li><span>Productos nuevos en borrador</span><strong><?php echo esc_html( $new_products ); ?></strong></li>
                            <li><span>Lotes cargados</span><strong><?php echo esc_html( $cargados ); ?></strong></li>
                        </ul>
                    </div>

                    <div class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Accesos rápidos</h3><span>Ir directo</span></div>
                        <div class="mm-quick-actions">
                            <?php if ( $this->permission_guard->can_access_bodega_panel() ) : ?><a href="<?php echo esc_url( home_url( '/?mm_logistica_app=bodega' ) ); ?>">📦 Bodega</a><?php endif; ?>
                            <?php if ( $this->permission_guard->can_access_precios_panel() ) : ?><a href="<?php echo esc_url( home_url( '/?mm_logistica_app=precios' ) ); ?>">🏷️ Precios</a><?php endif; ?>
                            <?php if ( $this->permission_guard->can_access_jefatura_panel() ) : ?><a href="<?php echo esc_url( home_url( '/?mm_logistica_app=jefatura' ) ); ?>">📊 Jefatura</a><?php endif; ?>
                            <?php if ( $this->permission_guard->can_access_precios_panel() || $this->permission_guard->can_access_jefatura_panel() ) : ?><a href="<?php echo esc_url( home_url( '/?mm_logistica_app=etiquetas' ) ); ?>">🖨️ Etiquetas</a><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mm-dashboard-columns">
                    <section class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Pendientes de precios</h3><span><?php echo esc_html( $en_precios ); ?> lote(s)</span></div>
                        <?php echo $this->mm_dashboard_recent_lotes_html( $by_status['mm_p_precios'], 'precios' ); ?>
                    </section>

                    <section class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Pendientes de jefatura</h3><span><?php echo esc_html( $en_aprobacion ); ?> lote(s)</span></div>
                        <?php echo $this->mm_dashboard_recent_lotes_html( $by_status['mm_p_aprobacion'], 'jefatura' ); ?>
                    </section>
                </div>

                <section class="mm-detail-card">
                    <div class="mm-detail-card-head"><h3>Estado de etiquetas</h3><span><?php echo esc_html( $label_stats['ready_lotes'] ); ?> lote(s) listos</span></div>
                    <p class="mm-help-text">Productos pendientes de impresión: <strong><?php echo esc_html( $label_stats['pending_products'] ); ?></strong>. Entra a Etiquetas para imprimir por lote, por producto o por cantidad personalizada.</p>
                    <a class="mm-mini-primary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=etiquetas' ) ); ?>">Ir a etiquetas</a>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }

    /**
     * Define la estructura del menú agrupada por flujo de trabajo. Cada grupo
     * declara una condición de visibilidad por rol: una persona solo ve los
     * grupos que le corresponden, en vez de las 16 opciones planas de antes.
     */
    private function sidebar_groups() {
        $guard      = $this->permission_guard;
        $is_bodega  = $guard->can_access_bodega_panel();
        $is_precios = $guard->can_access_precios_panel();
        $is_jefe    = $guard->can_access_jefatura_panel();
        $is_admin   = $guard->is_admin();

        $def = array(
            'inicio' => array(
                'label'   => 'Inicio',
                'visible' => true,
                'items'   => array(
                    'dashboard'      => array( 'label' => 'Dashboard', 'icon' => '🏠' ),
                    'notificaciones' => array( 'label' => 'Notificaciones', 'icon' => '🔔' ),
                ),
            ),
            'operacion' => array(
                'label'   => 'Operación',
                'visible' => $is_bodega,
                'items'   => array(
                    'bodega'           => array( 'label' => 'Bodega', 'icon' => '📦' ),
                    'exhibicion'       => array( 'label' => 'Exhibición', 'icon' => '🧺' ),
                    'productos-nuevos' => array( 'label' => 'Productos nuevos', 'icon' => '🆕' ),
                ),
            ),
            'compras' => array(
                'label'   => 'Compras',
                'visible' => $is_precios || $is_jefe || $is_admin,
                'items'   => array(
                    'pedidos'  => array( 'label' => 'Pedidos', 'icon' => '🛒' ),
                    'facturas' => array( 'label' => 'Facturas', 'icon' => '🧾' ),
                ),
            ),
            'precios' => array(
                'label'   => 'Precios',
                'visible' => $is_precios,
                'items'   => array(
                    'precios'   => array( 'label' => 'Precios', 'icon' => '🏷️' ),
                    'etiquetas' => array( 'label' => 'Etiquetas', 'icon' => '🖨️' ),
                ),
            ),
            'aprobacion' => array(
                'label'   => 'Aprobación',
                'visible' => $is_jefe,
                'items'   => array(
                    'jefatura'       => array( 'label' => 'Aprobaciones', 'icon' => '📊' ),
                    'sincronizacion' => array( 'label' => 'Sincronización', 'icon' => '🔁' ),
                ),
            ),
            'analisis' => array(
                'label'   => 'Análisis',
                'visible' => $is_jefe || $is_admin,
                'items'   => array(
                    'rotacion'  => array( 'label' => 'Rotación', 'icon' => '📉' ),
                    'reportes'  => array( 'label' => 'Reportes', 'icon' => '📈' ),
                    'historial' => array( 'label' => 'Historial', 'icon' => '🕓' ),
                    'mekano'    => array( 'label' => 'Mekano', 'icon' => '📤' ),
                ),
            ),
            'sistema' => array(
                'label'   => 'Sistema',
                'visible' => $is_admin,
                'items'   => array(
                    'usuarios' => array( 'label' => 'Usuarios', 'icon' => '👥' ),
                    'sistema'  => array( 'label' => 'Ajustes', 'icon' => '🛡️' ),
                ),
            ),
        );

        // Etiquetas: jefatura también las imprime aunque no tenga panel de precios.
        if ( $is_jefe && ! $is_precios ) {
            $def['precios']['visible'] = true;
            $def['precios']['items'] = array(
                'etiquetas' => array( 'label' => 'Etiquetas', 'icon' => '🖨️' ),
            );
            $def['precios']['label'] = 'Etiquetas';
        }

        return $def;
    }

    private function render_sidebar( $active ) {
        $groups = $this->sidebar_groups();

        ob_start(); ?>
        <aside class="mm-platform-sidebar">
            <div class="mm-platform-brand">
                <div class="mm-brand-mark">M</div>
                <div><strong>MegaMundo</strong><span>Logística</span></div>
            </div>
            <nav class="mm-platform-nav">
                <?php foreach ( $groups as $group ) : ?>
                    <?php if ( empty( $group['visible'] ) || empty( $group['items'] ) ) { continue; } ?>
                    <p class="mm-nav-group-label"><?php echo esc_html( $group['label'] ); ?></p>
                    <?php foreach ( $group['items'] as $key => $item ) : ?>
                        <a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( home_url( '/?mm_logistica_app=' . $key ) ); ?>">
                            <span class="mm-nav-icon" aria-hidden="true"><?php echo esc_html( $item['icon'] ); ?></span><?php echo esc_html( $item['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>
            <div class="mm-platform-user">
                <small>Usuario activo</small>
                <strong><?php echo esc_html( wp_get_current_user()->display_name ?: wp_get_current_user()->user_login ); ?></strong>
            </div>
        </aside>
        <?php return ob_get_clean();
    }

    private function status_label( $status ) {
        $labels = array(
            'draft'           => 'En conteo',
            'mm_p_precios'    => 'Pendiente de precios',
            'mm_p_aprobacion' => 'Pendiente de aprobación',
            'mm_cargado'      => 'Cargado',
            'publish'         => 'Publicado',
        );
        return $labels[ $status ] ?? ucfirst( (string) $status );
    }

    private function get_lote_counts() {
        $statuses = array( 'draft', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
        $counts = array();
        foreach ( $statuses as $status ) {
            $counts[ $status ] = count( $this->lote_repo->find_by_status( $status ) );
        }
        return $counts;
    }

    private function render_lote_card( $lote, $context = 'precios' ) {
        $lote_id  = intval( $lote->ID );
        $status   = $this->lote_repo->get_status( $lote_id );
        $items    = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
        $units    = 0;
        $cost     = 0;
        $sale     = 0;
        foreach ( $items as $item ) {
            $qty = intval( $item->cantidad_contada );
            $units += $qty;
            $cost += $qty * floatval( $item->costo_ia );
            $sale += $qty * floatval( $item->precio_propuesto );
        }
        $edit_url = admin_url( 'post.php?post=' . $lote_id . '&action=edit' );
        ob_start(); ?>
        <article class="mm-platform-lote-card">
            <div class="mm-lote-card-top">
                <span class="mm-badge-soft"><?php echo esc_html( $this->status_label( $status ) ); ?></span>
                <small>#<?php echo esc_html( $lote_id ); ?></small>
            </div>
            <h3><?php echo esc_html( get_the_title( $lote_id ) ); ?></h3>
            <div class="mm-lote-card-metrics">
                <span><strong><?php echo esc_html( count( $items ) ); ?></strong><small>SKUs</small></span>
                <span><strong><?php echo esc_html( $units ); ?></strong><small>Unidades</small></span>
                <?php if ( $this->permission_guard->can_view_finance() ) : ?>
                    <span><strong>$<?php echo esc_html( number_format( $sale, 0, ',', '.' ) ); ?></strong><small>Venta prop.</small></span>
                <?php endif; ?>
            </div>
            <div class="mm-lote-card-actions">
                <?php if ( 'bodega' === $context && 'draft' === $status ) : ?>
                    <a class="mm-mini-primary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=bodega&lote_id=' . $lote_id ) ); ?>">Escanear</a>
                <?php else : ?>
                    <a class="mm-mini-primary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=' . $context . '&lote_id=' . $lote_id ) ); ?>">Abrir lote</a>
                <?php endif; ?>
            </div>
        </article>
        <?php return ob_get_clean();
    }

    public function render_escaner( $atts, $standalone_panel = false ) {
        $atts = shortcode_atts( array(
            'lote_id' => 0,
        ), $atts, 'mm_escaner_bodega' );

        if ( ! is_user_logged_in() ) {
            return $this->render_login_app();
        }

        if ( ! $this->permission_guard->can_access_bodega_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para usar el panel de bodega.' );
        }

        $lote_id_forzado = intval( $atts['lote_id'] );
        $lote_forzado_valido = false;
        $titulo_lote_forzado = '';

        if ( $lote_id_forzado > 0 ) {
            $post_lote = $this->lote_repo->find( $lote_id_forzado );
            if ( $post_lote && $this->lote_repo->get_status( $lote_id_forzado ) === 'draft' ) {
                $lote_forzado_valido = true;
                $titulo_lote_forzado = get_the_title( $lote_id_forzado );
            }
        }

        $lotes_abiertos = $this->lote_repo->find_by_status( 'draft' );
        $invalid_forced = $lote_id_forzado > 0 && ! $lote_forzado_valido;

        ob_start(); ?>
        <main id="mm-contenedor-escaner" class="<?php echo $standalone_panel ? 'mm-platform-shell' : 'mm-app-shell'; ?>" data-mode="scanner">
            <?php if ( $standalone_panel ) : ?>
                <?php echo $this->render_sidebar( 'bodega' ); ?>
            <?php else : ?>
                <aside class="mm-platform-sidebar">
                    <div class="mm-platform-brand"><div class="mm-brand-mark">M</div><div><strong>MegaMundo</strong><span>Logística</span></div></div>
                    <nav class="mm-platform-nav"><a class="is-active" href="<?php echo esc_url( home_url( '/?mm_logistica_app=bodega' ) ); ?>"><span>📦</span>Bodega</a></nav>
                </aside>
            <?php endif; ?>

            <section class="mm-platform-main mm-bodega-main">
                <header class="mm-platform-header">
                    <div>
                        <span class="mm-eyebrow">Panel de Bodega</span>
                        <h1>Escáner de ingreso</h1>
                        <p>Escanea mercancía, crea productos nuevos, toma fotos y registra cantidades. Los costos y precios se completan en el panel de Precios.</p>
                    </div>
                    <a class="mm-header-link" href="<?php echo esc_url( home_url( '/?mm_logistica_app=panel' ) ); ?>">Mi panel</a>
                </header>

                <div id="mm-estado-conexion" class="mm-connection-alert" hidden></div>
                <div id="mm-pendientes-contador" class="mm-pending-alert" hidden></div>

                <?php if ( $invalid_forced ) : ?>
                    <div class="mm-status-card mm-status-error"><strong>⚠️ Lote no disponible</strong><span>No hay un lote activo seleccionado o el lote enviado no existe / no está en borrador.</span></div>
                <?php endif; ?>

                <?php
                $bodega_context_lote_id = $lote_forzado_valido ? $lote_id_forzado : ( isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0 );
                if ( $bodega_context_lote_id ) {
                    echo $this->render_bodega_pedido_context_panel( $bodega_context_lote_id );
                    echo $this->render_bodega_factura_ia_panel( $bodega_context_lote_id );
                    echo $this->render_bodega_factura_expected_panel( $bodega_context_lote_id );
                    echo $this->mm45_render_bodega_receipt_panel( $bodega_context_lote_id );
                }
                ?>

                <form id="mm-form-escaner" class="mm-scan-grid" <?php if ( $invalid_forced ) echo 'aria-disabled="true"'; ?>>
                    <section class="mm-scan-card mm-card-primary">
                        <div class="mm-card-head"><div><span class="mm-card-kicker">Paso 1</span><h2>Lote activo</h2></div><span class="mm-pill">Borrador</span></div>
                        <?php if ( $lote_forzado_valido ) : ?>
                            <input type="hidden" id="mm-lote-id" value="<?php echo esc_attr( $lote_id_forzado ); ?>" />
                            <div class="mm-selected-lote"><span>Lote #<?php echo esc_html( $lote_id_forzado ); ?></span><strong><?php echo esc_html( $titulo_lote_forzado ); ?></strong></div>
                        <?php else : ?>
                            <label class="mm-field-label" for="mm-lote-id">Selecciona el lote en conteo</label>
                            <select id="mm-lote-id" class="mm-input" required <?php disabled( $invalid_forced ); ?>>
                                <option value="">Selecciona un lote abierto</option>
                                <?php foreach ( $lotes_abiertos as $lote ) : ?>
                                    <option value="<?php echo esc_attr( $lote->ID ); ?>">Lote #<?php echo esc_html( $lote->ID ); ?> — <?php echo esc_html( $lote->post_title ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <div class="mm-sku-row"><div><label class="mm-field-label" for="mm-codigo-producto">Código / SKU</label><input type="text" id="mm-codigo-producto" class="mm-input mm-input-xl" required autofocus autocomplete="off" inputmode="text" placeholder="Escanea con pistola o escribe el SKU" <?php disabled( $invalid_forced ); ?> /></div><button type="button" id="mm-btn-scan-camera" class="mm-secondary-action" <?php disabled( $invalid_forced ); ?>>📷 Cámara</button></div>
                        <div class="mm-quantity-row"><div><label class="mm-field-label" for="mm-cantidad">Cantidad física</label><input type="number" id="mm-cantidad" class="mm-input" value="1" min="1" required <?php disabled( $invalid_forced ); ?> /></div><button type="button" id="mm-btn-producto-nuevo" class="mm-ghost-action" <?php disabled( $invalid_forced ); ?>>➕ Crear producto / tomar foto</button></div>
                        <button type="submit" id="mm-btn-submit" class="mm-primary-action" <?php disabled( $invalid_forced ); ?>>Guardar escaneo</button>
                        <div id="mm-mensaje-estado" class="mm-message" aria-live="polite"></div>
                    </section>
                    <section id="mm-caja-nombre-nuevo" class="mm-scan-card mm-new-product-card" hidden>
                        <div class="mm-card-head"><div><span class="mm-card-kicker">Bodega</span><h2>Crear producto nuevo</h2></div><span class="mm-pill mm-pill-warning">Requiere datos</span></div>
                        <p class="mm-help-text">Si el producto no existe, bodega puede crearlo con nombre, foto y cantidad. La información comercial se revisa después.</p>
                        <div class="mm-ai-toolbar">
                            <button type="button" id="mm-btn-analizar-ia" class="mm-secondary-action">✨ Analizar con IA</button>
                            <a class="mm-ghost-action mm-compact-link" href="<?php echo esc_url( admin_url( 'edit.php?post_type=lotes_ingreso&page=mm-logistica-ajustes' ) ); ?>" target="_blank" rel="noopener">⚙️ Configurar IA</a>
                        </div>
                        <label class="mm-field-label" for="mm-nombre-nuevo">Nombre visible del producto</label>
                        <input type="text" id="mm-nombre-nuevo" class="mm-input" placeholder="Ej: Parlante Bluetooth recargable" />
                        <div class="mm-ai-grid">
                            <div>
                                <label class="mm-field-label" for="mm-categoria-ia">Categoría sugerida</label>
                                <input type="text" id="mm-categoria-ia" class="mm-input" placeholder="Ej: Audio / Parlantes" />
                            </div>
                            <div>
                                <label class="mm-field-label" for="mm-palabras-clave-ia">Palabras clave</label>
                                <input type="text" id="mm-palabras-clave-ia" class="mm-input" placeholder="bluetooth, parlante, recargable" />
                            </div>
                        </div>
                        <label class="mm-field-label" for="mm-descripcion-ia">Descripción corta</label>
                        <textarea id="mm-descripcion-ia" class="mm-input mm-textarea" rows="4" placeholder="La IA puede sugerirte una descripción breve para el catálogo."></textarea>
                        <div class="mm-photo-actions">
                            <button type="button" id="mm-btn-tomar-foto" class="mm-photo-picker mm-photo-button">
                                <span class="mm-photo-icon">📸</span>
                                <strong>Tomar foto</strong>
                                <small>En celular abre la cámara. En computador puede abrir archivos.</small>
                            </button>
                            <button type="button" id="mm-btn-buscar-foto" class="mm-photo-picker mm-photo-button">
                                <span class="mm-photo-icon">🖼️</span>
                                <strong>Buscar archivo</strong>
                                <small>Elige una foto desde galería o archivos.</small>
                            </button>
                            <input type="file" id="mm-foto-nuevo" accept="application/pdf,image/jpeg,image/png,image/webp" capture="environment" hidden />
                            <input type="file" id="mm-foto-archivo" accept="application/pdf,image/jpeg,image/png,image/webp" hidden />
                        </div>
                        <div id="mm-foto-preview" class="mm-photo-preview" hidden></div>
                        <div id="mm-ia-estado" class="mm-ia-note">La información generada por IA siempre debe revisarse antes de guardar el producto. Si guardas sin imagen, quedará una alerta pendiente en “Sin imagen”.</div>
                    </section>
                    <section class="mm-scan-card mm-side-card">
                        <div class="mm-card-head"><div><span class="mm-card-kicker">Actividad</span><h2>Últimos movimientos</h2></div></div>
                        <ul id="mm-ultimos-escaneos" class="mm-activity-list"><li class="is-empty">Aún no hay movimientos en esta sesión.</li></ul>
                        <div class="mm-device-note"><strong>Nota para iPhone:</strong> si la lectura de código no abre, usa pistola lectora o escribe el SKU. La foto del producto nuevo sí se toma desde el campo de imagen.</div>
                    </section>
                </form>
            </section>
        </main>
        <div id="mm-camera-modal" class="mm-camera-modal" hidden><div class="mm-camera-box"><div class="mm-card-head"><h2>Escaneo con cámara</h2><button type="button" id="mm-close-camera" class="mm-icon-button">×</button></div><video id="mm-camera-video" playsinline muted></video><p id="mm-camera-help">Apunta la cámara al código. Si tu navegador no lo soporta, escribe el SKU manualmente.</p></div></div>
        <?php return ob_get_clean();
    }
}
