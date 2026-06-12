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

        if ( ! in_array( $app, array( 'dashboard', 'pedidos', 'bodega', 'exhibicion', 'precios', 'jefatura', 'etiquetas', 'notificaciones', 'reportes', 'reports', 'productos-nuevos', 'sincronizacion', 'usuarios', 'historial', 'sistema', 'facturas', 'mekano' , 'exhibicion'), true ) ) {
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



    private function get_user_read_notifications() {
        $read = get_user_meta( get_current_user_id(), '_mm_logistica_notificaciones_vistas', true );
        return is_array( $read ) ? $read : array();
    }

    private function is_notification_read( $notification_id ) {
        $read = $this->get_user_read_notifications();
        return in_array( (string) $notification_id, array_map( 'strval', $read ), true );
    }

    private function build_notification_id( $type, $lote_id, $extra = '' ) {
        return sanitize_key( $type . '_' . intval( $lote_id ) . '_' . sanitize_title( (string) $extra ) );
    }

    private function make_notification( $type, $lote_id, $title, $message, $url, $priority = 'normal', $icon = '🔔', $extra = '' ) {
        $id = $this->build_notification_id( $type, $lote_id, $extra );
        return array(
            'id'       => $id,
            'type'     => $type,
            'lote_id'  => intval( $lote_id ),
            'title'    => (string) $title,
            'message'  => (string) $message,
            'url'      => (string) $url,
            'priority' => (string) $priority,
            'icon'     => (string) $icon,
            'read'     => $this->is_notification_read( $id ),
        );
    }

    private function get_internal_notifications() {
        $notifications = array();

        $can_precios  = $this->permission_guard->can_access_precios_panel();
        $can_jefatura = $this->permission_guard->can_access_jefatura_panel();

        if ( $can_precios || $can_jefatura ) {
            foreach ( $this->lote_repo->find_by_status( 'mm_p_precios' ) as $lote ) {
                $lote_id = intval( $lote->ID );
                $notifications[] = $this->make_notification(
                    'pendiente_precios',
                    $lote_id,
                    'Lote pendiente de precios',
                    'El lote "' . get_the_title( $lote_id ) . '" necesita costos y precios.',
                    home_url( '/?mm_logistica_app=precios&lote_id=' . $lote_id ),
                    'warning',
                    '🏷️'
                );
            }
        }

        if ( $can_jefatura ) {
            foreach ( $this->lote_repo->find_by_status( 'mm_p_aprobacion' ) as $lote ) {
                $lote_id = intval( $lote->ID );
                $notifications[] = $this->make_notification(
                    'pendiente_aprobacion',
                    $lote_id,
                    'Lote pendiente de aprobación',
                    'El lote "' . get_the_title( $lote_id ) . '" ya fue liquidado y espera aprobación.',
                    home_url( '/?mm_logistica_app=jefatura&lote_id=' . $lote_id ),
                    'urgent',
                    '📊'
                );
            }
        }

        if ( $can_precios || $can_jefatura ) {
            $lotes_etiquetas = array();
            if ( method_exists( $this, 'get_lotes_listos_para_etiquetas' ) ) {
                $lotes_etiquetas = $this->get_lotes_listos_para_etiquetas();
            }

            foreach ( $lotes_etiquetas as $lote ) {
                $lote_id = intval( $lote->ID );
                $notifications[] = $this->make_notification(
                    'listo_etiquetas',
                    $lote_id,
                    'Lote listo para etiquetas',
                    'El lote "' . get_the_title( $lote_id ) . '" ya está cargado y puede imprimirse.',
                    home_url( '/?mm_logistica_app=etiquetas' ),
                    'success',
                    '🖨️'
                );

                $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
                $statuses = get_post_meta( $lote_id, '_mm_etiquetas_items_status', true );
                $statuses = is_array( $statuses ) ? $statuses : array();
                $pending = 0;

                foreach ( $items as $item ) {
                    $item_id = intval( $item->id );
                    $status = isset( $statuses[ $item_id ]['status'] ) ? $statuses[ $item_id ]['status'] : 'pendiente';
                    if ( empty( $status ) || 'pendiente' === $status ) {
                        $pending++;
                    }
                }

                if ( $pending > 0 ) {
                    $notifications[] = $this->make_notification(
                        'etiquetas_pendientes',
                        $lote_id,
                        'Productos pendientes por imprimir',
                        'El lote "' . get_the_title( $lote_id ) . '" tiene ' . $pending . ' producto(s) pendientes de impresión.',
                        home_url( '/?mm_logistica_app=etiquetas' ),
                        'warning',
                        '⚠️',
                        'pendientes_' . $pending
                    );
                }
            }
        }

        usort( $notifications, function( $a, $b ) {
            if ( $a['read'] !== $b['read'] ) {
                return $a['read'] ? 1 : -1;
            }
            $order = array( 'urgent' => 0, 'warning' => 1, 'success' => 2, 'normal' => 3 );
            return ( $order[ $a['priority'] ] ?? 9 ) <=> ( $order[ $b['priority'] ] ?? 9 );
        } );

        return $notifications;
    }

    private function count_unread_notifications() {
        $count = 0;
        foreach ( $this->get_internal_notifications() as $notification ) {
            if ( empty( $notification['read'] ) ) {
                $count++;
            }
        }
        return $count;
    }

    public function ajax_marcar_notificacion_vista_app() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Debes iniciar sesión.' ), 401 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_app_notificaciones' ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
        }

        $notification_id = isset( $_POST['notification_id'] ) ? sanitize_key( wp_unslash( $_POST['notification_id'] ) ) : '';
        $mark_all = isset( $_POST['mark_all'] ) ? absint( $_POST['mark_all'] ) : 0;

        $read = $this->get_user_read_notifications();

        if ( $mark_all ) {
            foreach ( $this->get_internal_notifications() as $notification ) {
                $read[] = (string) $notification['id'];
            }
        } elseif ( $notification_id ) {
            $read[] = (string) $notification_id;
        } else {
            wp_send_json_error( array( 'message' => 'No se recibió la notificación.' ), 400 );
        }

        $read = array_values( array_unique( array_map( 'strval', $read ) ) );
        update_user_meta( get_current_user_id(), '_mm_logistica_notificaciones_vistas', $read );

        wp_send_json_success( array(
            'message' => $mark_all ? 'Todas las notificaciones fueron marcadas como vistas.' : 'Notificación marcada como vista.',
        ) );
    }

    private function render_notificaciones_dashboard() {
        $notifications = $this->get_internal_notifications();
        $unread = 0;
        foreach ( $notifications as $notification ) {
            if ( empty( $notification['read'] ) ) {
                $unread++;
            }
        }
        $nonce = wp_create_nonce( 'mm_app_notificaciones' );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'notificaciones' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Centro de notificaciones</span>
                        <h1>Alertas internas</h1>
                        <p>Revisa las acciones pendientes del flujo: precios, jefatura, etiquetas e impresión.</p>
                    </div>
                    <div class="mm-notification-header-actions">
                        <strong><?php echo esc_html( $unread ); ?> sin leer</strong>
                        <?php if ( $unread > 0 ) : ?>
                            <button type="button" class="mm-secondary-action mm-btn-notification-read" data-mark-all="1" data-nonce="<?php echo esc_attr( $nonce ); ?>">Marcar todas como vistas</button>
                        <?php endif; ?>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Total alertas</small><strong><?php echo esc_html( count( $notifications ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Sin leer</small><strong><?php echo esc_html( $unread ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Vistas</small><strong><?php echo esc_html( count( $notifications ) - $unread ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Estado</small><strong>Activo</strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Notificaciones operativas</h2>
                        <span>Las alertas se generan según el estado actual de los lotes.</span>
                    </div>

                    <div class="mm-notification-list">
                        <?php if ( empty( $notifications ) ) : ?>
                            <div class="mm-empty-state">No hay notificaciones por ahora.</div>
                        <?php endif; ?>

                        <?php foreach ( $notifications as $notification ) : ?>
                            <article class="mm-notification-card <?php echo ! empty( $notification['read'] ) ? 'is-read' : 'is-unread'; ?> mm-notification-<?php echo esc_attr( $notification['priority'] ); ?>">
                                <div class="mm-notification-icon"><?php echo esc_html( $notification['icon'] ); ?></div>
                                <div class="mm-notification-body">
                                    <div class="mm-notification-title-row">
                                        <h3><?php echo esc_html( $notification['title'] ); ?></h3>
                                        <span><?php echo ! empty( $notification['read'] ) ? 'Vista' : 'Nueva'; ?></span>
                                    </div>
                                    <p><?php echo esc_html( $notification['message'] ); ?></p>
                                    <div class="mm-notification-actions">
                                        <a class="mm-mini-primary" href="<?php echo esc_url( $notification['url'] ); ?>">Abrir</a>
                                        <?php if ( empty( $notification['read'] ) ) : ?>
                                            <button type="button" class="mm-mini-secondary mm-btn-notification-read" data-notification-id="<?php echo esc_attr( $notification['id'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Marcar vista</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }




    private function get_report_date_filters() {
        $today = current_time( 'Y-m-d' );
        $from = isset( $_GET['fecha_desde'] ) ? sanitize_text_field( wp_unslash( $_GET['fecha_desde'] ) ) : $today;
        $to   = isset( $_GET['fecha_hasta'] ) ? sanitize_text_field( wp_unslash( $_GET['fecha_hasta'] ) ) : $today;

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
            $from = $today;
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
            $to = $today;
        }

        return array(
            'from' => $from,
            'to'   => $to,
        );
    }

    private function report_lote_in_date_range( $lote_id, $filters ) {
        $timestamp = get_post_time( 'U', true, $lote_id );
        if ( ! $timestamp ) {
            return false;
        }

        $from_ts = strtotime( $filters['from'] . ' 00:00:00' );
        $to_ts   = strtotime( $filters['to'] . ' 23:59:59' );

        return $timestamp >= $from_ts && $timestamp <= $to_ts;
    }

    private function report_date_url( $from, $to ) {
        return add_query_arg(
            array(
                'mm_logistica_app' => 'reportes',
                'fecha_desde'      => $from,
                'fecha_hasta'      => $to,
            ),
            home_url( '/' )
        );
    }


    private function get_all_lotes_for_reports( $filters = null ) {
        $filters = $filters ?: $this->get_report_date_filters();
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( isset( $lote->ID ) && $this->report_lote_in_date_range( intval( $lote->ID ), $filters ) ) {
                    $lotes[ intval( $lote->ID ) ] = $lote;
                }
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_report_metrics( $filters = null ) {
        $filters = $filters ?: $this->get_report_date_filters();
        $lotes = $this->get_all_lotes_for_reports( $filters );
        $metrics = array(
            'lotes_total'               => count( $lotes ),
            'lotes_cargados'            => 0,
            'unidades_total'            => 0,
            'skus_total'                => 0,
            'costo_total'               => 0,
            'venta_total'               => 0,
            'margen_total'              => 0,
            'productos_nuevos_borrador' => 0,
            'productos_impresos'        => 0,
            'productos_pendientes'      => 0,
            'reimpresiones'             => 0,
        );

        $draft_products = array();

        foreach ( $lotes as $lote ) {
            $lote_id = intval( $lote->ID );
            $status = $this->lote_repo->get_status( $lote_id );

            if ( 'mm_cargado' === $status || $this->lote_repo->is_synchronized( $lote_id ) ) {
                $metrics['lotes_cargados']++;
            }

            $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
            $metrics['skus_total'] += count( $items );

            $label_statuses = get_post_meta( $lote_id, '_mm_etiquetas_items_status', true );
            $label_statuses = is_array( $label_statuses ) ? $label_statuses : array();

            foreach ( $items as $item ) {
                $qty  = intval( $item->cantidad_contada );
                $cost = floatval( $item->costo_ia );
                $sale = floatval( $item->precio_propuesto );

                $metrics['unidades_total'] += $qty;
                $metrics['costo_total']    += $cost * $qty;
                $metrics['venta_total']    += $sale * $qty;
                $metrics['margen_total']   += ( $sale - $cost ) * $qty;

                $pid = intval( $item->producto_id );
                if ( $pid > 0 && 'draft' === get_post_status( $pid ) ) {
                    $draft_products[ $pid ] = true;
                }

                $item_id = intval( $item->id );
                $label_status = isset( $label_statuses[ $item_id ] ) ? $label_statuses[ $item_id ] : array();
                $state = isset( $label_status['status'] ) ? $label_status['status'] : 'pendiente';

                if ( empty( $state ) || 'pendiente' === $state ) {
                    $metrics['productos_pendientes']++;
                } else {
                    $metrics['productos_impresos']++;
                }

                if ( isset( $label_status['print_count'] ) && intval( $label_status['print_count'] ) > 1 ) {
                    $metrics['reimpresiones'] += intval( $label_status['print_count'] ) - 1;
                }
            }
        }

        $metrics['productos_nuevos_borrador'] = count( $draft_products );
        return $metrics;
    }

    private function report_money( $value ) {
        return '$' . number_format( floatval( $value ), 0, ',', '.' );
    }

    private function report_export_url( $type ) {
        $filters = $this->get_report_date_filters();
        return wp_nonce_url(
            add_query_arg(
                array(
                    'mm_export_report' => sanitize_key( $type ),
                    'fecha_desde'      => $filters['from'],
                    'fecha_hasta'      => $filters['to'],
                ),
                home_url( '/' )
            ),
            'mm_export_report_' . sanitize_key( $type )
        );
    }

    public function export_report_csv() {
        if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
            wp_die( 'No tienes permisos para exportar reportes.' );
        }

        $type = isset( $_GET['mm_export_report'] ) ? sanitize_key( wp_unslash( $_GET['mm_export_report'] ) ) : '';

        if ( ! $type || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mm_export_report_' . $type ) ) {
            wp_die( 'Enlace de exportación no autorizado o vencido.' );
        }

        $filters = $this->get_report_date_filters();
        $filename = 'megamundo-reporte-' . $type . '-' . $filters['from'] . '-a-' . $filters['to'] . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        if ( 'lotes' === $type ) {
            fputcsv( $out, array( 'ID Lote', 'Nombre', 'Estado', 'SKUs', 'Unidades', 'Costo total', 'Venta total', 'Margen total', 'Fecha' ) );
            foreach ( $this->get_all_lotes_for_reports( $filters ) as $lote ) {
                $lote_id = intval( $lote->ID );
                $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
                $units = 0;
                $cost = 0;
                $sale = 0;

                foreach ( $items as $item ) {
                    $qty = intval( $item->cantidad_contada );
                    $units += $qty;
                    $cost  += floatval( $item->costo_ia ) * $qty;
                    $sale  += floatval( $item->precio_propuesto ) * $qty;
                }

                fputcsv( $out, array(
                    $lote_id,
                    get_the_title( $lote_id ),
                    $this->status_label( $this->lote_repo->get_status( $lote_id ) ),
                    count( $items ),
                    $units,
                    $cost,
                    $sale,
                    $sale - $cost,
                    get_the_date( 'Y-m-d H:i:s', $lote_id ),
                ) );
            }
        } elseif ( 'productos' === $type ) {
            fputcsv( $out, array( 'ID Lote', 'Lote', 'SKU', 'Producto', 'Cantidad', 'Costo unitario', 'Precio final', 'Margen unitario', 'Estado lote' ) );
            foreach ( $this->get_all_lotes_for_reports( $filters ) as $lote ) {
                $lote_id = intval( $lote->ID );
                $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

                foreach ( $items as $item ) {
                    $product_name = intval( $item->producto_id ) ? get_the_title( intval( $item->producto_id ) ) : 'Producto sin nombre';
                    $cost = floatval( $item->costo_ia );
                    $sale = floatval( $item->precio_propuesto );

                    fputcsv( $out, array(
                        $lote_id,
                        get_the_title( $lote_id ),
                        $item->sku,
                        $product_name,
                        intval( $item->cantidad_contada ),
                        $cost,
                        $sale,
                        $sale - $cost,
                        $this->status_label( $this->lote_repo->get_status( $lote_id ) ),
                    ) );
                }
            }
        } elseif ( 'etiquetas' === $type ) {
            fputcsv( $out, array( 'ID Lote', 'Lote', 'SKU', 'Producto', 'Cantidad', 'Estado impresión', 'Veces impresas' ) );
            foreach ( $this->get_all_lotes_for_reports( $filters ) as $lote ) {
                $lote_id = intval( $lote->ID );
                $statuses = get_post_meta( $lote_id, '_mm_etiquetas_items_status', true );
                $statuses = is_array( $statuses ) ? $statuses : array();
                $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

                foreach ( $items as $item ) {
                    $item_id = intval( $item->id );
                    $state = isset( $statuses[ $item_id ]['status'] ) ? $statuses[ $item_id ]['status'] : 'pendiente';
                    $print_count = isset( $statuses[ $item_id ]['print_count'] ) ? intval( $statuses[ $item_id ]['print_count'] ) : 0;
                    $product_name = intval( $item->producto_id ) ? get_the_title( intval( $item->producto_id ) ) : 'Producto sin nombre';

                    fputcsv( $out, array(
                        $lote_id,
                        get_the_title( $lote_id ),
                        $item->sku,
                        $product_name,
                        intval( $item->cantidad_contada ),
                        $state,
                        $print_count,
                    ) );
                }
            }
        } else {
            fputcsv( $out, array( 'Reporte no válido' ) );
        }

        fclose( $out );
        exit;
    }

    private function render_reportes_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para ver reportes.' );
        }

        $filters = $this->get_report_date_filters();
        $metrics = $this->get_report_metrics( $filters );
        $today = current_time( 'Y-m-d' );
        $yesterday = date( 'Y-m-d', strtotime( $today . ' -1 day' ) );
        $last_7 = date( 'Y-m-d', strtotime( $today . ' -6 days' ) );
        $month_start = date( 'Y-m-01', strtotime( $today ) );
        $margin_pct = $metrics['venta_total'] > 0 ? ( $metrics['margen_total'] / $metrics['venta_total'] ) * 100 : 0;

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'reportes' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Reportes</span>
                        <h1>Medición y exportación</h1>
                        <p>Consulta el rendimiento operativo de lotes, productos, costos, precios y etiquetas por día o rango de fechas.</p>
                    </div>
                </header>

                <section class="mm-report-filter-card">
                    <div>
                        <h2>Filtro por fecha</h2>
                        <p>Mostrando datos desde <strong><?php echo esc_html( $filters['from'] ); ?></strong> hasta <strong><?php echo esc_html( $filters['to'] ); ?></strong>.</p>
                    </div>
                    <div class="mm-report-quick-dates">
                        <a href="<?php echo esc_url( $this->report_date_url( $today, $today ) ); ?>">Hoy</a>
                        <a href="<?php echo esc_url( $this->report_date_url( $yesterday, $yesterday ) ); ?>">Ayer</a>
                        <a href="<?php echo esc_url( $this->report_date_url( $last_7, $today ) ); ?>">Últimos 7 días</a>
                        <a href="<?php echo esc_url( $this->report_date_url( $month_start, $today ) ); ?>">Este mes</a>
                    </div>
                    <form class="mm-report-date-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <input type="hidden" name="mm_logistica_app" value="reportes">
                        <label>Desde <input type="date" name="fecha_desde" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
                        <label>Hasta <input type="date" name="fecha_hasta" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
                        <button type="submit" class="mm-mini-primary">Aplicar filtro</button>
                    </form>
                </section>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Lotes totales</small><strong><?php echo esc_html( $metrics['lotes_total'] ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Lotes cargados</small><strong><?php echo esc_html( $metrics['lotes_cargados'] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Unidades ingresadas</small><strong><?php echo esc_html( $metrics['unidades_total'] ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>SKUs registrados</small><strong><?php echo esc_html( $metrics['skus_total'] ); ?></strong></div>
                </div>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Costo total</small><strong><?php echo esc_html( $this->report_money( $metrics['costo_total'] ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Venta total final</small><strong><?php echo esc_html( $this->report_money( $metrics['venta_total'] ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Margen estimado</small><strong><?php echo esc_html( $this->report_money( $metrics['margen_total'] ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>% Margen</small><strong><?php echo esc_html( number_format( $margin_pct, 1, ',', '.' ) ); ?>%</strong></div>
                </div>

                <div class="mm-dashboard-alert-grid">
                    <div class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Etiquetas</h3><span>Control de impresión</span></div>
                        <ul class="mm-summary-list">
                            <li><span>Productos impresos/reimpresos</span><strong><?php echo esc_html( $metrics['productos_impresos'] ); ?></strong></li>
                            <li><span>Productos pendientes por imprimir</span><strong><?php echo esc_html( $metrics['productos_pendientes'] ); ?></strong></li>
                            <li><span>Reimpresiones registradas</span><strong><?php echo esc_html( $metrics['reimpresiones'] ); ?></strong></li>
                        </ul>
                    </div>

                    <div class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Productos nuevos</h3><span>Revisión</span></div>
                        <ul class="mm-summary-list">
                            <li><span>Productos nuevos en borrador</span><strong><?php echo esc_html( $metrics['productos_nuevos_borrador'] ); ?></strong></li>
                        </ul>
                    </div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Exportaciones CSV</h2>
                        <span>Descarga reportes filtrados por la fecha seleccionada.</span>
                    </div>

                    <div class="mm-report-export-grid">
                        <a class="mm-report-export-card" href="<?php echo esc_url( $this->report_export_url( 'lotes' ) ); ?>">
                            <strong>Reporte de lotes</strong>
                            <small>Estados, unidades, costos, ventas y margen por lote.</small>
                            <span>Exportar CSV</span>
                        </a>
                        <a class="mm-report-export-card" href="<?php echo esc_url( $this->report_export_url( 'productos' ) ); ?>">
                            <strong>Reporte de productos</strong>
                            <small>SKU, producto, cantidad, costo, precio final y margen.</small>
                            <span>Exportar CSV</span>
                        </a>
                        <a class="mm-report-export-card" href="<?php echo esc_url( $this->report_export_url( 'etiquetas' ) ); ?>">
                            <strong>Reporte de etiquetas</strong>
                            <small>Estado de impresión, productos pendientes y reimpresiones.</small>
                            <span>Exportar CSV</span>
                        </a>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }



    private function get_productos_nuevos_para_revision() {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => array( 'draft', 'pending', 'publish' ),
            'posts_per_page' => 80,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_mm_producto_nuevo_lote',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_mm_ia_nombre_sugerido',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_mm_requiere_revision',
                    'value'   => '1',
                    'compare' => '=',
                ),
            ),
        );

        return get_posts( $args );
    }

    private function get_producto_revision_data( $product_id ) {
        $product_id = intval( $product_id );

        return array(
            'nombre_ia'       => get_post_meta( $product_id, '_mm_ia_nombre_sugerido', true ),
            'categoria_ia'    => get_post_meta( $product_id, '_mm_ia_categoria_sugerida', true ),
            'descripcion_ia'  => get_post_meta( $product_id, '_mm_ia_descripcion_corta', true ),
            'palabras_ia'     => get_post_meta( $product_id, '_mm_ia_palabras_clave', true ),
            'marca_ia'        => get_post_meta( $product_id, '_mm_ia_marca_detectada', true ),
            'observaciones'   => get_post_meta( $product_id, '_mm_ia_observaciones', true ),
            'confianza'       => get_post_meta( $product_id, '_mm_ia_confianza', true ),
            'lote_id'         => absint( get_post_meta( $product_id, '_mm_producto_nuevo_lote', true ) ),
            'revision_estado' => get_post_meta( $product_id, '_mm_revision_estado', true ) ?: 'pendiente',
        );
    }

    public function ajax_guardar_revision_producto_nuevo() {
        if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para revisar productos nuevos.' ), 403 );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $nonce      = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $product_id || ! wp_verify_nonce( $nonce, 'mm_producto_nuevo_' . $product_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        if ( 'product' !== get_post_type( $product_id ) ) {
            wp_send_json_error( array( 'message' => 'Producto inválido.' ), 404 );
        }

        $nombre      = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
        $descripcion = isset( $_POST['descripcion'] ) ? sanitize_textarea_field( wp_unslash( $_POST['descripcion'] ) ) : '';
        $categoria   = isset( $_POST['categoria'] ) ? sanitize_text_field( wp_unslash( $_POST['categoria'] ) ) : '';
        $palabras    = isset( $_POST['palabras'] ) ? sanitize_text_field( wp_unslash( $_POST['palabras'] ) ) : '';

        if ( empty( $nombre ) ) {
            wp_send_json_error( array( 'message' => 'El nombre del producto es obligatorio.' ), 400 );
        }

        wp_update_post( array(
            'ID'           => $product_id,
            'post_title'   => $nombre,
            'post_excerpt' => $descripcion,
        ) );

        update_post_meta( $product_id, '_mm_revision_estado', 'revisado' );
        update_post_meta( $product_id, '_mm_revision_at', current_time( 'mysql' ) );
        update_post_meta( $product_id, '_mm_revision_by', get_current_user_id() );
        update_post_meta( $product_id, '_mm_categoria_revisada', $categoria );
        update_post_meta( $product_id, '_mm_palabras_clave_revisadas', $palabras );
        update_post_meta( $product_id, '_mm_requiere_revision', '0' );

        
            if ( ! $this->mm_producto_tiene_imagen_upload() ) {
                $mm_codigo_tmp = isset( $_POST['codigo'] ) ? sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) : ( isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '' );
                $mm_producto_tmp = isset( $_POST['producto'] ) ? sanitize_text_field( wp_unslash( $_POST['producto'] ) ) : ( isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '' );
                $this->mm_registrar_producto_sin_imagen( $mm_codigo_tmp, $mm_producto_tmp, 'bodega' );
            }

wp_send_json_success( array(
            'message' => 'Producto revisado y guardado correctamente.',
        ) );
    }

    private function render_productos_nuevos_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para revisar productos nuevos.' );
        }

        $products = $this->get_productos_nuevos_para_revision();
        $pendientes = 0;
        $revisados = 0;

        foreach ( $products as $product ) {
            $estado = get_post_meta( $product->ID, '_mm_revision_estado', true ) ?: 'pendiente';
            if ( 'revisado' === $estado ) {
                $revisados++;
            } else {
                $pendientes++;
            }
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'productos-nuevos' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Productos nuevos + IA</span>
                        <h1>Revisión de productos nuevos</h1>
                        <p>Revisa los productos creados desde bodega, corrige nombre, descripción, categoría y palabras clave antes de dejarlos listos.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Total productos</small><strong><?php echo esc_html( count( $products ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Pendientes</small><strong><?php echo esc_html( $pendientes ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Revisados</small><strong><?php echo esc_html( $revisados ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Fuente</small><strong>IA + Bodega</strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Productos por revisar</h2>
                        <span>La IA sugiere. El personal confirma.</span>
                    </div>

                    <div class="mm-new-products-grid">
                        <?php if ( empty( $products ) ) : ?>
                            <div class="mm-empty-state">No hay productos nuevos pendientes de revisión.</div>
                        <?php endif; ?>

                        <?php foreach ( $products as $product ) :
                            $data = $this->get_producto_revision_data( $product->ID );
                            $thumb = get_the_post_thumbnail_url( $product->ID, 'medium' );
                            $nonce = wp_create_nonce( 'mm_producto_nuevo_' . $product->ID );
                            $edit_url = admin_url( 'post.php?post=' . $product->ID . '&action=edit' );
                        ?>
                            <article class="mm-new-product-review-card" data-product-id="<?php echo esc_attr( $product->ID ); ?>">
                                <div class="mm-new-product-image">
                                    <?php if ( $thumb ) : ?>
                                        <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( get_the_title( $product->ID ) ); ?>">
                                    <?php else : ?>
                                        <span>Sin foto</span>
                                    <?php endif; ?>
                                </div>

                                <form class="mm-product-review-form">
                                    <input type="hidden" name="product_id" value="<?php echo esc_attr( $product->ID ); ?>">
                                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                                    <div class="mm-product-review-head">
                                        <span class="mm-badge-soft <?php echo 'revisado' === $data['revision_estado'] ? '' : 'mm-badge-warn'; ?>">
                                            <?php echo esc_html( ucfirst( $data['revision_estado'] ) ); ?>
                                        </span>
                                        <?php if ( $data['lote_id'] ) : ?>
                                            <small>Lote #<?php echo esc_html( $data['lote_id'] ); ?></small>
                                        <?php endif; ?>
                                    </div>

                                    <label>Nombre del producto
                                        <input class="mm-input" type="text" name="nombre" value="<?php echo esc_attr( get_the_title( $product->ID ) ?: $data['nombre_ia'] ); ?>" placeholder="Nombre comercial">
                                    </label>

                                    <label>Categoría sugerida / revisada
                                        <input class="mm-input" type="text" name="categoria" value="<?php echo esc_attr( $data['categoria_ia'] ); ?>" placeholder="Ej: Hogar / Cocina">
                                    </label>

                                    <label>Descripción corta
                                        <textarea class="mm-input mm-textarea" name="descripcion" rows="4" placeholder="Descripción para WooCommerce"><?php echo esc_textarea( $product->post_excerpt ?: $data['descripcion_ia'] ); ?></textarea>
                                    </label>

                                    <label>Palabras clave
                                        <input class="mm-input" type="text" name="palabras" value="<?php echo esc_attr( $data['palabras_ia'] ); ?>" placeholder="palabra1, palabra2, palabra3">
                                    </label>

                                    <div class="mm-ai-observations">
                                        <strong>Lectura IA</strong>
                                        <p><b>Marca:</b> <?php echo esc_html( $data['marca_ia'] ?: 'No detectada' ); ?></p>
                                        <p><b>Observaciones:</b> <?php echo esc_html( $data['observaciones'] ?: 'Sin observaciones.' ); ?></p>
                                        <?php if ( $data['confianza'] ) : ?><p><b>Confianza:</b> <?php echo esc_html( $data['confianza'] ); ?></p><?php endif; ?>
                                    </div>

                                    <div class="mm-product-review-actions">
                                        <button type="submit" class="mm-mini-primary">Guardar revisión</button>
                                        <a class="mm-mini-secondary" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener">Abrir en WooCommerce</a>
                                    </div>
                                    <div class="mm-product-review-msg" hidden></div>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }



    private function get_mm_role_label( $role ) {
        $labels = array(
            'administrator' => 'Administrador / Jefatura',
            'mm_contador'   => 'Bodega',
            'mm_ingresador' => 'Precios / Etiquetas',
        );

        return $labels[ $role ] ?? $role;
    }

    private function get_mm_role_permissions_matrix() {
        return array(
            'mm_contador' => array(
                'label' => 'Bodega',
                'icon'  => '📦',
                'can'   => array(
                    'Escanear productos',
                    'Crear lotes en conteo',
                    'Tomar foto de productos nuevos',
                    'Registrar cantidades',
                ),
                'cannot' => array(
                    'Ver costos y márgenes',
                    'Autorizar precios',
                    'Cargar a WooCommerce',
                    'Ver reportes financieros',
                ),
            ),
            'mm_ingresador' => array(
                'label' => 'Precios / Etiquetas',
                'icon'  => '🏷️',
                'can'   => array(
                    'Asignar costos',
                    'Asignar precios propuestos',
                    'Revisar productos nuevos con IA',
                    'Imprimir etiquetas',
                    'Ver reportes operativos',
                ),
                'cannot' => array(
                    'Autorizar precio final',
                    'Cargar lote al sistema',
                    'Reintentar sincronización',
                ),
            ),
            'administrator' => array(
                'label' => 'Jefatura / Administrador',
                'icon'  => '📊',
                'can'   => array(
                    'Ver todo el sistema',
                    'Autorizar precio final',
                    'Cargar a WooCommerce',
                    'Reintentar sincronización',
                    'Ver reportes financieros',
                    'Administrar usuarios desde WordPress',
                ),
                'cannot' => array(),
            ),
        );
    }

    private function get_mm_logistica_users() {
        $roles = array( 'administrator', 'mm_contador', 'mm_ingresador' );
        $users = array();

        foreach ( $roles as $role ) {
            $role_users = get_users( array(
                'role'    => $role,
                'orderby' => 'display_name',
                'order'   => 'ASC',
            ) );

            foreach ( $role_users as $user ) {
                $users[ $user->ID ] = $user;
            }
        }

        uasort( $users, function( $a, $b ) {
            return strcasecmp( $a->display_name, $b->display_name );
        } );

        return array_values( $users );
    }

    private function render_usuarios_dashboard() {
        if ( ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'Solo jefatura puede ver el panel de usuarios y permisos.' );
        }

        $users = $this->get_mm_logistica_users();
        $matrix = $this->get_mm_role_permissions_matrix();

        $counts = array(
            'administrator' => 0,
            'mm_contador'   => 0,
            'mm_ingresador' => 0,
        );

        foreach ( $users as $user ) {
            foreach ( $counts as $role => $count ) {
                if ( in_array( $role, (array) $user->roles, true ) ) {
                    $counts[ $role ]++;
                }
            }
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'usuarios' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Usuarios y permisos</span>
                        <h1>Control de acceso</h1>
                        <p>Consulta quién tiene acceso a cada área y qué puede hacer cada rol dentro de la plataforma.</p>
                    </div>
                    <div class="mm-detail-actions">
                        <a class="mm-secondary-action" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" target="_blank" rel="noopener">Administrar en WordPress</a>
                        <a class="mm-secondary-action" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>" target="_blank" rel="noopener">Crear usuario</a>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Total usuarios</small><strong><?php echo esc_html( count( $users ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Bodega</small><strong><?php echo esc_html( $counts['mm_contador'] ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Precios</small><strong><?php echo esc_html( $counts['mm_ingresador'] ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Jefatura</small><strong><?php echo esc_html( $counts['administrator'] ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Matriz de permisos</h2>
                        <span>Reglas principales del flujo operativo.</span>
                    </div>

                    <div class="mm-permissions-grid">
                        <?php foreach ( $matrix as $role => $info ) : ?>
                            <article class="mm-permission-card">
                                <div class="mm-permission-head">
                                    <span><?php echo esc_html( $info['icon'] ); ?></span>
                                    <div>
                                        <h3><?php echo esc_html( $info['label'] ); ?></h3>
                                        <small><?php echo esc_html( $role ); ?></small>
                                    </div>
                                </div>

                                <div class="mm-permission-list is-can">
                                    <strong>Puede hacer</strong>
                                    <ul>
                                        <?php foreach ( $info['can'] as $item ) : ?>
                                            <li>✅ <?php echo esc_html( $item ); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>

                                <?php if ( ! empty( $info['cannot'] ) ) : ?>
                                    <div class="mm-permission-list is-cannot">
                                        <strong>No debe hacer</strong>
                                        <ul>
                                            <?php foreach ( $info['cannot'] as $item ) : ?>
                                                <li>⛔ <?php echo esc_html( $item ); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Usuarios con acceso</h2>
                        <span>Usuarios detectados con rol de administrador, bodega o precios.</span>
                    </div>

                    <div class="mm-users-table-wrap">
                        <table class="mm-users-table">
                            <thead>
                                <tr>
                                    <th>Usuario</th>
                                    <th>Email</th>
                                    <th>Roles</th>
                                    <th>Fecha de registro</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( empty( $users ) ) : ?>
                                    <tr><td colspan="5">No hay usuarios con roles logísticos asignados.</td></tr>
                                <?php endif; ?>

                                <?php foreach ( $users as $user ) :
                                    $role_labels = array();
                                    foreach ( (array) $user->roles as $role ) {
                                        if ( isset( $matrix[ $role ] ) || 'administrator' === $role ) {
                                            $role_labels[] = $this->get_mm_role_label( $role );
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html( $user->display_name ); ?></strong>
                                            <small>@<?php echo esc_html( $user->user_login ); ?></small>
                                        </td>
                                        <td><?php echo esc_html( $user->user_email ); ?></td>
                                        <td>
                                            <?php foreach ( $role_labels as $label ) : ?>
                                                <span class="mm-user-role-badge"><?php echo esc_html( $label ); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td><?php echo esc_html( $user->user_registered ); ?></td>
                                        <td><a class="mm-mini-secondary" href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $user->ID ) ); ?>" target="_blank" rel="noopener">Editar</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }



    private function get_lotes_para_sincronizacion_dashboard() {
        $statuses = array( 'mm_cargado', 'publish', 'mm_p_aprobacion' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( isset( $lote->ID ) ) {
                    $lotes[ intval( $lote->ID ) ] = $lote;
                }
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_lote_sync_summary( $lote_id ) {
        $lote_id = intval( $lote_id );
        $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

        $summary = array(
            'total'      => count( $items ),
            'ok'         => 0,
            'error'      => 0,
            'pendiente'  => 0,
            'sync_meta'  => $this->lote_repo->get_sync_meta( $lote_id ),
            'items'      => array(),
        );

        foreach ( $items as $item ) {
            $status = 'pendiente';
            $error  = '';

            $synced_at = property_exists( $item, 'synced_at' ) ? $item->synced_at : '';
            $sync_error = property_exists( $item, 'sync_error' ) ? $item->sync_error : '';

            if ( ! empty( $synced_at ) ) {
                $status = 'ok';
                $summary['ok']++;
            } elseif ( ! empty( $sync_error ) ) {
                $status = 'error';
                $error = $sync_error;
                $summary['error']++;
            } else {
                $summary['pendiente']++;
            }

            $summary['items'][] = array(
                'id'           => intval( $item->id ),
                'sku'          => $item->sku,
                'producto_id'  => intval( $item->producto_id ),
                'nombre'       => intval( $item->producto_id ) ? get_the_title( intval( $item->producto_id ) ) : 'Producto sin nombre',
                'cantidad'     => intval( $item->cantidad_contada ),
                'precio'       => floatval( $item->precio_propuesto ),
                'synced_at'    => $synced_at,
                'status'       => $status,
                'error'        => $error,
            );
        }

        return $summary;
    }

    public function ajax_reintentar_sincronizacion_lote() {
        if ( ! is_user_logged_in() || ! $this->permission_guard->can_access_jefatura_panel() ) {
            wp_send_json_error( array( 'message' => 'Solo jefatura puede reintentar sincronización.' ), 403 );
        }

        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_reintentar_sync_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            wp_send_json_error( array( 'message' => 'Lote no encontrado.' ), 404 );
        }

        delete_post_meta( $lote_id, '_sincronizado_wc' );
        $this->lote_repo->update_sync_status( $lote_id, 'pendiente' );
        update_post_meta( $lote_id, '_mm_sync_retry_requested_at', current_time( 'mysql' ) );
        update_post_meta( $lote_id, '_mm_sync_retry_requested_by', get_current_user_id() );

        $this->lote_repo->update_status( $lote_id, 'mm_cargado' );

        wp_send_json_success( array(
            'message' => 'Reintento solicitado. Revisa nuevamente el estado en unos minutos.',
        ) );
    }

    private function render_sincronizacion_dashboard() {
        if ( ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'Solo jefatura puede ver el control de sincronización.' );
        }

        $lotes = $this->get_lotes_para_sincronizacion_dashboard();
        $total_lotes = count( $lotes );
        $total_ok = 0;
        $total_error = 0;
        $total_pending = 0;

        foreach ( $lotes as $lote ) {
            $summary = $this->get_lote_sync_summary( intval( $lote->ID ) );
            $total_ok += $summary['ok'];
            $total_error += $summary['error'];
            $total_pending += $summary['pendiente'];
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'sincronizacion' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Control de sincronización</span>
                        <h1>Carga a WooCommerce</h1>
                        <p>Verifica qué productos cargaron correctamente, cuáles quedaron pendientes y cuáles tuvieron error.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Lotes revisados</small><strong><?php echo esc_html( $total_lotes ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Productos OK</small><strong><?php echo esc_html( $total_ok ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Pendientes</small><strong><?php echo esc_html( $total_pending ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Con error</small><strong><?php echo esc_html( $total_error ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Estado por lote</h2>
                        <span>Reintenta solo si hay errores o productos pendientes después de cargar.</span>
                    </div>

                    <div class="mm-sync-lote-list">
                        <?php if ( empty( $lotes ) ) : ?>
                            <div class="mm-empty-state">No hay lotes cargados o en proceso de sincronización.</div>
                        <?php endif; ?>

                        <?php foreach ( $lotes as $lote ) :
                            $lote_id = intval( $lote->ID );
                            $summary = $this->get_lote_sync_summary( $lote_id );
                            $nonce = wp_create_nonce( 'mm_reintentar_sync_' . $lote_id );
                            $sync_status = $summary['sync_meta']['status'] ?? 'pendiente';
                        ?>
                            <article class="mm-sync-card" data-lote-id="<?php echo esc_attr( $lote_id ); ?>">
                                <div class="mm-sync-card-head">
                                    <div>
                                        <span class="mm-badge-soft">Lote #<?php echo esc_html( $lote_id ); ?></span>
                                        <h3><?php echo esc_html( get_the_title( $lote_id ) ); ?></h3>
                                        <p>Estado general: <strong><?php echo esc_html( $sync_status ); ?></strong></p>
                                    </div>
                                    <div class="mm-sync-actions">
                                        <a class="mm-mini-secondary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=jefatura&lote_id=' . $lote_id ) ); ?>">Ver lote</a>
                                        <button type="button" class="mm-mini-primary mm-btn-retry-sync" data-lote-id="<?php echo esc_attr( $lote_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Reintentar carga</button>
                                    </div>
                                </div>

                                <div class="mm-sync-metrics">
                                    <span><strong><?php echo esc_html( $summary['total'] ); ?></strong><small>Total</small></span>
                                    <span class="is-ok"><strong><?php echo esc_html( $summary['ok'] ); ?></strong><small>OK</small></span>
                                    <span class="is-pending"><strong><?php echo esc_html( $summary['pendiente'] ); ?></strong><small>Pendientes</small></span>
                                    <span class="is-error"><strong><?php echo esc_html( $summary['error'] ); ?></strong><small>Errores</small></span>
                                </div>

                                <div class="mm-sync-table-wrap">
                                    <table class="mm-sync-table">
                                        <thead>
                                            <tr>
                                                <th>Estado</th>
                                                <th>SKU</th>
                                                <th>Producto</th>
                                                <th>Cant.</th>
                                                <th>Precio</th>
                                                <th>Detalle</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ( $summary['items'] as $item ) : ?>
                                                <tr>
                                                    <td><span class="mm-sync-status mm-sync-<?php echo esc_attr( $item['status'] ); ?>"><?php echo esc_html( strtoupper( $item['status'] ) ); ?></span></td>
                                                    <td><?php echo esc_html( $item['sku'] ); ?></td>
                                                    <td><?php echo esc_html( $item['nombre'] ); ?></td>
                                                    <td><?php echo esc_html( $item['cantidad'] ); ?></td>
                                                    <td>$<?php echo esc_html( number_format( $item['precio'], 0, ',', '.' ) ); ?></td>
                                                    <td><?php echo $item['error'] ? esc_html( $item['error'] ) : ( $item['synced_at'] ? 'Sincronizado: ' . esc_html( $item['synced_at'] ) : 'Pendiente de carga' ); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mm-sync-message" hidden></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }



    private function get_historial_lotes_disponibles() {
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( isset( $lote->ID ) ) {
                    $lotes[ intval( $lote->ID ) ] = $lote;
                }
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_historial_lote_id_actual() {
        $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
        if ( $lote_id > 0 && $this->lote_repo->find( $lote_id ) ) {
            return $lote_id;
        }

        $lotes = $this->get_historial_lotes_disponibles();
        return ! empty( $lotes ) ? intval( $lotes[0]->ID ) : 0;
    }

    private function get_historial_item_events( $lote_id, $item ) {
        $events = array();
        $lote_id = intval( $lote_id );
        $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
        $product_id = isset( $item->producto_id ) ? intval( $item->producto_id ) : 0;

        $sku = isset( $item->sku ) ? $item->sku : '';
        $qty = isset( $item->cantidad_contada ) ? intval( $item->cantidad_contada ) : 0;
        $operator = isset( $item->operador ) && ! empty( $item->operador ) ? $item->operador : '';
        $scan_date = isset( $item->fecha_escaneo ) && ! empty( $item->fecha_escaneo ) ? $item->fecha_escaneo : '';

        $events[] = array(
            'type'  => 'escaneo',
            'icon'  => '📦',
            'title' => 'Producto registrado en lote',
            'text'  => 'SKU ' . $sku . ' con cantidad ' . $qty . ( $operator ? ' por ' . $operator : '' ) . '.',
            'date'  => $scan_date,
        );

        if ( $product_id > 0 ) {
            $thumb = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
            if ( $thumb ) {
                $events[] = array(
                    'type'  => 'foto',
                    'icon'  => '📸',
                    'title' => 'Foto de producto disponible',
                    'text'  => 'El producto tiene imagen registrada para revisión, etiquetas o catálogo.',
                    'date'  => $scan_date,
                );
            }

            $review_state = get_post_meta( $product_id, '_mm_revision_estado', true );
            $review_at = get_post_meta( $product_id, '_mm_revision_at', true );
            $review_by = absint( get_post_meta( $product_id, '_mm_revision_by', true ) );
            if ( 'revisado' === $review_state ) {
                $user = $review_by ? get_userdata( $review_by ) : null;
                $events[] = array(
                    'type'  => 'revision_ia',
                    'icon'  => '🤖',
                    'title' => 'Producto nuevo revisado',
                    'text'  => 'Información de IA revisada' . ( $user ? ' por ' . $user->display_name : '' ) . '.',
                    'date'  => $review_at,
                );
            }
        }

        $cost = isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0;
        $price = isset( $item->precio_propuesto ) ? floatval( $item->precio_propuesto ) : 0;
        if ( $cost > 0 || $price > 0 ) {
            $events[] = array(
                'type'  => 'precio',
                'icon'  => '🏷️',
                'title' => 'Costo y precio asignados',
                'text'  => 'Costo: $' . number_format( $cost, 0, ',', '.' ) . ' · Precio: $' . number_format( $price, 0, ',', '.' ) . '.',
                'date'  => '',
            );
        }

        $approved_at = get_post_meta( $lote_id, '_mm_jefatura_aprobado_at', true );
        $approved_by = absint( get_post_meta( $lote_id, '_mm_jefatura_aprobado_by', true ) );
        if ( $approved_at ) {
            $user = $approved_by ? get_userdata( $approved_by ) : null;
            $events[] = array(
                'type'  => 'autorizacion',
                'icon'  => '✅',
                'title' => 'Precio autorizado por jefatura',
                'text'  => 'Precio final autorizado' . ( $user ? ' por ' . $user->display_name : '' ) . '.',
                'date'  => $approved_at,
            );
        }

        $synced_at = property_exists( $item, 'synced_at' ) ? $item->synced_at : '';
        $sync_error = property_exists( $item, 'sync_error' ) ? $item->sync_error : '';
        if ( $synced_at ) {
            $events[] = array(
                'type'  => 'sync_ok',
                'icon'  => '🔁',
                'title' => 'Sincronizado con WooCommerce',
                'text'  => 'Producto cargado correctamente al sistema.',
                'date'  => $synced_at,
            );
        } elseif ( $sync_error ) {
            $events[] = array(
                'type'  => 'sync_error',
                'icon'  => '⚠️',
                'title' => 'Error de sincronización',
                'text'  => $sync_error,
                'date'  => '',
            );
        }

        $label_statuses = get_post_meta( $lote_id, '_mm_etiquetas_items_status', true );
        $label_statuses = is_array( $label_statuses ) ? $label_statuses : array();
        if ( $item_id && isset( $label_statuses[ $item_id ] ) && is_array( $label_statuses[ $item_id ] ) ) {
            $status = $label_statuses[ $item_id ];
            $print_count = isset( $status['print_count'] ) ? intval( $status['print_count'] ) : 0;
            if ( $print_count > 0 ) {
                $printed_by = ! empty( $status['printed_by'] ) ? get_userdata( absint( $status['printed_by'] ) ) : null;
                $events[] = array(
                    'type'  => 'impresion',
                    'icon'  => '🖨️',
                    'title' => $print_count > 1 ? 'Etiqueta reimpresa' : 'Etiqueta impresa',
                    'text'  => 'Impresiones registradas: ' . $print_count . ( $printed_by ? ' · por ' . $printed_by->display_name : '' ) . '.',
                    'date'  => isset( $status['printed_at'] ) ? $status['printed_at'] : '',
                );
            }
        }

        return $events;
    }

    private function render_historial_producto_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para ver el historial por producto.' );
        }

        $lotes = $this->get_historial_lotes_disponibles();
        $lote_id = $this->get_historial_lote_id_actual();
        $items = $lote_id && $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

        $total_items = count( $items );
        $with_price = 0;
        $synced = 0;
        $printed = 0;

        $label_statuses = $lote_id ? get_post_meta( $lote_id, '_mm_etiquetas_items_status', true ) : array();
        $label_statuses = is_array( $label_statuses ) ? $label_statuses : array();

        foreach ( $items as $item ) {
            if ( isset( $item->precio_propuesto ) && floatval( $item->precio_propuesto ) > 0 ) {
                $with_price++;
            }
            if ( property_exists( $item, 'synced_at' ) && ! empty( $item->synced_at ) ) {
                $synced++;
            }
            $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
            if ( $item_id && isset( $label_statuses[ $item_id ]['print_count'] ) && intval( $label_statuses[ $item_id ]['print_count'] ) > 0 ) {
                $printed++;
            }
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'historial' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Historial por producto</span>
                        <h1>Trazabilidad completa</h1>
                        <p>Consulta qué pasó con cada SKU dentro del lote: escaneo, precios, autorización, sincronización e impresión.</p>
                    </div>
                </header>

                

                <section class="mm-report-filter-card">
                    <div>
                        <h2>Seleccionar lote</h2>
                        <p>El historial se muestra producto por producto dentro del lote seleccionado.</p>
                    </div>
                    <form class="mm-report-date-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <input type="hidden" name="mm_logistica_app" value="historial">
                        <label>Lote
                            <select name="lote_id" class="mm-input">
                                <?php foreach ( $lotes as $lote ) : ?>
                                    <option value="<?php echo esc_attr( $lote->ID ); ?>" <?php selected( $lote_id, $lote->ID ); ?>>
                                        #<?php echo esc_html( $lote->ID ); ?> — <?php echo esc_html( get_the_title( $lote->ID ) ); ?> / <?php echo esc_html( $this->status_label( $this->lote_repo->get_status( $lote->ID ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="mm-mini-primary">Ver historial</button>
                    </form>
                </section>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Productos</small><strong><?php echo esc_html( $total_items ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Con precio</small><strong><?php echo esc_html( $with_price ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Sincronizados</small><strong><?php echo esc_html( $synced ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Etiquetas impresas</small><strong><?php echo esc_html( $printed ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Historial del lote <?php echo $lote_id ? '#' . esc_html( $lote_id ) : ''; ?></h2>
                        <span>Una tarjeta por producto.</span>
                    </div>

                    <div class="mm-history-grid">
                        <?php if ( empty( $items ) ) : ?>
                            <div class="mm-empty-state">No hay productos para mostrar en este lote.</div>
                        <?php endif; ?>

                        <?php foreach ( $items as $item ) :
                            $product_id = isset( $item->producto_id ) ? intval( $item->producto_id ) : 0;
                            $name = $product_id ? get_the_title( $product_id ) : 'Producto sin nombre';
                            $thumb = $product_id ? get_the_post_thumbnail_url( $product_id, 'thumbnail' ) : '';
                            $events = $this->get_historial_item_events( $lote_id, $item );
                        ?>
                            <article class="mm-history-card">
                                <div class="mm-history-product-head">
                                    <div class="mm-history-thumb">
                                        <?php if ( $thumb ) : ?>
                                            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $name ); ?>">
                                        <?php else : ?>
                                            <span>Sin foto</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h3><?php echo esc_html( $name ); ?></h3>
                                        <p>SKU: <strong><?php echo esc_html( isset( $item->sku ) ? $item->sku : '' ); ?></strong> · Cantidad: <strong><?php echo esc_html( isset( $item->cantidad_contada ) ? intval( $item->cantidad_contada ) : 0 ); ?></strong></p>
                                    </div>
                                </div>

                                <div class="mm-history-timeline">
                                    <?php foreach ( $events as $event ) : ?>
                                        <div class="mm-history-event mm-history-<?php echo esc_attr( $event['type'] ); ?>">
                                            <span class="mm-history-event-icon"><?php echo esc_html( $event['icon'] ); ?></span>
                                            <div>
                                                <strong><?php echo esc_html( $event['title'] ); ?></strong>
                                                <p><?php echo esc_html( $event['text'] ); ?></p>
                                                <?php if ( ! empty( $event['date'] ) ) : ?>
                                                    <small><?php echo esc_html( $event['date'] ); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }



    private function get_system_route_checks() {
        $routes = array(
            'dashboard'         => 'Dashboard',
            'bodega'            => 'Bodega',
            'precios'           => 'Precios',
            'jefatura'          => 'Jefatura',
            'etiquetas'         => 'Etiquetas',
            'notificaciones'    => 'Notificaciones',
            'reportes'          => 'Reportes',
            'productos-nuevos'  => 'Productos nuevos',
            'sincronizacion'    => 'Sincronización',
            'usuarios'          => 'Usuarios',
            'historial'         => 'Historial',
            'facturas'          => 'Facturas',
            'mekano'            => 'Mekano',
        );

        $checks = array();

        foreach ( $routes as $slug => $label ) {
            $checks[] = array(
                'slug'   => $slug,
                'label'  => $label,
                'url'    => home_url( '/?mm_logistica_app=' . $slug ),
                'status' => 'Registrada',
            );
        }

        return $checks;
    }

    private function render_sistema_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return $this->render_denied_app( 'Solo administradores pueden ver el diagnóstico del sistema.' );
        }

        $checks = $this->get_system_route_checks();
        $plugin_version = defined( 'MM_LOGISTICA_VERSION' ) ? MM_LOGISTICA_VERSION : 'Sin versión';

        global $wpdb;
        $tables = array(
            $wpdb->prefix . 'mm_lote_items',
            $wpdb->prefix . 'mm_lote_audit_logs',
        );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'sistema' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Sistema</span>
                        <h1>Diagnóstico y estabilidad</h1>
                        <p>Verifica rutas, versión instalada y tablas principales del plugin.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Versión</small><strong><?php echo esc_html( $plugin_version ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>PHP</small><strong><?php echo esc_html( PHP_VERSION ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>WordPress</small><strong><?php echo esc_html( get_bloginfo( 'version' ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Rutas</small><strong><?php echo esc_html( count( $checks ) ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Rutas principales</h2>
                        <span>Abre cada módulo para verificar que carga correctamente.</span>
                    </div>

                    <div class="mm-system-route-grid">
                        <?php foreach ( $checks as $check ) : ?>
                            <a href="<?php echo esc_url( $check['url'] ); ?>" class="mm-system-route-card">
                                <strong><?php echo esc_html( $check['label'] ); ?></strong>
                                <small><?php echo esc_html( $check['slug'] ); ?></small>
                                <span><?php echo esc_html( $check['status'] ); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Tablas del plugin</h2>
                        <span>Validación rápida de base de datos.</span>
                    </div>

                    <div class="mm-system-table-list">
                        <?php foreach ( $tables as $table ) :
                            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
                        ?>
                            <div class="mm-system-table-row">
                                <strong><?php echo esc_html( $table ); ?></strong>
                                <span class="<?php echo $exists ? 'is-ok' : 'is-error'; ?>">
                                    <?php echo $exists ? 'Existe' : 'No encontrada'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Prueba recomendada</h2>
                        <span>Antes de producción.</span>
                    </div>

                    <div class="mm-safe-note">
                        <strong>Recorrido obligatorio:</strong>
                        <p>Bodega → Precios → Jefatura → Sincronización → Etiquetas → Reportes. Hazlo con un lote pequeño de 2 productos.</p>
                    </div>
                </section>
            </section>
        </main>
                        <?php echo $this->render_ia_config_panel(); ?>

        <?php return ob_get_clean();
    }



    private function get_facturas_lotes_disponibles() {
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( isset( $lote->ID ) ) {
                    $lotes[ intval( $lote->ID ) ] = $lote;
                }
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_facturas_lote_id_actual() {
        $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
        if ( $lote_id > 0 && $this->lote_repo->find( $lote_id ) ) {
            return $lote_id;
        }

        $lotes = $this->get_facturas_lotes_disponibles();
        return ! empty( $lotes ) ? intval( $lotes[0]->ID ) : 0;
    }

    private function get_facturas_lote( $lote_id ) {
        $facturas = get_post_meta( intval( $lote_id ), '_mm_facturas_proveedor', true );
        return is_array( $facturas ) ? $facturas : array();
    }

    private function save_facturas_lote( $lote_id, $facturas ) {
        update_post_meta( intval( $lote_id ), '_mm_facturas_proveedor', array_values( $facturas ) );
    }

    private function calcular_costo_lote_factura( $lote_id ) {
        $items = $this->item_repo ? $this->item_repo->get_items( intval( $lote_id ) ) : array();
        $total = 0;

        foreach ( $items as $item ) {
            $qty = isset( $item->cantidad_contada ) ? intval( $item->cantidad_contada ) : 0;
            $cost = isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0;
            $total += $qty * $cost;
        }

        return $total;
    }

    private function format_factura_money( $value ) {
        return '$' . number_format( floatval( $value ), 0, ',', '.' );
    }

    private function handle_factura_upload_file() {
        if ( empty( $_FILES['archivo_factura'] ) || empty( $_FILES['archivo_factura']['name'] ) ) {
            return array(
                'url' => '',
                'attachment_id' => 0,
            );
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $attachment_id = media_handle_upload( 'archivo_factura', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            return array(
                'error' => $attachment_id->get_error_message(),
            );
        }

        return array(
            'url' => wp_get_attachment_url( $attachment_id ),
            'attachment_id' => intval( $attachment_id ),
        );
    }

    public function ajax_guardar_factura_lote() {
        if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para registrar facturas.' ), 403 );
        }

        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_factura_lote_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
        }

        if ( ! $this->lote_repo->find( $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Lote no encontrado.' ), 404 );
        }

        $numero = isset( $_POST['numero_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_factura'] ) ) : '';
        $proveedor = isset( $_POST['proveedor'] ) ? sanitize_text_field( wp_unslash( $_POST['proveedor'] ) ) : '';
        $fecha = isset( $_POST['fecha_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha_factura'] ) ) : '';
        $total = isset( $_POST['total_factura'] ) ? floatval( str_replace( array( '.', ',' ), array( '', '.' ), wp_unslash( $_POST['total_factura'] ) ) ) : 0;
        $estado = isset( $_POST['estado_factura'] ) ? sanitize_key( wp_unslash( $_POST['estado_factura'] ) ) : 'pendiente';
        $observaciones = isset( $_POST['observaciones'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ) ) : '';
            $productos_texto = isset( $_POST['productos_pedido'] ) ? sanitize_textarea_field( wp_unslash( $_POST['productos_pedido'] ) ) : '';
            $productos_internos = $this->parse_pedido_productos_internos( $productos_texto );

        if ( empty( $numero ) ) {
            wp_send_json_error( array( 'message' => 'El número de factura es obligatorio.' ), 400 );
        }

        if ( empty( $proveedor ) ) {
            wp_send_json_error( array( 'message' => 'El proveedor es obligatorio.' ), 400 );
        }

        if ( $total <= 0 ) {
            wp_send_json_error( array( 'message' => 'El total de la factura debe ser mayor a cero.' ), 400 );
        }

        if ( ! in_array( $estado, array( 'pendiente', 'revisada', 'pagada' ), true ) ) {
            $estado = 'pendiente';
        }

        $upload = $this->handle_factura_upload_file();
        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( array( 'message' => 'No se pudo subir el archivo: ' . $upload['error'] ), 400 );
        }

        $facturas = $this->get_facturas_lote( $lote_id );
        $factura_id = uniqid( 'fac_', true );

        $facturas[] = array(
            'id'            => $factura_id,
            'numero'        => $numero,
            'proveedor'     => $proveedor,
            'fecha'         => $fecha,
            'total'         => $total,
            'estado'        => $estado,
            'observaciones' => $observaciones,
            'archivo_url'   => $upload['url'] ?? '',
            'attachment_id' => $upload['attachment_id'] ?? 0,
            'created_at'    => current_time( 'mysql' ),
            'created_by'    => get_current_user_id(),
        );

        $this->save_facturas_lote( $lote_id, $facturas );

        update_post_meta( $lote_id, '_mm_factura_ultima_at', current_time( 'mysql' ) );
        update_post_meta( $lote_id, '_mm_factura_ultima_by', get_current_user_id() );

        wp_send_json_success( array(
            'message' => 'Factura registrada correctamente.',
        ) );
    }

    public function ajax_eliminar_factura_lote() {
        if ( ! is_user_logged_in() || ! $this->permission_guard->can_access_jefatura_panel() ) {
            wp_send_json_error( array( 'message' => 'Solo jefatura puede eliminar facturas.' ), 403 );
        }

        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $factura_id = isset( $_POST['factura_id'] ) ? sanitize_text_field( wp_unslash( $_POST['factura_id'] ) ) : '';
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! $factura_id || ! wp_verify_nonce( $nonce, 'mm_factura_lote_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado.' ), 403 );
        }

        $facturas = $this->get_facturas_lote( $lote_id );
        $facturas = array_values( array_filter( $facturas, function( $factura ) use ( $factura_id ) {
            return isset( $factura['id'] ) && $factura['id'] !== $factura_id;
        } ) );

        $this->save_facturas_lote( $lote_id, $facturas );

        wp_send_json_success( array(
            'message' => 'Factura eliminada correctamente.',
        ) );
    }

    private function render_facturas_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para registrar facturas.' );
        }

        $lotes = $this->get_facturas_lotes_disponibles();
        $lote_id = $this->get_facturas_lote_id_actual();
        $facturas = $lote_id ? $this->get_facturas_lote( $lote_id ) : array();
        $nonce = $lote_id ? wp_create_nonce( 'mm_factura_lote_' . $lote_id ) : '';
        $costo_lote = $lote_id ? $this->calcular_costo_lote_factura( $lote_id ) : 0;

        $total_facturas = 0;
        $pendientes = 0;
        $pagadas = 0;

        foreach ( $facturas as $factura ) {
            $total_facturas += isset( $factura['total'] ) ? floatval( $factura['total'] ) : 0;
            if ( isset( $factura['estado'] ) && 'pagada' === $factura['estado'] ) {
                $pagadas++;
            } else {
                $pendientes++;
            }
        }

        $diferencia = $total_facturas - $costo_lote;

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'facturas' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Facturas de proveedor</span>
                        <h1>Registro de facturas por lote</h1>
                        <p>Asocia facturas de compra al lote, sube soporte y compara el total facturado contra el costo ingresado.</p>
                    </div>
                </header>

                
                
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    
                <?php endif; ?>



<section class="mm-report-filter-card">
                    <div>
                        <h2>Seleccionar lote</h2>
                        <p>Registra o consulta facturas asociadas a un lote de ingreso.</p>
                    </div>
                    <form class="mm-report-date-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <input type="hidden" name="mm_logistica_app" value="facturas">
                        <label>Lote
                            <select name="lote_id" class="mm-input">
                                <?php foreach ( $lotes as $lote ) : ?>
                                    <option value="<?php echo esc_attr( $lote->ID ); ?>" <?php selected( $lote_id, $lote->ID ); ?>>
                                        #<?php echo esc_html( $lote->ID ); ?> — <?php echo esc_html( get_the_title( $lote->ID ) ); ?> / <?php echo esc_html( $this->status_label( $this->lote_repo->get_status( $lote->ID ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="mm-mini-primary">Ver facturas</button>
                    </form>
                </section>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Facturas</small><strong><?php echo esc_html( count( $facturas ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Total factura</small><strong><?php echo esc_html( $this->format_factura_money( $total_facturas ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Costo lote</small><strong><?php echo esc_html( $this->format_factura_money( $costo_lote ) ); ?></strong></div>
                    <div class="mm-role-summary-card <?php echo abs( $diferencia ) <= 1000 ? 'is-success' : 'is-warning'; ?>"><small>Diferencia</small><strong><?php echo esc_html( $this->format_factura_money( $diferencia ) ); ?></strong></div>
                </div>

                <div class="mm-factura-layout">
                    <section class="mm-platform-section">
                        <div class="mm-section-head">
                            <h2>Registrar factura</h2>
                            <span>Adjunta foto o PDF de la factura.</span>
                        </div>

                        <?php if ( ! $lote_id ) : ?>
                            <div class="mm-empty-state">No hay lote seleccionado.</div>
                        <?php else : ?>
                            <form class="mm-factura-form" enctype="multipart/form-data">
                                <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">
                                <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                                <div class="mm-factura-form-grid">
                                    <label>Número de factura
                                        <input class="mm-input" type="text" name="numero_factura" placeholder="Ej: FV-2034" required>
                                    </label>
                                    <label>Proveedor
                                        <input class="mm-input" type="text" name="proveedor" placeholder="Nombre del proveedor" required>
                                    </label>
                                    <label>Fecha de factura
                                        <input class="mm-input" type="date" name="fecha_factura" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
                                    </label>
                                    <label>Total factura
                                        <input class="mm-input" type="number" name="total_factura" placeholder="2500000" min="0" step="1" required>
                                    </label>
                                    <label>Estado
                                        <select class="mm-input" name="estado_factura">
                                            <option value="pendiente">Pendiente</option>
                                            <option value="revisada">Revisada</option>
                                            <option value="pagada">Pagada</option>
                                        </select>
                                    </label>
                                    <label>Soporte factura
                                        <input class="mm-input" type="file" name="archivo_factura" accept="application/pdf,image/jpeg,image/png,image/webp">
                                    </label>
                                </div>

                                <label>Observaciones
                                    <textarea class="mm-input mm-textarea" name="observaciones" rows="3" placeholder="Notas internas sobre la factura, proveedor, diferencias o pago."></textarea>
                                </label>

                                <button type="submit" class="mm-mini-primary">Guardar factura</button>
                                <div class="mm-factura-msg" hidden></div>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="mm-platform-section">
                        <div class="mm-section-head">
                            <h2>Facturas registradas</h2>
                            <span>Pendientes: <?php echo esc_html( $pendientes ); ?> · Pagadas: <?php echo esc_html( $pagadas ); ?></span>
                        </div>

                        <div class="mm-factura-list">
                            <?php if ( empty( $facturas ) ) : ?>
                                <div class="mm-empty-state">Aún no hay facturas registradas para este lote.</div>
                            <?php endif; ?>

                            <?php foreach ( array_reverse( $facturas ) as $factura ) :
                                $created_by = ! empty( $factura['created_by'] ) ? get_userdata( absint( $factura['created_by'] ) ) : null;
                            ?>
                                <article class="mm-factura-card">
                                    <div class="mm-factura-card-head">
                                        <div>
                                            <span class="mm-badge-soft"><?php echo esc_html( ucfirst( $factura['estado'] ?? 'pendiente' ) ); ?></span>
                                            <h3><?php echo esc_html( $factura['numero'] ?? 'Sin número' ); ?></h3>
                                            <p><?php echo esc_html( $factura['proveedor'] ?? 'Sin proveedor' ); ?></p>
                                        </div>
                                        <strong><?php echo esc_html( $this->format_factura_money( $factura['total'] ?? 0 ) ); ?></strong>
                                    </div>

                                    <ul class="mm-factura-meta">
                                        <li><span>Fecha factura</span><b><?php echo esc_html( $factura['fecha'] ?? '-' ); ?></b></li>
                                        <li><span>Registrada</span><b><?php echo esc_html( $factura['created_at'] ?? '-' ); ?></b></li>
                                        <li><span>Usuario</span><b><?php echo esc_html( $created_by ? $created_by->display_name : '-' ); ?></b></li>
                                    </ul>

                                    <?php if ( ! empty( $factura['observaciones'] ) ) : ?>
                                        <p class="mm-factura-observacion"><?php echo esc_html( $factura['observaciones'] ); ?></p>
                                    <?php endif; ?>

                                    <div class="mm-factura-actions">
                                        <?php if ( ! empty( $factura['archivo_url'] ) ) : ?>
                                            <a class="mm-mini-secondary" href="<?php echo esc_url( $factura['archivo_url'] ); ?>" target="_blank" rel="noopener">Ver soporte</a>
                                        <?php endif; ?>
                                        <?php if ( $this->permission_guard->can_access_jefatura_panel() ) : ?>
                                            <button type="button" class="mm-mini-danger mm-btn-delete-factura" data-lote-id="<?php echo esc_attr( $lote_id ); ?>" data-factura-id="<?php echo esc_attr( $factura['id'] ?? '' ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Eliminar</button>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </section>
        </main>
        <?php return ob_get_clean();
    }



    private function normalize_factura_number_value( $value ) {
        return trim( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $value ) );
    }

    private function normalize_factura_money_value( $value ) {
        $value = trim( (string) $value );
        $value = preg_replace( '/[^\d\,\.]/', '', $value );

        if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
            // Si trae ambos, asumimos separador decimal al final y eliminamos miles.
            if ( strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
                $value = str_replace( '.', '', $value );
                $value = str_replace( ',', '.', $value );
            } else {
                $value = str_replace( ',', '', $value );
            }
        } elseif ( false !== strpos( $value, ',' ) ) {
            $parts = explode( ',', $value );
            if ( strlen( end( $parts ) ) === 3 ) {
                $value = str_replace( ',', '', $value );
            } else {
                $value = str_replace( ',', '.', $value );
            }
        }

        return floatval( $value );
    }

    private function mm_pdf_unescape_text( $text ) {
        $text = str_replace( array( '\\(', '\\)', '\\\\', '\n', '\r', '\t' ), array( '(', ')', '\\', "\n", "\r", "\t" ), $text );
        $text = preg_replace( '/\\\\([0-7]{1,3})/', function( $m ) {
            return chr( octdec( $m[1] ) );
        }, $text );
        return $text;
    }

    private function extract_text_from_pdf_file( $path ) {
        if ( empty( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
            return '';
        }

        $size = @filesize( $path );
        if ( $size && $size > 8 * 1024 * 1024 ) {
            return '';
        }

        $raw = @file_get_contents( $path );
        if ( false === $raw || '' === $raw ) {
            return '';
        }

        $texts = array();

        // Intento 1: extraer streams y descomprimir FlateDecode si aplica.
        if ( preg_match_all( '/<<(.*?)>>\s*stream\s*\r?\n?(.*?)\r?\n?endstream/s', $raw, $streams, PREG_SET_ORDER ) ) {
            foreach ( $streams as $stream ) {
                $dict = $stream[1];
                $data = $stream[2];

                if ( false !== strpos( $dict, '/FlateDecode' ) ) {
                    $decoded = @gzuncompress( $data );
                    if ( false === $decoded ) {
                        $decoded = @gzdecode( $data );
                    }
                    if ( false === $decoded ) {
                        $decoded = @gzinflate( substr( $data, 2 ) );
                    }
                    if ( false !== $decoded ) {
                        $data = $decoded;
                    }
                }

                // Texto en paréntesis: (texto) Tj / dentro de TJ.
                if ( preg_match_all( '/\((?:\\\\.|[^\\\\)])*\)/s', $data, $matches ) ) {
                    foreach ( $matches[0] as $match ) {
                        $clean = substr( $match, 1, -1 );
                        $clean = $this->mm_pdf_unescape_text( $clean );
                        if ( trim( $clean ) !== '' ) {
                            $texts[] = $clean;
                        }
                    }
                }

                // Texto en hexadecimal <0048006f006c0061>
                if ( preg_match_all( '/<([0-9A-Fa-f]{4,})>/', $data, $hexes ) ) {
                    foreach ( $hexes[1] as $hex ) {
                        $bin = @hex2bin( $hex );
                        if ( $bin ) {
                            if ( function_exists( 'mb_convert_encoding' ) ) {
                                $utf16 = @mb_convert_encoding( $bin, 'UTF-8', 'UTF-16BE' );
                                $ascii = @mb_convert_encoding( $bin, 'UTF-8', 'ISO-8859-1' );
                                $candidate = strlen( trim( $utf16 ) ) > strlen( trim( $ascii ) ) ? $utf16 : $ascii;
                            } else {
                                $candidate = @iconv( 'UTF-16BE', 'UTF-8//IGNORE', $bin );
                                if ( false === $candidate || '' === trim( $candidate ) ) {
                                    $candidate = @iconv( 'ISO-8859-1', 'UTF-8//IGNORE', $bin );
                                }
                                if ( false === $candidate ) {
                                    $candidate = '';
                                }
                            }
                            if ( trim( $candidate ) !== '' ) {
                                $texts[] = $candidate;
                            }
                        }
                    }
                }
            }
        }

        // Intento 2: buscar texto plano incrustado en el PDF.
        $plain_source = substr( $raw, 0, 500000 );
        $plain = @preg_replace( '/[^\P{C}\r\n\t]+/u', ' ', $plain_source );
        if ( is_string( $plain ) && preg_match( '/Factura|TOTAL|Proveedor|Pedido|Descripción|NOVAVENTA|NIT/i', $plain ) ) {
            $texts[] = $plain;
        }

        $text = implode( "\n", $texts );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( "/[ \t]+/", ' ', $text );
        $text = preg_replace( "/\n{2,}/", "\n", $text );

        return trim( $text );
    }

    private function extract_factura_text_from_uploaded_file( $field_name ) {
        if ( empty( $_FILES[ $field_name ] ) || empty( $_FILES[ $field_name ]['tmp_name'] ) ) {
            return '';
        }

        $file = $_FILES[ $field_name ];
        $name = sanitize_text_field( $file['name'] ?? '' );
        $type = sanitize_text_field( $file['type'] ?? '' );
        $tmp  = $file['tmp_name'];

        $text = '';

        if ( preg_match( '/\.pdf$/i', $name ) || false !== stripos( $type, 'pdf' ) ) {
            $text = $this->extract_text_from_pdf_file( $tmp );
        }

        if ( empty( $text ) ) {
            $text = 'Archivo recibido: ' . $name . '. No se pudo extraer texto real. Si es imagen o PDF escaneado, pega el texto manualmente o conecta OCR externo.';
        }

        return $text;
    }

    private function parse_factura_ia_from_text( $text ) {
        $text = trim( (string) $text );
        $compact = preg_replace( '/[ \t]+/', ' ', $text );

        $data = array(
            'numero_factura'       => '',
            'proveedor'            => '',
            'nit_proveedor'        => '',
            'fecha_factura'        => current_time( 'Y-m-d' ),
            'total_factura'        => '',
            'iva_detectado'        => '',
            'pedido_numero'        => '',
            'productos_detectados' => array(),
            'confianza'            => 'media',
            'observaciones_ia'     => 'Lectura preliminar desde PDF/texto. Revisa y confirma antes de crear el lote.',
            'texto_extraido'       => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 3000 ) : substr( $text, 0, 3000 ),
        );

        // Número factura: prioriza "Factura Electrónica de venta No." o "No."
        if ( preg_match( '/Factura\s+(?:Electr[oó]nica\s+de\s+venta|de\s+venta)?\s*No\.?\s*([0-9A-Za-z\-]+)/iu', $compact, $m ) ) {
            $data['numero_factura'] = $this->normalize_factura_number_value( $m[1] );
        } elseif ( preg_match( '/\bNo\.?\s*([0-9]{5,})\b/u', $compact, $m ) ) {
            $data['numero_factura'] = $this->normalize_factura_number_value( $m[1] );
        } elseif ( preg_match( '/(?:factura|fact\.|fv|nro\.?|número)\s*[:#-]?\s*([A-Z0-9\-]+)/iu', $compact, $m ) ) {
            $data['numero_factura'] = $this->normalize_factura_number_value( $m[1] );
        }

        // Proveedor y NIT.
        if ( preg_match( '/([A-ZÁÉÍÓÚÑ0-9\.\s]+S\.A\.S)\s+([0-9]{6,12}\-?[0-9]?)/u', $compact, $m ) ) {
            $data['proveedor'] = trim( sanitize_text_field( $m[1] ) );
            $data['nit_proveedor'] = trim( sanitize_text_field( $m[2] ) );
        } elseif ( preg_match( '/(NOVAVENTA\s+S\.A\.S)/iu', $compact, $m ) ) {
            $data['proveedor'] = 'NOVAVENTA S.A.S';
        } elseif ( preg_match( '/(?:proveedor|emisor)\s*[:#-]?\s*([^\n\r]+)/iu', $text, $m ) ) {
            $data['proveedor'] = sanitize_text_field( trim( $m[1] ) );
        }

        if ( empty( $data['nit_proveedor'] ) && preg_match( '/NIT\s*([0-9\.\-]+)/iu', $compact, $m ) ) {
            $data['nit_proveedor'] = sanitize_text_field( $m[1] );
        }

        // Fecha factura.
        if ( preg_match( '/Fecha\s+Factura\s+(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/iu', $compact, $m ) ) {
            $data['fecha_factura'] = sprintf( '%04d-%02d-%02d', intval( $m[3] ), intval( $m[2] ), intval( $m[1] ) );
        } elseif ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $compact, $m ) ) {
            $data['fecha_factura'] = sanitize_text_field( $m[1] );
        } elseif ( preg_match( '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $compact, $m ) ) {
            $data['fecha_factura'] = sprintf( '%04d-%02d-%02d', intval( $m[3] ), intval( $m[2] ), intval( $m[1] ) );
        }

        // Totales.
        if ( preg_match( '/TOTAL\s+FACTURA\s+\$?\s*([0-9\.\,]+)/iu', $compact, $m ) ) {
            $data['total_factura'] = (string) intval( round( $this->normalize_factura_money_value( $m[1] ) ) );
        } elseif ( preg_match( '/TOTAL\s+A\s+PAGAR\s+\$?\s*([0-9\.\,]+)/iu', $compact, $m ) ) {
            $data['total_factura'] = (string) intval( round( $this->normalize_factura_money_value( $m[1] ) ) );
        } elseif ( preg_match( '/(?:total|valor\s+total|gran\s+total)\s*[:$ ]+\s*([0-9\.\,]+)/iu', $compact, $m ) ) {
            $data['total_factura'] = (string) intval( round( $this->normalize_factura_money_value( $m[1] ) ) );
        }

        if ( preg_match( '/Valor\s+Total\s+de\s+Iva\s+([0-9\.\,]+)/iu', $compact, $m ) ) {
            $data['iva_detectado'] = (string) intval( round( $this->normalize_factura_money_value( $m[1] ) ) );
        }

        if ( preg_match( '/Pedido\s+No\.?\s*([0-9]+)/iu', $compact, $m ) ) {
            $data['pedido_numero'] = sanitize_text_field( $m[1] );
        }

        // Productos: patrón de tabla con item/código/descripción/iva/cantidad/unidad/valor...
        $lines = preg_split( '/\r\n|\r|\n/', $text );
        $buffered = array();

        foreach ( $lines as $line ) {
            $line = trim( preg_replace( '/\s+/', ' ', $line ) );
            if ( strlen( $line ) < 8 ) {
                continue;
            }

            // Caso Novaventa / factura tabular:
            // 1 97317 Gel Pote Ego Attraction x 110ml 19 3 unidad 2,580 1,470 7,742
            if ( preg_match( '/^(\d{1,3})\s+(\d{1,8})\s+(.+?)\s+(0|5|19)\s+(\d{1,5})\s+(unidad|und|unds|unid|u)\s+([0-9\.\,]+)(?:\s+([0-9\.\,]+))?(?:\s+([0-9\.\,]+))?(?:\s+([0-9\.\,]+))?$/iu', $line, $m ) ) {
                $descripcion = trim( $m[3] );
                if ( preg_match( '/cargo\s+por\s+manejo/iu', $descripcion ) ) {
                    continue;
                }
                $valor_unitario = $this->normalize_factura_money_value( $m[7] );
                $valor_total = ! empty( $m[10] ) ? $this->normalize_factura_money_value( $m[10] ) : ( ! empty( $m[9] ) ? $this->normalize_factura_money_value( $m[9] ) : 0 );

                $data['productos_detectados'][] = array(
                    'item'          => intval( $m[1] ),
                    'codigo'        => sanitize_text_field( $m[2] ),
                    'nombre'        => sanitize_text_field( $descripcion ),
                    'iva'           => intval( $m[4] ),
                    'cantidad'      => intval( $m[5] ),
                    'costo'         => intval( round( $valor_unitario ) ),
                    'valor_total'   => intval( round( $valor_total ) ),
                );
                continue;
            }

            // Caso manual simple: Shampoo 12 8500
            if ( preg_match( '/^(.+?)\s+(?:x\s*)?(\d{1,5})\s+[$]?\s*([0-9\.\,]{3,})$/iu', $line, $m ) ) {
                $name = trim( $m[1] );
                if ( preg_match( '/factura|proveedor|total|iva|fecha|subtotal|pago|saldo|banco|convenio|resoluci[oó]n/iu', $name ) ) {
                    continue;
                }
                $data['productos_detectados'][] = array(
                    'item'        => count( $data['productos_detectados'] ) + 1,
                    'codigo'      => '',
                    'nombre'      => sanitize_text_field( $name ),
                    'iva'         => '',
                    'cantidad'    => intval( $m[2] ),
                    'costo'       => intval( round( $this->normalize_factura_money_value( $m[3] ) ) ),
                    'valor_total' => 0,
                );
            }
        }

        // Si no se detectaron por línea, intentar sobre texto compacto para líneas que se pegaron juntas.
        if ( empty( $data['productos_detectados'] ) ) {
            if ( preg_match_all( '/(\d{1,3})\s+(\d{3,8})\s+([A-ZÁÉÍÓÚÑa-záéíóúñ0-9\s\.\-]+?)\s+(0|5|19)\s+(\d{1,5})\s+unidad\s+([0-9\.\,]+)/u', $compact, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $m ) {
                    $descripcion = trim( $m[3] );
                    if ( preg_match( '/cargo\s+por\s+manejo/iu', $descripcion ) ) {
                        continue;
                    }
                    $data['productos_detectados'][] = array(
                        'item'        => intval( $m[1] ),
                        'codigo'      => sanitize_text_field( $m[2] ),
                        'nombre'      => sanitize_text_field( $descripcion ),
                        'iva'         => intval( $m[4] ),
                        'cantidad'    => intval( $m[5] ),
                        'costo'       => intval( round( $this->normalize_factura_money_value( $m[6] ) ) ),
                        'valor_total' => 0,
                    );
                }
            }
        }

        if ( empty( $data['productos_detectados'] ) ) {
            $data['confianza'] = 'baja';
            $data['observaciones_ia'] = 'Se extrajo texto, pero no se detectaron productos con seguridad. Revisa el texto extraído o pega la tabla de productos manualmente.';
        } else {
            $data['confianza'] = 'alta';
            $data['observaciones_ia'] = 'Se detectaron ' . count( $data['productos_detectados'] ) . ' productos. Revisa nombres, cantidades y costos antes de crear el lote.';
        }

        return $data;
    }


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

    public function ajax_analizar_factura_ia() {
        try {
            $texto_manual = isset( $_POST['texto_factura'] ) ? sanitize_textarea_field( wp_unslash( $_POST['texto_factura'] ) ) : '';
            $result = $this->openai_service->analizar_pedido_con_openai_directo( $texto_manual );
            if ( ! is_array( $result ) || ! empty( $result['error'] ) ) { wp_send_json_error( array( 'message' => is_array($result)&&!empty($result['error'])?$result['error']:'No se pudo analizar factura.', 'data'=>$result ), 500 ); }
            wp_send_json_success( array( 'message'=>'Factura analizada con OpenAI directo.', 'data'=>$result ) );
        } catch ( \Throwable $e ) { error_log('[MegaMundo] Error OpenAI factura: '.$e->getMessage()); wp_send_json_error( array( 'message'=> current_user_can('manage_options') ? 'Error OpenAI directo: '.$e->getMessage() : 'No se pudo analizar la factura.' ), 500 ); }
    }

    public function ajax_crear_lote_desde_factura() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para crear lotes desde factura.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_factura_ia' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $numero = isset( $_POST['numero_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_factura'] ) ) : '';
            $proveedor = isset( $_POST['proveedor'] ) ? sanitize_text_field( wp_unslash( $_POST['proveedor'] ) ) : '';
            $fecha = isset( $_POST['fecha_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha_factura'] ) ) : current_time( 'Y-m-d' );
            $total = isset( $_POST['total_factura'] ) ? floatval( preg_replace( '/[^0-9\.]/', '', wp_unslash( $_POST['total_factura'] ) ) ) : 0;
            $observaciones = isset( $_POST['observaciones_ia'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observaciones_ia'] ) ) : '';
            $productos_esperados = isset( $_POST['productos_detectados_json'] ) ? $this->mm_normalize_expected_products_payload( $_POST['productos_detectados_json'] ) : array();

            if ( empty( $numero ) ) {
                wp_send_json_error( array( 'message' => 'Escribe el número de factura.' ), 400 );
            }

            if ( empty( $proveedor ) ) {
                wp_send_json_error( array( 'message' => 'Escribe el proveedor.' ), 400 );
            }

            $upload = $this->mm_handle_factura_soporte_upload( 'archivo_ia' );
            if ( isset( $upload['error'] ) && ! empty( $upload['error'] ) ) {
                wp_send_json_error( array( 'message' => 'No se pudo subir el soporte: ' . $upload['error'] ), 400 );
            }

            $existing = get_posts( array(
                'post_type'      => 'lotes_ingreso',
                'post_status'    => array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' ),
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'   => '_mm_factura_origen_numero',
                        'value' => $numero,
                    ),
                ),
            ) );

            if ( ! empty( $existing ) ) {
                wp_send_json_error( array(
                    'message' => 'Ya existe un lote creado desde esta factura: lote #' . intval( $existing[0]->ID ),
                ), 400 );
            }

            $lote_id = wp_insert_post( array(
                'post_type'   => 'lotes_ingreso',
                'post_title'  => 'Lote Factura ' . $numero . ' - ' . $proveedor,
                'post_status' => 'draft',
                'post_author' => get_current_user_id(),
            ) );

            if ( is_wp_error( $lote_id ) || ! $lote_id ) {
                wp_send_json_error( array( 'message' => 'No se pudo crear el lote.' ), 500 );
            }

            update_post_meta( $lote_id, '_mm_factura_origen_numero', $numero );
            update_post_meta( $lote_id, '_mm_factura_origen_proveedor', $proveedor );
            update_post_meta( $lote_id, '_mm_factura_origen_fecha', $fecha );
            update_post_meta( $lote_id, '_mm_factura_origen_total', $total );
            update_post_meta( $lote_id, '_mm_factura_origen_observaciones', $observaciones );
            update_post_meta( $lote_id, '_mm_factura_origen_soporte_url', $upload['url'] ?? '' );
            update_post_meta( $lote_id, '_mm_factura_origen_soporte_id', $upload['attachment_id'] ?? 0 );
            update_post_meta( $lote_id, '_mm_tipo_movimiento', 'sumar' );
        update_post_meta( $lote_id, '_mm_pedido_productos_internos_lote', $productos_internos );
        if ( ! empty( $productos_bodega ) ) {
            update_post_meta( $lote_id, '_mm_factura_productos_esperados', $productos_bodega );
            update_post_meta( $lote_id, '_mm_factura_productos_esperados_count', count( $productos_bodega ) );
        }
            if ( ! empty( $productos_esperados ) ) {
                update_post_meta( $lote_id, '_mm_factura_productos_esperados', $productos_esperados );
                update_post_meta( $lote_id, '_mm_factura_productos_esperados_count', count( $productos_esperados ) );
            }

            update_post_meta( $lote_id, '_mm_facturas_proveedor', array(
                array(
                    'id'            => uniqid( 'fac_', true ),
                    'numero'        => $numero,
                    'proveedor'     => $proveedor,
                    'fecha'         => $fecha,
                    'total'         => $total,
                    'estado'        => 'pendiente',
                    'observaciones' => $observaciones,
                    'archivo_url'   => $upload['url'] ?? '',
                    'attachment_id' => $upload['attachment_id'] ?? 0,
                    'created_at'    => current_time( 'mysql' ),
                    'created_by'    => get_current_user_id(),
                ),
            ) );

            wp_send_json_success( array(
                'message'  => 'Lote creado y factura guardada como soporte.',
                'redirect' => home_url( '/?mm_logistica_app=bodega&lote_id=' . intval( $lote_id ) ),
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error creando lote desde factura: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => 'No se pudo crear el lote desde la factura. Revisa los datos e inténtalo de nuevo.',
                'technical' => current_user_can( 'manage_options' ) ? $e->getMessage() : '',
            ), 500 );
        }
    }


    private function mm_handle_factura_soporte_upload( $field_name ) {
        if ( empty( $_FILES[ $field_name ] ) || empty( $_FILES[ $field_name ]['name'] ) ) {
            return array(
                'url' => '',
                'attachment_id' => 0,
            );
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $attachment_id = media_handle_upload( $field_name, 0 );

        if ( is_wp_error( $attachment_id ) ) {
            return array(
                'url' => '',
                'attachment_id' => 0,
                'error' => $attachment_id->get_error_message(),
            );
        }

        return array(
            'url' => wp_get_attachment_url( $attachment_id ),
            'attachment_id' => intval( $attachment_id ),
        );
    }



    private function get_factura_ai_webhook_url() {
        return trim( (string) get_option( 'mm_factura_ai_webhook_url', '' ) );
    }

    private function get_factura_ai_webhook_token() {
        return trim( (string) get_option( 'mm_factura_ai_webhook_token', '' ) );
    }

    public function ajax_guardar_factura_ai_webhook() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Solo administradores pueden configurar el webhook de IA.' ), 403 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_factura_ai_config' ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
        }

        $url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
            $openai_api_key = isset( $_POST['openai_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ) ) : '';
        $token = isset( $_POST['webhook_token'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_token'] ) ) : '';

        if ( ! empty( $url ) && ! wp_http_validate_url( $url ) ) {
            wp_send_json_error( array( 'message' => 'La URL del webhook no es válida.' ), 400 );
        }

        update_option( 'mm_factura_ai_webhook_url', $url );
        update_option( 'mm_factura_ai_webhook_token', $token );

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
            update_option( 'mm_openai_invoice_model', $invoice_model );
            update_option( 'mm_openai_reasoning_effort', $reasoning_effort );
            update_option( 'mm_openai_temperature', (string) $temperature );

        wp_send_json_success( array( 'message' => 'Webhook de IA guardado correctamente.' ) );
    }



    private function analizar_factura_con_webhook_ia( $texto_manual = '' ) {
        return $this->openai_service->analizar_factura_con_webhook_ia( $texto_manual );
    }



    private function get_item_multi_prices_meta( $lote_id ) {
        $prices = get_post_meta( intval( $lote_id ), '_mm_items_multi_prices', true );
        return is_array( $prices ) ? $prices : array();
    }

    private function get_item_multi_price_values( $lote_id, $item ) {
        $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
        $meta = $this->get_item_multi_prices_meta( $lote_id );

        $detal = isset( $item->precio_propuesto ) ? floatval( $item->precio_propuesto ) : 0;
        $mayor = 0;
        $gran_mayor = 0;

        if ( $item_id && isset( $meta[ $item_id ] ) && is_array( $meta[ $item_id ] ) ) {
            $detal = isset( $meta[ $item_id ]['detal'] ) ? floatval( $meta[ $item_id ]['detal'] ) : $detal;
            $mayor = isset( $meta[ $item_id ]['mayor'] ) ? floatval( $meta[ $item_id ]['mayor'] ) : 0;
            $gran_mayor = isset( $meta[ $item_id ]['gran_mayor'] ) ? floatval( $meta[ $item_id ]['gran_mayor'] ) : 0;
        }

        if ( $mayor <= 0 && $detal > 0 ) {
            $mayor = $detal;
        }
        if ( $gran_mayor <= 0 && $mayor > 0 ) {
            $gran_mayor = $mayor;
        }

        return array(
            'detal'      => $detal,
            'mayor'      => $mayor,
            'gran_mayor' => $gran_mayor,
        );
    }

    private function calculate_margin_percent_safe( $cost, $price ) {
        $cost = floatval( $cost );
        $price = floatval( $price );
        if ( $price <= 0 ) {
            return 0;
        }
        return round( ( ( $price - $cost ) / $price ) * 100, 1 );
    }

    private function validar_precios_multiples_item( $cost, $detal, $mayor, $gran_mayor ) {
        $cost = floatval( $cost );
        $detal = floatval( $detal );
        $mayor = floatval( $mayor );
        $gran_mayor = floatval( $gran_mayor );

        if ( $detal <= 0 || $mayor <= 0 || $gran_mayor <= 0 ) {
            return 'Los tres precios deben estar completos.';
        }

        if ( $cost > 0 && ( $detal < $cost || $mayor < $cost || $gran_mayor < $cost ) ) {
            return 'Ningún precio debería quedar por debajo del costo.';
        }

        if ( ! ( $detal >= $mayor && $mayor >= $gran_mayor ) ) {
            return 'La lógica recomendada es: detal ≥ mayor ≥ gran mayor.';
        }

        return '';
    }

    public function ajax_guardar_precios_multiples() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para guardar precios.' ), 403 );
            }

            $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

            if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_precios_multiples_' . $lote_id ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
            $existing = $this->get_item_multi_prices_meta( $lote_id );

            $detal_values = isset( $_POST['precio_detal'] ) && is_array( $_POST['precio_detal'] ) ? wp_unslash( $_POST['precio_detal'] ) : array();
            $mayor_values = isset( $_POST['precio_mayor'] ) && is_array( $_POST['precio_mayor'] ) ? wp_unslash( $_POST['precio_mayor'] ) : array();
            $gran_values  = isset( $_POST['precio_gran_mayor'] ) && is_array( $_POST['precio_gran_mayor'] ) ? wp_unslash( $_POST['precio_gran_mayor'] ) : array();

            $warnings = array();

            foreach ( $items as $item ) {
                $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
                if ( ! $item_id ) {
                    continue;
                }

                $cost = isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0;
                $old = $this->get_item_multi_price_values( $lote_id, $item );

                $detal = isset( $detal_values[ $item_id ] ) ? floatval( preg_replace( '/[^0-9\.]/', '', sanitize_text_field( $detal_values[ $item_id ] ) ) ) : $old['detal'];
                $mayor = isset( $mayor_values[ $item_id ] ) ? floatval( preg_replace( '/[^0-9\.]/', '', sanitize_text_field( $mayor_values[ $item_id ] ) ) ) : $old['mayor'];
                $gran  = isset( $gran_values[ $item_id ] ) ? floatval( preg_replace( '/[^0-9\.]/', '', sanitize_text_field( $gran_values[ $item_id ] ) ) ) : $old['gran_mayor'];

                $validation = $this->validar_precios_multiples_item( $cost, $detal, $mayor, $gran );
                if ( $validation ) {
                    $warnings[] = 'SKU ' . ( $item->sku ?? $item_id ) . ': ' . $validation;
                }

                $existing[ $item_id ] = array(
                    'detal'      => $detal,
                    'mayor'      => $mayor,
                    'gran_mayor' => $gran,
                    'updated_at'  => current_time( 'mysql' ),
                    'updated_by'  => get_current_user_id(),
                );
            }

            update_post_meta( $lote_id, '_mm_items_multi_prices', $existing );
            update_post_meta( $lote_id, '_mm_multi_prices_updated_at', current_time( 'mysql' ) );
            update_post_meta( $lote_id, '_mm_multi_prices_updated_by', get_current_user_id() );

            wp_send_json_success( array(
                'message'  => empty( $warnings ) ? 'Precios múltiples guardados correctamente.' : 'Precios guardados con advertencias.',
                'warnings' => $warnings,
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error guardando precios múltiples: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => 'No se pudieron guardar los precios múltiples.',
                'technical' => current_user_can( 'manage_options' ) ? $e->getMessage() : '',
            ), 500 );
        }
    }

    private function render_multi_prices_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();

        if ( empty( $items ) ) {
            return '';
        }

        $can_edit = $this->permission_guard->can_access_precios_panel() || $this->permission_guard->can_access_jefatura_panel();
        $nonce = wp_create_nonce( 'mm_precios_multiples_' . $lote_id );

        ob_start(); ?>
        <section class="mm-platform-section mm-multi-prices-panel">
            <div class="mm-section-head">
                <h2>Precios múltiples</h2>
                <span>Precio detal, precio por mayor y precio gran mayor. La etiqueta principal usa precio detal.</span>
            </div>

            <form class="mm-multi-prices-form">
                <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">
                <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                <div class="mm-multi-prices-table-wrap">
                    <table class="mm-multi-prices-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Producto</th>
                                <th>Costo</th>
                                <th>Detal</th>
                                <th>Mayor</th>
                                <th>Gran mayor</th>
                                <th>Márgenes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $items as $item ) :
                                $item_id = isset( $item->id ) ? intval( $item->id ) : 0;
                                $product_id = isset( $item->producto_id ) ? intval( $item->producto_id ) : 0;
                                $name = $product_id ? get_the_title( $product_id ) : 'Producto sin nombre';
                                $sku = isset( $item->sku ) ? $item->sku : '';
                                $cost = isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0;
                                $prices = $this->get_item_multi_price_values( $lote_id, $item );
                                $warning = $this->validar_precios_multiples_item( $cost, $prices['detal'], $prices['mayor'], $prices['gran_mayor'] );
                            ?>
                                <tr class="<?php echo $warning ? 'has-price-warning' : ''; ?>">
                                    <td><strong><?php echo esc_html( $sku ); ?></strong></td>
                                    <td><?php echo esc_html( $name ); ?><?php if ( $warning ) : ?><small class="mm-price-warning"><?php echo esc_html( $warning ); ?></small><?php endif; ?></td>
                                    <td>$<?php echo esc_html( number_format( $cost, 0, ',', '.' ) ); ?></td>
                                    <td><input class="mm-input mm-price-input" type="number" min="0" step="1" name="precio_detal[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( intval( $prices['detal'] ) ); ?>" <?php disabled( ! $can_edit ); ?>></td>
                                    <td><input class="mm-input mm-price-input" type="number" min="0" step="1" name="precio_mayor[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( intval( $prices['mayor'] ) ); ?>" <?php disabled( ! $can_edit ); ?>></td>
                                    <td><input class="mm-input mm-price-input" type="number" min="0" step="1" name="precio_gran_mayor[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( intval( $prices['gran_mayor'] ) ); ?>" <?php disabled( ! $can_edit ); ?>></td>
                                    <td>
                                        <div class="mm-margin-stack">
                                            <span>Detal: <?php echo esc_html( $this->calculate_margin_percent_safe( $cost, $prices['detal'] ) ); ?>%</span>
                                            <span>Mayor: <?php echo esc_html( $this->calculate_margin_percent_safe( $cost, $prices['mayor'] ) ); ?>%</span>
                                            <span>G. mayor: <?php echo esc_html( $this->calculate_margin_percent_safe( $cost, $prices['gran_mayor'] ) ); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $can_edit ) : ?>
                    <div class="mm-multi-prices-actions">
                        <button type="submit" class="mm-mini-primary">Guardar precios múltiples</button>
                        <span>Regla recomendada: detal ≥ mayor ≥ gran mayor ≥ costo.</span>
                    </div>
                    <div class="mm-multi-prices-msg" hidden></div>
                <?php endif; ?>
            </form>
        </section>
        <?php return ob_get_clean();
    }



    private function mm_get_factura_productos_esperados( $lote_id ) {
        $lote_id = intval( $lote_id );
        $items = get_post_meta( $lote_id, '_mm_factura_productos_esperados', true );
        if ( is_array( $items ) && ! empty( $items ) ) { return $items; }

        $items = get_post_meta( $lote_id, '_mm_pedido_productos_internos_lote', true );
        if ( is_array( $items ) && ! empty( $items ) ) { return $this->build_bodega_safe_expected_products( $items ); }

        $pedido_id = intval( get_post_meta( $lote_id, '_mm_pedido_origen_id', true ) );
        if ( $pedido_id ) {
            $items = get_post_meta( $pedido_id, '_mm_pedido_productos_internos', true );
            if ( is_array( $items ) && ! empty( $items ) ) { return $this->build_bodega_safe_expected_products( $items ); }
        }

        return array();
    }

    private function mm_normalize_expected_products_payload( $json ) {
        if ( empty( $json ) ) { return array(); }
        $decoded = json_decode( wp_unslash( $json ), true );
        if ( ! is_array( $decoded ) ) { return array(); }
        $products = array();
        foreach ( $decoded as $index => $product ) {
            if ( ! is_array( $product ) ) { continue; }
            $products[] = array(
                'item'        => isset( $product['item'] ) ? intval( $product['item'] ) : $index + 1,
                'codigo'      => sanitize_text_field( $product['codigo'] ?? $product['sku'] ?? '' ),
                'nombre'      => sanitize_text_field( $product['nombre'] ?? $product['descripcion'] ?? '' ),
                'iva'         => sanitize_text_field( $product['iva'] ?? '' ),
                'cantidad'    => isset( $product['cantidad'] ) ? intval( $product['cantidad'] ) : 0,
                'costo'       => isset( $product['costo'] ) ? floatval( $product['costo'] ) : 0,
                'valor_total' => isset( $product['valor_total'] ) ? floatval( $product['valor_total'] ) : 0,
            );
        }
        return $products;
    }

    private function mm_get_scanned_qty_by_sku( $lote_id ) {
        $items = $this->item_repo ? $this->item_repo->get_items( intval( $lote_id ) ) : array();
        $map = array();
        foreach ( $items as $item ) {
            $sku = isset( $item->sku ) ? trim( (string) $item->sku ) : '';
            $qty = isset( $item->cantidad_contada ) ? intval( $item->cantidad_contada ) : 0;
            if ( $sku !== '' ) {
                $map[ $sku ] = ( $map[ $sku ] ?? 0 ) + $qty;
            }
        }
        return $map;
    }

    private function render_bodega_factura_expected_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( ! $lote_id ) { return ''; }

        $expected = $this->mm_get_factura_productos_esperados( $lote_id );
        $factura_numero = get_post_meta( $lote_id, '_mm_factura_origen_numero', true );
        $proveedor = get_post_meta( $lote_id, '_mm_factura_origen_proveedor', true );
        $soporte_url = get_post_meta( $lote_id, '_mm_factura_origen_soporte_url', true );

        if ( empty( $expected ) && empty( $factura_numero ) ) { return ''; }

        $scanned = $this->mm_get_scanned_qty_by_sku( $lote_id );
        $total_expected_units = 0;
        $completed = 0;
        $pending = 0;

        foreach ( $expected as $p ) {
            $qty = intval( $p['cantidad'] ?? 0 );
            $code = trim( (string) ( $p['codigo'] ?? '' ) );
            $scan = $code && isset( $scanned[ $code ] ) ? intval( $scanned[ $code ] ) : 0;
            $total_expected_units += $qty;
            if ( $qty > 0 && $scan >= $qty ) { $completed++; } else { $pending++; }
        }

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-expected-panel">
            <div class="mm-section-head">
                <h2>Productos esperados por factura</h2>
                <span>La IA indica lo que debería llegar; bodega confirma lo real.</span>
            </div>

            <div class="mm-bodega-expected-summary">
                <div><small>Factura</small><strong><?php echo esc_html( $factura_numero ?: 'Sin número' ); ?></strong><span><?php echo esc_html( $proveedor ?: 'Proveedor no registrado' ); ?></span></div>
                <div><small>Productos esperados</small><strong><?php echo esc_html( count( $expected ) ); ?></strong><span>Unidades: <?php echo esc_html( $total_expected_units ); ?></span></div>
                <div><small>Completos</small><strong><?php echo esc_html( $completed ); ?></strong><span>Pendientes: <?php echo esc_html( $pending ); ?></span></div>
                <?php if ( $soporte_url ) : ?><div><small>Soporte</small><a class="mm-mini-secondary" href="<?php echo esc_url( $soporte_url ); ?>" target="_blank" rel="noopener">Ver factura</a></div><?php endif; ?>
            </div>

            <?php if ( empty( $expected ) ) : ?>
                <div class="mm-empty-state">Este lote tiene factura asociada, pero todavía no tiene productos esperados detectados por IA.</div>
            <?php else : ?>
                <div class="mm-bodega-expected-table-wrap">
                    <table class="mm-bodega-expected-table">
                        <thead><tr><th>Estado</th><th>Código</th><th>Producto esperado</th><th>Esperado</th><th>Escaneado</th><th>Diferencia</th></tr></thead>
                        <tbody>
                        <?php foreach ( $expected as $p ) :
                            $code = trim( (string) ( $p['codigo'] ?? '' ) );
                            $qty = intval( $p['cantidad'] ?? 0 );
                            $scan = $code && isset( $scanned[ $code ] ) ? intval( $scanned[ $code ] ) : 0;
                            $diff = $scan - $qty;
                            if ( $scan <= 0 ) { $status = 'No escaneado'; $class = 'is-missing'; }
                            elseif ( $diff < 0 ) { $status = 'Faltante'; $class = 'is-warning'; }
                            elseif ( $diff > 0 ) { $status = 'Sobrante'; $class = 'is-extra'; }
                            else { $status = 'Completo'; $class = 'is-ok'; }
                        ?>
                            <tr>
                                <td><span class="mm-expected-status <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $status ); ?></span></td>
                                <td><strong><?php echo esc_html( $code ?: '-' ); ?></strong></td>
                                <td><?php echo esc_html( $p['nombre'] ?? 'Sin nombre' ); ?></td>
                                <td><?php echo esc_html( $qty ); ?></td>
                                <td><?php echo esc_html( $scan ); ?></td>
                                <td><?php echo esc_html( $diff ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }



    private function render_bodega_factura_ia_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( ! $lote_id ) {
            return '';
        }

        $nonce = wp_create_nonce( 'mm_factura_ia' );
        $save_nonce = wp_create_nonce( 'mm_bodega_factura_ia_' . $lote_id );
        $factura_numero = get_post_meta( $lote_id, '_mm_factura_origen_numero', true );
        $proveedor = get_post_meta( $lote_id, '_mm_factura_origen_proveedor', true );
        $total = get_post_meta( $lote_id, '_mm_factura_origen_total', true );

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-ia-panel mm-hide-bodega-ia-factura">
            <div class="mm-section-head">
                <h2>🤖 Analizar factura desde Bodega</h2>
                <span>Sube PDF/foto de factura para detectar productos esperados. Bodega seguirá escaneando normalmente.</span>
            </div>

            <div class="mm-bodega-ia-layout">
                <form class="mm-bodega-ia-analyze-form" enctype="multipart/form-data">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">

                    <label>Factura PDF o foto
                        <input class="mm-input" type="file" name="archivo_ia" accept="application/pdf,image/jpeg,image/png,image/webp" capture="environment">
                    </label>

                    <label>Texto manual opcional
                        <textarea class="mm-input mm-textarea" name="texto_factura" rows="5" placeholder="Pega texto de la factura si el PDF/foto no se lee bien."></textarea>
                    </label>

                    <button type="submit" class="mm-mini-primary">Analizar factura con IA</button>
                    <div class="mm-bodega-ia-msg" hidden></div>
                </form>

                <form class="mm-bodega-ia-result-form">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $save_nonce ); ?>">
                    <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">
                    <input type="hidden" name="productos_detectados_json" value="">

                    <div class="mm-factura-form-grid">
                        <label>Número factura
                            <input class="mm-input" type="text" name="numero_factura" value="<?php echo esc_attr( $factura_numero ); ?>">
                        </label>
                        <label>Proveedor
                            <input class="mm-input" type="text" name="proveedor" value="<?php echo esc_attr( $proveedor ); ?>">
                        </label>
                        <label>Fecha
                            <input class="mm-input" type="date" name="fecha_factura" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
                        </label>
                        <label>Total factura
                            <input class="mm-input" type="number" name="total_factura" min="0" step="1" value="<?php echo esc_attr( $total ); ?>">
                        </label>
                    </div>

                    <label>Observaciones
                        <textarea class="mm-input mm-textarea" name="observaciones_ia" rows="3"></textarea>
                    </label>

                    <div class="mm-factura-products-preview">
                        <strong>Productos detectados por IA</strong>
                        <div class="mm-bodega-ia-products-list">Aún no hay productos detectados.</div>
                    </div>

                    <div class="mm-bodega-ia-actions">
                        <button type="button" class="mm-mini-primary mm-btn-bodega-guardar-factura-ia">Guardar productos esperados en este lote</button>
                        <span>Esto no carga inventario; solo crea una lista de control para comparar contra el escaneo.</span>
                    </div>
                    <div class="mm-bodega-ia-save-msg" hidden></div>
                </form>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    public function ajax_bodega_guardar_factura_ia() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_bodega_panel() && ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para guardar datos de factura en bodega.' ), 403 );
            }

            $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

            if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_bodega_factura_ia_' . $lote_id ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            if ( ! $this->lote_repo->find( $lote_id ) ) {
                wp_send_json_error( array( 'message' => 'Lote no encontrado.' ), 404 );
            }

            $numero = isset( $_POST['numero_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_factura'] ) ) : '';
            $proveedor = isset( $_POST['proveedor'] ) ? sanitize_text_field( wp_unslash( $_POST['proveedor'] ) ) : '';
            $fecha = isset( $_POST['fecha_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha_factura'] ) ) : '';
            $total = isset( $_POST['total_factura'] ) ? floatval( preg_replace( '/[^0-9\.]/', '', wp_unslash( $_POST['total_factura'] ) ) ) : 0;
            $observaciones = isset( $_POST['observaciones_ia'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observaciones_ia'] ) ) : '';
            $products = isset( $_POST['productos_detectados_json'] ) ? $this->mm_normalize_expected_products_payload( $_POST['productos_detectados_json'] ) : array();

            if ( $numero ) {
                update_post_meta( $lote_id, '_mm_factura_origen_numero', $numero );
            }
            if ( $proveedor ) {
                update_post_meta( $lote_id, '_mm_factura_origen_proveedor', $proveedor );
            }
            if ( $fecha ) {
                update_post_meta( $lote_id, '_mm_factura_origen_fecha', $fecha );
            }
            if ( $total > 0 ) {
                update_post_meta( $lote_id, '_mm_factura_origen_total', $total );
            }
            if ( $observaciones ) {
                update_post_meta( $lote_id, '_mm_factura_origen_observaciones', $observaciones );
            }

            if ( ! empty( $products ) ) {
                update_post_meta( $lote_id, '_mm_factura_productos_esperados', $products );
                update_post_meta( $lote_id, '_mm_factura_productos_esperados_count', count( $products ) );
            }

            wp_send_json_success( array(
                'message' => ! empty( $products ) ? 'Productos esperados guardados en Bodega.' : 'Datos de factura guardados. No se recibieron productos detectados.',
                'count' => count( $products ),
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error guardando factura IA en bodega: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => 'No se pudo guardar la información de factura en bodega.',
                'technical' => current_user_can( 'manage_options' ) ? $e->getMessage() : '',
            ), 500 );
        }
    }







    public function ajax_exportar_mekano_lote() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_jefatura_panel() && ! $this->permission_guard->can_access_precios_panel() ) ) { wp_die( 'No tienes permiso para exportar a Mekano.', 403 ); }
            $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
            $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
            if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_exportar_mekano_' . $lote_id ) ) { wp_die( 'Acceso no autorizado.', 403 ); }
            $content = $this->mekano_service->build_mekano_csv_content( $lote_id );
            header( 'Content-Type: text/csv; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="mekano-lote-' . $lote_id . '-' . date( 'Ymd-His' ) . '.csv"' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );
            echo $content;
            exit;
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error exportando Mekano CSV: ' . $e->getMessage() );
            wp_die( 'No se pudo generar el archivo Mekano.', 500 );
        }
    }



    public function ajax_exportar_mekano_xlsx_lote() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_jefatura_panel() && ! $this->permission_guard->can_access_precios_panel() ) ) {
                wp_die( 'No tienes permiso para exportar a Mekano.', 403 );
            }

            $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
            $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

            if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_exportar_mekano_' . $lote_id ) ) {
                wp_die( 'Acceso no autorizado.', 403 );
            }

            if ( ! $this->lote_repo->find( $lote_id ) ) {
                wp_die( 'Lote no encontrado.', 404 );
            }

            $file = $this->mekano_service->build_mekano_xlsx_from_template( $lote_id );
            if ( is_wp_error( $file ) ) {
                wp_die( esc_html( $file->get_error_message() ), 500 );
            }

            if ( ! file_exists( $file ) ) {
                wp_die( 'El archivo XLSX se generó, pero no se encontró en el servidor.', 500 );
            }

            $filename = basename( $file );

            while ( ob_get_level() ) {
                ob_end_clean();
            }

            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Content-Length: ' . filesize( $file ) );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );

            readfile( $file );
            exit;
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error exportando XLSX Mekano: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine() );
            $message = current_user_can( 'manage_options' )
                ? 'Error generando XLSX: ' . $e->getMessage()
                : 'No se pudo generar el XLSX de Mekano.';
            wp_die( esc_html( $message ), 500 );
        }
    }


    private function render_mekano_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para exportar información hacia Mekano.' );
        }

        $lotes = $this->mekano_service->get_mekano_lotes_disponibles();
        $lote_id = $this->mekano_service->get_mekano_lote_actual();
        $items = $lote_id && $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
        $nonce = $lote_id ? wp_create_nonce( 'mm_exportar_mekano_' . $lote_id ) : '';
        $export_url = $lote_id ? admin_url( 'admin-ajax.php?action=mm_app_exportar_mekano_lote&lote_id=' . $lote_id . '&nonce=' . $nonce ) : '';
        $export_xlsx_url = $lote_id ? admin_url( 'admin-ajax.php?action=mm_app_exportar_mekano_xlsx_lote&lote_id=' . $lote_id . '&nonce=' . $nonce ) : '';

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'mekano' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Exportación Mekano</span>
                        <h1>Plantilla de referencias</h1>
                        <p>Genera el archivo usando exactamente la plantilla oficial de Mekano.</p>
                    </div>
                    <?php if ( $export_xlsx_url ) : ?>
                        <div class="mm-mekano-actions">
                            <a class="mm-secondary-action" href="<?php echo esc_url( $export_xlsx_url ); ?>">Descargar XLSX exacto</a>
                            <a class="mm-mini-secondary" href="<?php echo esc_url( $export_url ); ?>">Descargar CSV</a>
                        </div>
                    <?php endif; ?>
                </header>

                <section class="mm-report-filter-card">
                    <div>
                        <h2>Seleccionar lote</h2>
                        <p>El XLSX conserva el documento original. Solo se llenan datos desde la fila 8 en la hoja PLANTILLA.</p>
                    </div>
                    <form class="mm-report-date-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <input type="hidden" name="mm_logistica_app" value="mekano">
                        <label>Lote
                            <select name="lote_id" class="mm-input">
                                <?php foreach ( $lotes as $lote ) : ?>
                                    <option value="<?php echo esc_attr( $lote->ID ); ?>" <?php selected( $lote_id, $lote->ID ); ?>>
                                        #<?php echo esc_html( $lote->ID ); ?> — <?php echo esc_html( get_the_title( $lote->ID ) ); ?> / <?php echo esc_html( $this->status_label( $this->lote_repo->get_status( $lote->ID ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="mm-mini-primary">Ver lote</button>
                    </form>
                </section>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Lote</small><strong>#<?php echo esc_html( $lote_id ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Referencias</small><strong><?php echo esc_html( count( $items ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Formato principal</small><strong>XLSX</strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Plantilla</small><strong>Oficial</strong></div>
                </div>

                
                <?php if ( $export_xlsx_url ) : ?>
                    <section class="mm-platform-section mm-mekano-download-panel">
                        <div class="mm-section-head">
                            <h2>Exportar archivo Mekano</h2>
                            <span>Descarga la plantilla exacta con los productos del lote seleccionado.</span>
                        </div>
                        <div class="mm-mekano-download-actions">
                            <a class="mm-secondary-action" href="<?php echo esc_url( $export_xlsx_url ); ?>">Descargar XLSX exacto</a>
                            <a class="mm-mini-secondary" href="<?php echo esc_url( $export_url ); ?>">Descargar CSV</a>
                        </div>
                    </section>
                <?php endif; ?>

<section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Vista previa</h2>
                        <span>Primeras referencias del lote seleccionado.</span>
                    </div>
                    <div class="mm-mekano-preview-wrap">
                        <table class="mm-mekano-preview-table">
                            <thead><tr><th>CODIGO</th><th>NOMBRE</th><th>LINEA</th><th>UND</th><th>COSTO</th><th>PRECIO</th><th>CATEGORIA</th></tr></thead>
                            <tbody>
                                <?php if ( empty( $items ) ) : ?><tr><td colspan="7">No hay productos para exportar en este lote.</td></tr><?php endif; ?>
                                <?php foreach ( array_slice( $items, 0, 20 ) as $item ) : $v = $this->get_item_mekano_values( $lote_id, $item ); ?>
                                    <tr>
                                        <td><?php echo esc_html( $v['codigo'] ); ?></td>
                                        <td><?php echo esc_html( $v['nombre'] ); ?></td>
                                        <td><?php echo esc_html( $v['linea'] ); ?></td>
                                        <td><?php echo esc_html( $v['unidad'] ); ?></td>
                                        <td>$<?php echo esc_html( number_format( $v['costo'], 0, ',', '.' ) ); ?></td>
                                        <td>$<?php echo esc_html( number_format( $v['precio'], 0, ',', '.' ) ); ?></td>
                                        <td><?php echo esc_html( $v['categoria'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head"><h2>Plantilla exacta</h2><span>Se conserva el documento original.</span></div>
                    <div class="mm-mekano-note-actions">
                        <?php if ( $export_xlsx_url ) : ?>
                            <a class="mm-secondary-action" href="<?php echo esc_url( $export_xlsx_url ); ?>">Descargar XLSX exacto</a>
                            <a class="mm-mini-secondary" href="<?php echo esc_url( $export_url ); ?>">Descargar CSV</a>
                        <?php endif; ?>
                    </div>
                    <div class="mm-safe-note">
                        <strong>Importante:</strong>
                        <p>El exportador usa el XLSX oficial como base. Solo reemplaza datos desde la fila 8 en columnas A:W y conserva hojas auxiliares, formatos y estructura. La columna LINEA queda en blanco por ahora hasta confirmar su uso en Mekano. Si el servidor no permite XLSX, usa CSV como respaldo.</p>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
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


    private function mm_get_exhibicion_items() {
        $items = get_option( 'mm_exhibicion_items', array() );
        return is_array( $items ) ? $items : array();
    }

    private function mm_save_exhibicion_items( $items ) {
        update_option( 'mm_exhibicion_items', array_values( $items ) );
    }

    private function can_access_exhibicion_panel() {
        return is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' ) );
    }

    private function render_exhibicion() {
        if ( ! $this->can_access_exhibicion_panel() ) {
            return method_exists( $this, 'render_access_denied' ) ? $this->render_access_denied() : '<main class="mm-platform-main"><div class="mm-platform-section">No tienes permiso.</div></main>';
        }

        $items = $this->mm_get_exhibicion_items();

        $total_exhibicion = 0;
        $total_bodega = 0;
        $alertas = 0;

        foreach ( $items as $item ) {
            $ex = intval( $item['exhibicion'] ?? 0 );
            $bo = intval( $item['bodega'] ?? 0 );
            $min = intval( $item['minimo'] ?? 0 );
            $total_exhibicion += $ex;
            $total_bodega += $bo;
            if ( $min > 0 && $ex <= $min ) {
                $alertas++;
            }
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'exhibicion' ); ?>
            <section class="mm-platform-main">
            <section class="mm-dashboard-hero">
                <div>
                    <span class="mm-eyebrow">Control físico</span>
                    <h1>Exhibición y Bodega</h1>
                    <p>Registra cuánto producto está en exhibición, cuánto queda guardado y qué se debe reponer.</p>
                </div>
            </section>
            <section class="mm-platform-section">
                <div class="mm-section-head">
                    <h2>Flujo recomendado</h2>
                    <span>La exhibición debe alimentarse desde los productos recibidos en Bodega.</span>
                </div>
                <div class="mm-safe-note">
                    <strong>Uso correcto:</strong>
                    <p>Primero Bodega recibe el lote. Luego se mueve una parte a exhibición para controlar qué está en piso y qué sigue guardado.</p>
                </div>
            </section>


            <section class="mm-kpi-grid">
                <article class="mm-kpi-card">
                    <span>Referencias</span>
                    <strong><?php echo esc_html( count( $items ) ); ?></strong>
                </article>
                <article class="mm-kpi-card">
                    <span>Unidades en exhibición</span>
                    <strong><?php echo esc_html( $total_exhibicion ); ?></strong>
                </article>
                <article class="mm-kpi-card">
                    <span>Unidades en bodega</span>
                    <strong><?php echo esc_html( $total_bodega ); ?></strong>
                </article>
                <article class="mm-kpi-card <?php echo $alertas ? 'is-warning' : ''; ?>">
                    <span>Alertas de reposición</span>
                    <strong><?php echo esc_html( $alertas ); ?></strong>
                </article>
            </section>

            <section class="mm-platform-section">
                <div class="mm-section-head">
                    <h2>Registrar producto en exhibición</h2>
                    <span>Usa esto para crear o actualizar el control físico del producto.</span>
                </div>

                <form class="mm-exhibicion-form">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_exhibicion' ) ); ?>">

                    <div class="mm-exhibicion-form-grid">
                        <label>Código / SKU
                            <input class="mm-input" type="text" name="codigo" placeholder="Código de barras o referencia">
                        </label>
                        <label>Producto
                            <input class="mm-input" type="text" name="producto" placeholder="Nombre del producto">
                        </label>
                        <label>En exhibición
                            <input class="mm-input" type="number" name="exhibicion" min="0" step="1" value="0">
                        </label>
                        <label>En bodega
                            <input class="mm-input" type="number" name="bodega" min="0" step="1" value="0">
                        </label>
                        <label>Mínimo en exhibición
                            <input class="mm-input" type="number" name="minimo" min="0" step="1" value="0">
                        </label>
                        <label>Observación
                            <input class="mm-input" type="text" name="observacion" placeholder="Ubicación, estante, nota">
                        </label>
                    </div>

                    <button type="submit" class="mm-mini-primary">Guardar control</button>
                    <div class="mm-exhibicion-msg" hidden></div>
                </form>
            </section>

            <section class="mm-platform-section">
                <div class="mm-section-head">
                    <h2>Inventario físico</h2>
                    <span>Exhibición + Bodega = total físico registrado.</span>
                </div>

                <?php if ( empty( $items ) ) : ?>
                    <div class="mm-empty-state">Todavía no hay productos registrados en exhibición.</div>
                <?php else : ?>
                    <div class="mm-exhibicion-table-wrap">
                        <table class="mm-exhibicion-table">
                            <thead>
                                <tr>
                                    <th>Estado</th>
                                    <th>Código</th>
                                    <th>Producto</th>
                                    <th>Exhibición</th>
                                    <th>Bodega</th>
                                    <th>Total</th>
                                    <th>Mínimo</th>
                                    <th>Estado</th>
                                    <th>Movimiento rápido</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $items as $index => $item ) :
                                    $ex = intval( $item['exhibicion'] ?? 0 );
                                    $bo = intval( $item['bodega'] ?? 0 );
                                    $min = intval( $item['minimo'] ?? 0 );
                                    $estado = ( $min > 0 && $ex <= $min ) ? 'Reponer' : 'OK';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $item['codigo'] ?? '' ); ?></td>
                                        <td>
                                            <strong><?php echo esc_html( $item['producto'] ?? '' ); ?></strong>
                                            <small><?php echo esc_html( $item['observacion'] ?? '' ); ?></small>
                                        </td>
                                        <td><?php echo esc_html( $ex ); ?></td>
                                        <td><?php echo esc_html( $bo ); ?></td>
                                        <td><?php echo esc_html( $ex + $bo ); ?></td>
                                        <td><?php echo esc_html( $min ); ?></td>
                                        <td><span class="mm-exhibicion-status <?php echo 'Reponer' === $estado ? 'is-warning' : 'is-ok'; ?>"><?php echo esc_html( $estado ); ?></span></td>
                                        <td>
                                            <form class="mm-exhibicion-move-form">
                                                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_exhibicion' ) ); ?>">
                                                <input type="hidden" name="index" value="<?php echo esc_attr( $index ); ?>">
                                                <input class="mm-input" type="number" name="cantidad" min="1" step="1" placeholder="Cant.">
                                                <select class="mm-input" name="direccion">
                                                    <option value="bodega_a_exhibicion">Bodega → Exhibición</option>
                                                    <option value="exhibicion_a_bodega">Exhibición → Bodega</option>
                                                </select>
                                                <button type="submit" class="mm-mini-secondary">Mover</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }

    public function ajax_guardar_exhibicion() {
        try {
            if ( ! $this->can_access_exhibicion_panel() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para editar exhibición.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_exhibicion' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida.' ), 403 );
            }

            $codigo = isset( $_POST['codigo'] ) ? sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) : '';
            $producto = isset( $_POST['producto'] ) ? sanitize_text_field( wp_unslash( $_POST['producto'] ) ) : '';
            $exhibicion = isset( $_POST['exhibicion'] ) ? intval( $_POST['exhibicion'] ) : 0;
            $bodega = isset( $_POST['bodega'] ) ? intval( $_POST['bodega'] ) : 0;
            $minimo = isset( $_POST['minimo'] ) ? intval( $_POST['minimo'] ) : 0;
            $observacion = isset( $_POST['observacion'] ) ? sanitize_text_field( wp_unslash( $_POST['observacion'] ) ) : '';

            if ( '' === $codigo && '' === $producto ) {
                wp_send_json_error( array( 'message' => 'Escribe al menos código o nombre del producto.' ), 400 );
            }

            $items = $this->mm_get_exhibicion_items();
            $found = false;

            foreach ( $items as &$item ) {
                $same_code = '' !== $codigo && isset( $item['codigo'] ) && $item['codigo'] === $codigo;
                $same_name = '' === $codigo && '' !== $producto && strtolower( $item['producto'] ?? '' ) === strtolower( $producto );

                if ( $same_code || $same_name ) {
                    $item['codigo'] = $codigo ?: ( $item['codigo'] ?? '' );
                    $item['producto'] = $producto ?: ( $item['producto'] ?? '' );
                    $item['exhibicion'] = max( 0, $exhibicion );
                    $item['bodega'] = max( 0, $bodega );
                    $item['minimo'] = max( 0, $minimo );
                    $item['observacion'] = $observacion;
                    $item['updated_at'] = current_time( 'mysql' );
                    $found = true;
                    break;
                }
            }
            unset( $item );

            if ( ! $found ) {
                $items[] = array(
                    'codigo' => $codigo,
                    'producto' => $producto,
                    'exhibicion' => max( 0, $exhibicion ),
                    'bodega' => max( 0, $bodega ),
                    'minimo' => max( 0, $minimo ),
                    'observacion' => $observacion,
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                );
            }

            $this->mm_save_exhibicion_items( $items );

            wp_send_json_success( array( 'message' => 'Control de exhibición guardado.' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => 'Error guardando exhibición: ' . $e->getMessage() ), 500 );
        }
    }

    public function ajax_mover_exhibicion() {
        try {
            if ( ! $this->can_access_exhibicion_panel() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para mover inventario.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_exhibicion' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida.' ), 403 );
            }

            $index = isset( $_POST['index'] ) ? intval( $_POST['index'] ) : -1;
            $cantidad = isset( $_POST['cantidad'] ) ? intval( $_POST['cantidad'] ) : 0;
            $direccion = isset( $_POST['direccion'] ) ? sanitize_text_field( wp_unslash( $_POST['direccion'] ) ) : '';

            if ( $cantidad <= 0 ) {
                wp_send_json_error( array( 'message' => 'La cantidad debe ser mayor a 0.' ), 400 );
            }

            $items = $this->mm_get_exhibicion_items();

            if ( ! isset( $items[ $index ] ) ) {
                wp_send_json_error( array( 'message' => 'Producto no encontrado.' ), 404 );
            }

            $ex = intval( $items[ $index ]['exhibicion'] ?? 0 );
            $bo = intval( $items[ $index ]['bodega'] ?? 0 );

            if ( 'bodega_a_exhibicion' === $direccion ) {
                if ( $bo < $cantidad ) {
                    wp_send_json_error( array( 'message' => 'No hay suficiente cantidad en bodega.' ), 400 );
                }
                $bo -= $cantidad;
                $ex += $cantidad;
            } elseif ( 'exhibicion_a_bodega' === $direccion ) {
                if ( $ex < $cantidad ) {
                    wp_send_json_error( array( 'message' => 'No hay suficiente cantidad en exhibición.' ), 400 );
                }
                $ex -= $cantidad;
                $bo += $cantidad;
            } else {
                wp_send_json_error( array( 'message' => 'Movimiento no válido.' ), 400 );
            }

            $items[ $index ]['exhibicion'] = $ex;
            $items[ $index ]['bodega'] = $bo;
            $items[ $index ]['updated_at'] = current_time( 'mysql' );

            $this->mm_save_exhibicion_items( $items );

            wp_send_json_success( array( 'message' => 'Movimiento registrado.' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => 'Error moviendo inventario: ' . $e->getMessage() ), 500 );
        }
    }

    private function mm_get_lote_expected_products_for_bodega( $lote_id ) {
        global $wpdb;

        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) {
            return array();
        }

        $expected = array();

        // 1. Intentar desde tabla de pedidos si existe.
        $tables = array(
            $wpdb->prefix . 'mm_pedidos',
            $wpdb->prefix . 'mm_pedidos_internos',
            $wpdb->prefix . 'mm_lote_pedidos',
        );

        foreach ( $tables as $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists !== $table ) {
                continue;
            }

            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE lote_id = %d OR id_lote = %d ORDER BY id DESC LIMIT 20", $lote_id, $lote_id ), ARRAY_A );
            foreach ( (array) $rows as $row ) {
                foreach ( array( 'productos', 'productos_pedido', 'detalle_productos', 'items', 'metadata' ) as $field ) {
                    if ( empty( $row[ $field ] ) ) {
                        continue;
                    }
                    $decoded = json_decode( $row[ $field ], true );
                    if ( is_array( $decoded ) ) {
                        $expected = isset( $decoded['productos_detectados'] ) ? $decoded['productos_detectados'] : $decoded;
                        break 3;
                    }
                }
            }
        }

        // 2. Intentar desde opción auxiliar si fue guardada por el flujo de pedidos.
        if ( empty( $expected ) ) {
            $option_products = get_option( 'mm_lote_' . $lote_id . '_expected_products', array() );
            if ( is_array( $option_products ) ) {
                $expected = $option_products;
            }
        }

        return is_array( $expected ) ? $expected : array();
    }

    private function mm_render_bodega_expected_products_panel( $lote_id ) {
        $products = $this->mm_get_lote_expected_products_for_bodega( $lote_id );

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-expected-products-panel">
            <div class="mm-section-head">
                <h2>📋 Productos esperados del pedido</h2>
                <span>Esta lista viene del pedido. Bodega solo ve código, producto, cantidad y observación.</span>
            </div>

            <?php if ( empty( $products ) ) : ?>
                <div class="mm-empty-state">Este lote todavía no tiene productos esperados asociados al pedido.</div>
            <?php else : ?>
                <div class="mm-bodega-expected-table-wrap">
                    <table class="mm-bodega-expected-table">
                        <thead>
                            <tr>
                                <th>Código / SKU</th>
                                <th>Producto</th>
                                <th>Cantidad esperada</th>
                                <th>Observación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $products as $product ) :
                                if ( ! is_array( $product ) ) {
                                    continue;
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $product['codigo'] ?? $product['sku'] ?? '' ); ?></td>
                                    <td><strong><?php echo esc_html( $product['nombre'] ?? $product['producto'] ?? $product['descripcion'] ?? '' ); ?></strong></td>
                                    <td><?php echo esc_html( intval( $product['cantidad'] ?? 0 ) ); ?></td>
                                    <td><?php echo esc_html( $product['observacion'] ?? $product['nota'] ?? '' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }



    private function render_exhibicion_dashboard() {
        return $this->render_exhibicion();
    }


    private function mm_get_expected_products_for_selected_lote( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) {
            return array();
        }

        $keys = array(
            'mm_lote_' . $lote_id . '_expected_products',
            'mm_lote_' . $lote_id . '_received_products',
            'mm_lote_expected_products_' . $lote_id,
        );

        foreach ( $keys as $key ) {
            $items = get_option( $key, array() );
            if ( is_array( $items ) && ! empty( $items ) ) {
                return $items;
            }
        }

        $meta_keys = array(
            '_mm_pedido_productos_internos_lote',
            '_mm_productos_esperados',
            '_mm_lote_productos_esperados',
            '_mm_pedido_productos_internos',
        );

        foreach ( $meta_keys as $key ) {
            $items = get_post_meta( $lote_id, $key, true );
            if ( is_array( $items ) && ! empty( $items ) ) {
                return $items;
            }
        }

        return array();
    }

    private function mm_render_bodega_selected_lote_products( $lote_id ) {
        $products = $this->mm_get_expected_products_for_selected_lote( $lote_id );

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-selected-products">
            <div class="mm-section-head">
                <h2>📋 Productos del lote seleccionado</h2>
                <span>Se cargan automáticamente al seleccionar el lote.</span>
            </div>

            <?php if ( empty( $products ) ) : ?>
                <div class="mm-empty-state">Este lote no tiene productos asociados todavía. Revisa que el pedido haya creado el lote con productos.</div>
            <?php else : ?>
                <div class="mm-bodega-selected-table-wrap">
                    <table class="mm-bodega-selected-table">
                        <thead>
                            <tr>
                                <th>Código / SKU</th>
                                <th>Producto</th>
                                <th>Cantidad esperada</th>
                                <th>Observación</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $products as $product ) :
                            if ( ! is_array( $product ) ) { continue; }
                            $codigo = $product['codigo'] ?? $product['sku'] ?? '';
                            $nombre = $product['nombre'] ?? $product['producto'] ?? $product['descripcion'] ?? '';
                            $cantidad = intval( $product['cantidad'] ?? $product['esperado'] ?? $product['recibido'] ?? 0 );
                            $observacion = $product['observacion'] ?? $product['nota'] ?? '';
                            ?>
                            <tr>
                                <td><?php echo esc_html( $codigo ); ?></td>
                                <td><span class="mm-bodega-product-with-image"><?php echo $this->mm_render_bodega_producto_imagen( $product ); ?><strong><?php echo esc_html( $nombre ); ?></strong></span></td>
                                <td><?php echo esc_html( $cantidad ); ?></td>
                                <td><?php echo esc_html( $observacion ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }


    private function mm_get_current_lote_id_from_request() {
        foreach ( array( 'lote_id', 'lote', 'id_lote', 'mm_lote_id' ) as $key ) {
            if ( isset( $_GET[ $key ] ) ) {
                return intval( $_GET[ $key ] );
            }
        }
        return 0;
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


    private function mm_get_productos_sin_imagen() {
        $items = get_option( 'mm_productos_sin_imagen', array() );
        return is_array( $items ) ? $items : array();
    }

    private function mm_save_productos_sin_imagen( $items ) {
        update_option( 'mm_productos_sin_imagen', array_values( $items ) );
    }

    private function mm_registrar_producto_sin_imagen( $codigo, $producto, $origen = 'bodega' ) {
        $codigo = sanitize_text_field( (string) $codigo );
        $producto = sanitize_text_field( (string) $producto );

        if ( '' === $codigo && '' === $producto ) {
            return;
        }

        $items = $this->mm_get_productos_sin_imagen();
        $key = '' !== $codigo ? 'sku:' . $codigo : 'nombre:' . strtolower( $producto );

        foreach ( $items as &$item ) {
            $item_key = ! empty( $item['codigo'] ) ? 'sku:' . $item['codigo'] : 'nombre:' . strtolower( $item['producto'] ?? '' );
            if ( $item_key === $key ) {
                $item['producto'] = $producto ?: ( $item['producto'] ?? '' );
                $item['updated_at'] = current_time( 'mysql' );
                return;
            }
        }
        unset( $item );

        $items[] = array(
            'codigo' => $codigo,
            'producto' => $producto,
            'origen' => sanitize_text_field( $origen ),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        );

        $this->mm_save_productos_sin_imagen( $items );
    }

    private function mm_producto_tiene_imagen_upload() {
        foreach ( array( 'producto_imagen', 'imagen_producto', 'foto_producto', 'producto_foto', 'image', 'foto' ) as $field ) {
            if ( ! empty( $_FILES[ $field ]['name'] ) && ! empty( $_FILES[ $field ]['tmp_name'] ) ) {
                return true;
            }
        }
        return false;
    }

    private function mm_render_productos_sin_imagen_card() {
        $count = count( $this->mm_get_productos_sin_imagen() );
        ob_start(); ?>
        <section class="mm-platform-section mm-no-image-alert">
            <div class="mm-section-head">
                <h2>📷 Productos sin imagen</h2>
                <span>Pendientes para completar catálogo, bodega y exhibición.</span>
            </div>
            <div class="mm-no-image-summary">
                <div>
                    <small>Pendientes</small>
                    <strong><?php echo esc_html( $count ); ?></strong>
                    <span>productos necesitan fotografía</span>
                </div>
                <a class="mm-mini-primary" href="<?php echo esc_url( add_query_arg( 'mm_logistica_app', 'productos_sin_imagen', home_url( '/' ) ) ); ?>">Ver pendientes</a>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    private function render_productos_sin_imagen() {
        $items = $this->mm_get_productos_sin_imagen();
        ob_start(); ?>
        <main class="mm-platform-main">
            <section class="mm-dashboard-hero">
                <div>
                    <span class="mm-eyebrow">Calidad visual</span>
                    <h1>Productos pendientes de fotografía</h1>
                    <p>Productos guardados sin imagen. Puedes completar la foto después sin detener bodega.</p>
                </div>
            </section>
            <section class="mm-platform-section">
                <div class="mm-section-head">
                    <h2>Listado</h2>
                    <span>Marca como resuelto cuando ya tenga imagen.</span>
                </div>
                <?php if ( empty( $items ) ) : ?>
                    <div class="mm-empty-state">No hay productos pendientes de imagen.</div>
                <?php else : ?>
                    <div class="mm-no-image-table-wrap">
                        <table class="mm-no-image-table">
                            <thead><tr><th>Estado</th><th>Código</th><th>Producto</th><th>Origen</th><th>Fecha</th><th>Acción</th></tr></thead>
                            <tbody>
                            <?php foreach ( $items as $index => $item ) : ?>
                                <tr>
                                    <td><span class="mm-image-status is-missing">🟡 Sin imagen</span></td>
                                    <td><?php echo esc_html( $item['codigo'] ?? '' ); ?></td>
                                    <td><strong><?php echo esc_html( $item['producto'] ?? '' ); ?></strong></td>
                                    <td><?php echo esc_html( $item['origen'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( $item['created_at'] ?? '' ); ?></td>
                                    <td><button class="mm-mini-secondary mm-resolver-producto-sin-imagen" data-index="<?php echo esc_attr( $index ); ?>">Marcar resuelto</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php return ob_get_clean();
    }

    public function ajax_resolver_producto_sin_imagen() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'No autorizado.' ), 403 );
        }

        $index = isset( $_POST['index'] ) ? intval( $_POST['index'] ) : -1;
        $items = $this->mm_get_productos_sin_imagen();

        if ( ! isset( $items[ $index ] ) ) {
            wp_send_json_error( array( 'message' => 'Producto no encontrado.' ), 404 );
        }

        unset( $items[ $index ] );
        $this->mm_save_productos_sin_imagen( $items );

        
            if ( ! $this->mm_producto_tiene_imagen_upload() ) {
                $mm_codigo_tmp = isset( $_POST['codigo'] ) ? sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) : ( isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '' );
                $mm_producto_tmp = isset( $_POST['producto'] ) ? sanitize_text_field( wp_unslash( $_POST['producto'] ) ) : ( isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '' );
                $this->mm_registrar_producto_sin_imagen( $mm_codigo_tmp, $mm_producto_tmp, 'bodega' );
            }

wp_send_json_success( array( 'message' => 'Producto marcado como resuelto.' ) );
    }



    private function mm_marcar_lote_pendiente_precios( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) {
            return;
        }

        update_post_meta( $lote_id, '_mm_lote_estado', 'pendiente_precios' );
        update_post_meta( $lote_id, '_mm_precios_estado', 'pendiente' );
        update_post_meta( $lote_id, '_mm_bodega_finalizada', current_time( 'mysql' ) );

        $pendientes = get_option( 'mm_lotes_pendientes_precios', array() );
        if ( ! is_array( $pendientes ) ) {
            $pendientes = array();
        }

        $pendientes[] = $lote_id;
        update_option( 'mm_lotes_pendientes_precios', array_values( array_unique( array_map( 'intval', $pendientes ) ) ) );
    }

    private function mm_get_lotes_pendientes_precios() {
        $pendientes = get_option( 'mm_lotes_pendientes_precios', array() );
        return is_array( $pendientes ) ? array_values( array_unique( array_map( 'intval', $pendientes ) ) ) : array();
    }

    private function mm_get_productos_lote_para_precios( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) {
            return array();
        }

        foreach ( array(
            'mm_lote_' . $lote_id . '_received_products',
            'mm_lote_' . $lote_id . '_expected_products',
            'mm_lote_expected_products_' . $lote_id,
        ) as $key ) {
            $items = get_option( $key, array() );
            if ( is_array( $items ) && ! empty( $items ) ) {
                return $items;
            }
        }

        foreach ( array(
            '_mm_pedido_productos_internos_lote',
            '_mm_lote_productos_esperados',
            '_mm_productos_esperados',
            '_mm_pedido_productos_internos',
        ) as $key ) {
            $items = get_post_meta( $lote_id, $key, true );
            if ( is_array( $items ) && ! empty( $items ) ) {
                return $items;
            }
        }

        return array();
    }

    private function mm_render_precios_pendientes_panel() {
        $lotes = $this->mm_get_lotes_pendientes_precios();

        ob_start(); ?>
        <section class="mm-platform-section mm-precios-pendientes-panel">
            <div class="mm-section-head">
                <h2>🏷️ Lotes pendientes de precios</h2>
                <span>Lotes finalizados por Bodega listos para asignar precio detal, mayor y gran mayor.</span>
            </div>

            <?php if ( empty( $lotes ) ) : ?>
                <div class="mm-empty-state">No hay lotes pendientes de precios.</div>
            <?php else : ?>
                <div class="mm-precios-lotes-grid">
                    <?php foreach ( $lotes as $lote_id ) :
                        $productos = $this->mm_get_productos_lote_para_precios( $lote_id );
                        ?>
                        <article class="mm-precios-lote-card">
                            <small>Lote</small>
                            <strong>#<?php echo esc_html( $lote_id ); ?></strong>
                            <span><?php echo esc_html( count( $productos ) ); ?> producto(s)</span>
                            <a class="mm-mini-primary" href="<?php echo esc_url( add_query_arg( array( 'mm_logistica_app' => 'precios', 'lote_id' => $lote_id ), home_url( '/' ) ) ); ?>">Asignar precios</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }

    private function mm_render_precios_lote_actual_panel() {
        $lote_id = isset( $_GET['lote_id'] ) ? intval( $_GET['lote_id'] ) : 0;
        if ( $lote_id <= 0 ) {
            return '';
        }

        $productos = $this->mm_get_productos_lote_para_precios( $lote_id );

        ob_start(); ?>
        <section class="mm-platform-section mm-precios-lote-actual-panel">
            <div class="mm-section-head">
                <h2>Asignar precios al lote #<?php echo esc_html( $lote_id ); ?></h2>
                <span>Completa precio detal, mayor y gran mayor.</span>
            </div>

            <?php if ( empty( $productos ) ) : ?>
                <div class="mm-empty-state">Este lote no tiene productos cargados para precios.</div>
            <?php else : ?>
                <div class="mm-precios-table-wrap">
                    <table class="mm-precios-table">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Costo</th>
                                <th>Detal</th>
                                <th>Mayor</th>
                                <th>Gran mayor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $productos as $index => $product ) :
                                if ( ! is_array( $product ) ) { continue; }
                                $codigo = $product['codigo'] ?? $product['sku'] ?? '';
                                $nombre = $product['nombre'] ?? $product['producto'] ?? $product['descripcion'] ?? '';
                                $cantidad = intval( $product['recibido'] ?? $product['cantidad'] ?? $product['esperado'] ?? 0 );
                                $costo = floatval( $product['costo'] ?? 0 );
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $codigo ); ?></td>
                                    <td><?php echo $this->mm_render_bodega_img_nombre( $product, $nombre ); ?></td>
                                    <td><?php echo esc_html( $cantidad ); ?></td>
                                    <td><?php echo esc_html( number_format( $costo, 0, ',', '.' ) ); ?></td>
                                    <td><input class="mm-input" type="number" min="0" step="1" name="productos[<?php echo esc_attr( $index ); ?>][precio_detal]"></td>
                                    <td><input class="mm-input" type="number" min="0" step="1" name="productos[<?php echo esc_attr( $index ); ?>][precio_mayor]"></td>
                                    <td><input class="mm-input" type="number" min="0" step="1" name="productos[<?php echo esc_attr( $index ); ?>][precio_gran_mayor]"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }



    private function mm_validar_productos_bodega_para_finalizar( $products ) {
        $errors = array();
        $complete = 0;
        $pending = 0;

        foreach ( (array) $products as $index => $product ) {
            if ( ! is_array( $product ) ) {
                continue;
            }

            $codigo = trim( sanitize_text_field( $product['codigo'] ?? $product['sku'] ?? '' ) );
            $nombre = trim( sanitize_text_field( $product['nombre'] ?? $product['producto'] ?? $product['descripcion'] ?? '' ) );
            $recibido_raw = $product['recibido'] ?? $product['cantidad_recibida'] ?? '';
            $recibido = is_numeric( $recibido_raw ) ? intval( $recibido_raw ) : 0;

            $row_errors = array();

            if ( '' === $codigo ) {
                $row_errors[] = 'SKU vacío';
            }

            if ( '' === $nombre ) {
                $row_errors[] = 'producto vacío';
            }

            if ( $recibido <= 0 ) {
                $row_errors[] = 'cantidad recibida sin confirmar';
            }

            if ( empty( $row_errors ) ) {
                $complete++;
            } else {
                $pending++;
                $errors[] = array(
                    'index' => $index + 1,
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'errores' => $row_errors,
                );
            }
        }

        return array(
            'ok' => empty( $errors ),
            'complete' => $complete,
            'pending' => $pending,
            'errors' => $errors,
        );
    }

    private function mm_build_bodega_validation_message( $validation ) {
        $message = "No se puede finalizar Bodega todavía.\n\n";
        $message .= 'Productos completos: ' . intval( $validation['complete'] ?? 0 ) . "\n";
        $message .= 'Productos pendientes: ' . intval( $validation['pending'] ?? 0 ) . "\n\n";
        $message .= "Corrige estos productos:\n";

        foreach ( array_slice( (array) ( $validation['errors'] ?? array() ), 0, 12 ) as $error ) {
            $label = ! empty( $error['nombre'] ) ? $error['nombre'] : ( ! empty( $error['codigo'] ) ? $error['codigo'] : 'Producto #' . intval( $error['index'] ?? 0 ) );
            $message .= '• ' . $label . ': ' . implode( ', ', (array) $error['errores'] ) . "\n";
        }

        if ( count( (array) ( $validation['errors'] ?? array() ) ) > 12 ) {
            $message .= '• Y más productos pendientes...' . "\n";
        }

        return $message;
    }



    private function mm_render_bodega_producto_imagen( $product ) {
        $url = '';
        if ( is_array( $product ) ) {
            $url = esc_url( $product['imagen_url'] ?? '' );
            if ( empty( $url ) && ! empty( $product['imagen_id'] ) ) {
                $url = esc_url( wp_get_attachment_url( intval( $product['imagen_id'] ) ) );
            }
        }

        if ( empty( $url ) ) {
            return '<span class="mm-bodega-product-img is-empty">Sin imagen</span>';
        }

        return '<span class="mm-bodega-product-img" style="background-image:url(' . esc_url( $url ) . ');"></span>';
    }



    private function mm_get_product_image_from_product( $product ) {
        if ( ! is_array( $product ) ) {
            return '';
        }

        $url = '';
        foreach ( array( 'imagen_url', 'image_url', 'foto_url', 'thumbnail', 'imagen' ) as $key ) {
            if ( ! empty( $product[ $key ] ) && is_string( $product[ $key ] ) ) {
                $url = esc_url_raw( $product[ $key ] );
                break;
            }
        }

        foreach ( array( 'imagen_id', 'image_id', 'attachment_id', 'foto_id' ) as $key ) {
            if ( empty( $url ) && ! empty( $product[ $key ] ) ) {
                $maybe = wp_get_attachment_url( intval( $product[ $key ] ) );
                if ( $maybe ) {
                    $url = esc_url_raw( $maybe );
                    break;
                }
            }
        }

        return $url;
    }

    private function mm_render_bodega_img_nombre( $product, $nombre ) {
        $url = $this->mm_get_product_image_from_product( $product );
        $nombre = esc_html( $nombre );

        if ( empty( $url ) ) {
            return '<span class="mm-bodega-name-with-img"><span class="mm-bodega-img-mini is-empty">Sin imagen</span><strong>' . $nombre . '</strong></span>';
        }

        return '<span class="mm-bodega-name-with-img"><span class="mm-bodega-img-mini" style="background-image:url(' . esc_url( $url ) . ');"></span><strong>' . $nombre . '</strong></span>';
    }


    private function render_sidebar( $active ) {
        $items = array(
            'dashboard' => array( 'label' => 'Dashboard', 'icon' => '🏠', 'url' => home_url( '/?mm_logistica_app=dashboard' ) ),
            'pedidos' => array( 'label' => 'Pedidos', 'icon' => '🛒', 'url' => home_url( '/?mm_logistica_app=pedidos' ) ),
            'notificaciones' => array( 'label' => 'Notificaciones', 'icon' => '🔔', 'url' => home_url( '/?mm_logistica_app=notificaciones' ) ),
            'reportes' => array( 'label' => 'Reportes', 'icon' => '📈', 'url' => home_url( '/?mm_logistica_app=reportes' ) ),
            'productos-nuevos' => array( 'label' => 'Productos nuevos', 'icon' => '🆕', 'url' => home_url( '/?mm_logistica_app=productos-nuevos' ) ),
            'sincronizacion' => array( 'label' => 'Sincronización', 'icon' => '🔁', 'url' => home_url( '/?mm_logistica_app=sincronizacion' ) ),
            'usuarios' => array( 'label' => 'Usuarios', 'icon' => '👥', 'url' => home_url( '/?mm_logistica_app=usuarios' ) ),
            'historial' => array( 'label' => 'Historial', 'icon' => '🧾', 'url' => home_url( '/?mm_logistica_app=historial' ) ),
            'facturas' => array( 'label' => 'Facturas', 'icon' => '🧾', 'url' => home_url( '/?mm_logistica_app=facturas' ) ),
            'mekano' => array( 'label' => 'Mekano', 'icon' => '📤', 'url' => home_url( '/?mm_logistica_app=mekano' ) ),
            'sistema' => array( 'label' => 'Sistema', 'icon' => '🛡️', 'url' => home_url( '/?mm_logistica_app=sistema' ) ),
            'exhibicion' => array( 'label' => 'Exhibición', 'icon' => '🧺', 'url' => home_url( '/?mm_logistica_app=exhibicion' ) ),
        );
        if ( $this->permission_guard->can_access_bodega_panel() ) {
            $items['bodega'] = array( 'label' => 'Bodega', 'icon' => '📦', 'url' => home_url( '/?mm_logistica_app=bodega' ) );
            $items['exhibicion'] = array( 'label' => 'Exhibición', 'icon' => '🧺', 'url' => home_url( '/?mm_logistica_app=exhibicion' ) );
        }
        if ( $this->permission_guard->can_access_precios_panel() ) {
            $items['precios'] = array( 'label' => 'Precios', 'icon' => '🏷️', 'url' => home_url( '/?mm_logistica_app=precios' ) );
        }
        if ( $this->permission_guard->can_access_jefatura_panel() ) {
            $items['jefatura'] = array( 'label' => 'Jefatura', 'icon' => '📊', 'url' => home_url( '/?mm_logistica_app=jefatura' ) );
        }
        if ( $this->permission_guard->can_access_precios_panel() || $this->permission_guard->can_access_jefatura_panel() ) {
            $items['etiquetas'] = array( 'label' => 'Etiquetas', 'icon' => '🏷️', 'url' => home_url( '/?mm_logistica_app=etiquetas' ) );
        }

        ob_start(); ?>
        <aside class="mm-platform-sidebar">
            <div class="mm-platform-brand">
                <div class="mm-brand-mark">M</div>
                <div><strong>MegaMundo</strong><span>Logística</span></div>
            </div>
            <nav class="mm-platform-nav">
                <?php foreach ( $items as $key => $item ) : ?>
                    <a class="<?php echo $active === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>">
                        <span><?php echo esc_html( $item['icon'] ); ?></span><?php echo esc_html( $item['label'] ); ?>
                    </a>
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

    private function render_precios_dashboard() {
        $pendientes_precios = $this->lote_repo->find_by_status( 'mm_p_precios' );
        $conteo             = $this->lote_repo->find_by_status( 'draft' );
        $publicados         = $this->lote_repo->find_by_status( 'publish' );

        // En el panel de precios deben aparecer los lotes que aún necesitan liquidación:
        // borrador/en conteo, pendiente de precios y publicados que todavía no estén cargados.
        $lotes_para_liquidar = array();
        foreach ( array_merge( $pendientes_precios, $conteo, $publicados ) as $lote ) {
            if ( ! isset( $lote->ID ) ) {
                continue;
            }
            $lotes_para_liquidar[ intval( $lote->ID ) ] = $lote;
        }
        $pendientes = array_values( $lotes_para_liquidar );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'precios' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header">
                    <div><span class="mm-eyebrow">Panel de Precios</span><h1>Liquidación de mercancía</h1><p>Revisa lotes cerrados por bodega, completa costos, precios y márgenes antes de enviarlos a jefatura.</p></div>
                </header>
                <div class="mm-role-summary-grid">
                    <div class="mm-role-summary-card"><small>Por liquidar</small><strong><?php echo esc_html( count( $pendientes ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>En borrador</small><strong><?php echo esc_html( count( $conteo ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Pendiente precios</small><strong><?php echo esc_html( count( $pendientes_precios ) ); ?></strong></div>
                </div>
                <section class="mm-platform-section">
                    <div class="mm-section-head"><h2>Lotes para liquidar</h2><span>Aquí aparecen lotes en borrador, publicados o pendientes de precios para asignar costos y precios.</span></div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $pendientes ) ) : ?><div class="mm-empty-state">No hay lotes disponibles para liquidar.</div><?php endif; ?>
                        <?php foreach ( $pendientes as $lote ) { echo $this->render_lote_card( $lote, 'precios' ); } ?>
                    </div>
                </section>
            </section>
        </main>
                        <?php
                $selected_lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
                if ( $selected_lote_id ) {
                    echo $this->render_multi_prices_panel( $selected_lote_id );
                }
                ?>
        <?php return ob_get_clean();
    }


    private function get_lotes_listos_para_etiquetas() {
        $cargados = $this->lote_repo->find_by_status( 'mm_cargado' );
        $publicados = $this->lote_repo->find_by_status( 'publish' );
        $lotes = array();

        foreach ( array_merge( $cargados, $publicados ) as $lote ) {
            if ( ! isset( $lote->ID ) ) {
                continue;
            }
            $lote_id = intval( $lote->ID );
            if ( $this->lote_repo->is_synchronized( $lote_id ) || 'mm_cargado' === $this->lote_repo->get_status( $lote_id ) ) {
                $lotes[ $lote_id ] = $lote;
            }
        }

        return array_values( $lotes );
    }


    private function get_etiquetas_item_statuses( $lote_id ) {
        $raw = get_post_meta( intval( $lote_id ), '_mm_etiquetas_items_status', true );
        return is_array( $raw ) ? $raw : array();
    }

    private function get_etiqueta_item_status( $lote_id, $item_id ) {
        $statuses = $this->get_etiquetas_item_statuses( $lote_id );
        $item_id = intval( $item_id );
        return isset( $statuses[ $item_id ] ) && is_array( $statuses[ $item_id ] ) ? $statuses[ $item_id ] : array(
            'status' => 'pendiente',
            'printed_at' => '',
            'printed_by' => 0,
            'print_count' => 0,
        );
    }

    public function ajax_marcar_etiqueta_impresa_app() {
        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! $item_id || ! wp_verify_nonce( $nonce, 'mm_app_etiquetas_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para marcar etiquetas.' ), 403 );
        }

        $items = $this->item_repo ? $this->item_repo->get_by_lote( $lote_id ) : array();
        $exists = false;
        foreach ( $items as $item ) {
            if ( intval( $item->id ) === $item_id ) {
                $exists = true;
                break;
            }
        }

        if ( ! $exists ) {
            wp_send_json_error( array( 'message' => 'El producto no existe dentro de este lote.' ), 404 );
        }

        $statuses = $this->get_etiquetas_item_statuses( $lote_id );
        $prev = isset( $statuses[ $item_id ] ) && is_array( $statuses[ $item_id ] ) ? $statuses[ $item_id ] : array();
        $count = isset( $prev['print_count'] ) ? intval( $prev['print_count'] ) + 1 : 1;

        $statuses[ $item_id ] = array(
            'status'      => $count > 1 ? 'reimpreso' : 'impreso',
            'printed_at'  => current_time( 'mysql' ),
            'printed_by'  => get_current_user_id(),
            'print_count' => $count,
        );

        update_post_meta( $lote_id, '_mm_etiquetas_items_status', $statuses );

        wp_send_json_success( array(
            'message' => $count > 1 ? 'Reimpresión registrada.' : 'Producto marcado como impreso.',
            'status' => $statuses[ $item_id ]['status'],
            'print_count' => $count,
        ) );
    }

    private function render_etiqueta_card( $lote ) {
        $lote_id = intval( $lote->ID );
        $items = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
        $units = 0;
        foreach ( $items as $item ) {
            $units += intval( $item->cantidad_contada );
        }
        $print_url = wp_nonce_url( home_url( '/?imprimir_tickets_lote=' . $lote_id ), 'imprimir_tickets_' . $lote_id );
        $etiquetas_nonce = wp_create_nonce( 'mm_app_etiquetas_' . $lote_id );
        $status_items = $this->get_etiquetas_item_statuses( $lote_id );
        $printed_count = 0;
        foreach ( $items as $status_item ) {
            $tmp_status = $this->get_etiqueta_item_status( $lote_id, intval( $status_item->id ) );
            if ( ! empty( $tmp_status['status'] ) && 'pendiente' !== $tmp_status['status'] ) {
                $printed_count++;
            }
        }
        $detail_url = home_url( '/?mm_logistica_app=jefatura&lote_id=' . $lote_id );
        if ( ! $this->permission_guard->can_access_jefatura_panel() ) {
            $detail_url = home_url( '/?mm_logistica_app=precios&lote_id=' . $lote_id );
        }
        ob_start(); ?>
        <article class="mm-platform-lote-card mm-label-lote-card">
            <div class="mm-lote-card-top">
                <span class="mm-badge-soft">Listo para imprimir</span>
                <small>Lote #<?php echo esc_html( $lote_id ); ?></small>
            </div>
            <h3><?php echo esc_html( get_the_title( $lote_id ) ); ?></h3>
            <div class="mm-lote-card-metrics">
                <span><strong><?php echo esc_html( count( $items ) ); ?></strong><small>SKUs</small></span>
                <span><strong><?php echo esc_html( $units ); ?></strong><small>Etiquetas aprox.</small></span>
                <span><strong><?php echo esc_html( $this->status_label( $this->lote_repo->get_status( $lote_id ) ) ); ?></strong><small>Estado</small></span>
                <span><strong><?php echo esc_html( $printed_count ); ?>/<?php echo esc_html( count( $items ) ); ?></strong><small>Productos impresos</small></span>
            </div>
            <div class="mm-lote-card-actions">
                <a class="mm-mini-primary" href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener">Imprimir lote completo</a>
                <a class="mm-mini-secondary" href="<?php echo esc_url( $detail_url ); ?>">Revisar lote</a>
            </div>

            <?php if ( ! empty( $items ) ) : ?>
                <div class="mm-product-print-list">
                    <h4>Imprimir producto por producto</h4>
                    <?php foreach ( $items as $item ) :
                        $product_name = $item->producto_id ? get_the_title( (int) $item->producto_id ) : '';
                        if ( ! $product_name ) { $product_name = 'Producto sin nombre'; }
                        $qty = intval( $item->cantidad_contada );
                        $item_print_url = wp_nonce_url( home_url( '/?imprimir_tickets_lote=' . $lote_id . '&item_id=' . intval( $item->id ) ), 'imprimir_tickets_' . $lote_id );
                    ?>
                        <?php
                            $print_status = $this->get_etiqueta_item_status( $lote_id, intval( $item->id ) );
                            $status_label = ! empty( $print_status['status'] ) ? $print_status['status'] : 'pendiente';
                            $print_count = ! empty( $print_status['print_count'] ) ? intval( $print_status['print_count'] ) : 0;
                        ?>
                        <div class="mm-product-print-row" data-lote-id="<?php echo esc_attr( $lote_id ); ?>" data-item-id="<?php echo esc_attr( (int) $item->id ); ?>" data-nonce="<?php echo esc_attr( $etiquetas_nonce ); ?>">
                            <div>
                                <strong><?php echo esc_html( $product_name ); ?></strong>
                                <small>SKU: <?php echo esc_html( $item->sku ); ?> · <?php echo esc_html( $qty ); ?> etiqueta(s)</small>
                                <span class="mm-print-status mm-print-status-<?php echo esc_attr( $status_label ); ?>">
                                    <?php echo esc_html( ucfirst( $status_label ) ); ?><?php echo $print_count > 1 ? ' · ' . esc_html( $print_count ) . ' veces' : ''; ?>
                                </span>
                            </div>
                            <div class="mm-product-print-actions">
                                <a class="mm-mini-secondary" href="<?php echo esc_url( $item_print_url ); ?>" target="_blank" rel="noopener"><?php echo 'pendiente' === $status_label ? 'Imprimir todo este producto' : 'Reimprimir todo'; ?></a>
                                <div class="mm-custom-print">
                                    <input type="number" min="1" max="<?php echo esc_attr( max( 1, $qty ) ); ?>" value="<?php echo esc_attr( $qty ); ?>" class="mm-custom-print-qty" aria-label="Cantidad personalizada de etiquetas">
                                    <button type="button" class="mm-mini-secondary mm-btn-print-custom" data-print-base="<?php echo esc_url( $item_print_url ); ?>">Imprimir cantidad</button>
                                </div>
                                <button type="button" class="mm-mini-primary mm-btn-marcar-impreso"><?php echo 'pendiente' === $status_label ? 'Marcar impreso' : 'Registrar reimpresión'; ?></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php return ob_get_clean();
    }

    private function render_etiquetas_dashboard() {
        $lotes = $this->get_lotes_listos_para_etiquetas();
        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'etiquetas' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header">
                    <div>
                        <span class="mm-eyebrow">Panel de Etiquetas</span>
                        <h1>Impresión de tickets y etiquetas</h1>
                        <p>Cuando jefatura aprueba un lote, el sistema lo carga a WooCommerce y aquí queda listo para imprimir etiquetas con el precio final.</p>
                    </div>
                </header>
                <div class="mm-role-summary-grid">
                    <div class="mm-role-summary-card is-success"><small>Lotes listos</small><strong><?php echo esc_html( count( $lotes ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Regla</small><strong>Solo cargados</strong></div>
                    <div class="mm-role-summary-card"><small>Precio usado</small><strong>Precio final</strong></div>
                </div>
                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Lotes listos para impresión</h2>
                        <span>Solo aparecen lotes aprobados/cargados o sincronizados. No se imprimen etiquetas antes de aprobación.</span>
                    </div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $lotes ) ) : ?>
                            <div class="mm-empty-state">Aún no hay lotes aprobados listos para imprimir etiquetas.</div>
                        <?php endif; ?>
                        <?php foreach ( $lotes as $lote ) { echo $this->render_etiqueta_card( $lote ); } ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }


    private function get_lotes_jefatura_en_proceso() {
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( ! isset( $lote->ID ) ) {
                    continue;
                }

                $lote_id = intval( $lote->ID );

                // Si ya fue sincronizado, no debe aparecer como pendiente/en proceso.
                if ( $this->lote_repo->is_synchronized( $lote_id ) || 'mm_cargado' === $this->lote_repo->get_status( $lote_id ) ) {
                    continue;
                }

                $lotes[ $lote_id ] = $lote;
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_lotes_jefatura_por_aprobar() {
        $lotes = array();

        foreach ( $this->lote_repo->find_by_status( 'mm_p_aprobacion' ) as $lote ) {
            if ( ! isset( $lote->ID ) ) {
                continue;
            }

            $lote_id = intval( $lote->ID );
            if ( ! $this->lote_repo->is_synchronized( $lote_id ) ) {
                $lotes[ $lote_id ] = $lote;
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function render_jefatura_dashboard() {
        $counts = $this->get_lote_counts();
        $pendientes = $this->get_lotes_jefatura_por_aprobar();
        $en_proceso = $this->get_lotes_jefatura_en_proceso();
        $cargados   = array_slice( $this->lote_repo->find_by_status( 'mm_cargado' ), 0, 6 );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'jefatura' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-jefatura-hero">
                    <div>
                        <span class="mm-eyebrow">Panel de Jefatura</span>
                        <h1>Control logístico general</h1>
                        <p>Supervisa todos los lotes activos: en conteo, precios, pendientes de aprobación y cargados.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>En conteo</small><strong><?php echo esc_html( $counts['draft'] ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>En precios</small><strong><?php echo esc_html( $counts['mm_p_precios'] ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Por aprobar</small><strong><?php echo esc_html( count( $pendientes ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Cargados</small><strong><?php echo esc_html( $counts['mm_cargado'] ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Pendientes de aprobación</h2>
                        <span>Aquí aparecen los lotes que Precios ya envió para aprobación final.</span>
                    </div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $pendientes ) ) : ?><div class="mm-empty-state">No hay lotes pendientes de aprobación.</div><?php endif; ?>
                        <?php foreach ( $pendientes as $lote ) { echo $this->render_lote_card( $lote, 'jefatura' ); } ?>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Todos los lotes en proceso</h2>
                        <span>Vista de control para jefatura: borrador, publicados, en precios y por aprobar.</span>
                    </div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $en_proceso ) ) : ?><div class="mm-empty-state">No hay lotes activos en proceso.</div><?php endif; ?>
                        <?php foreach ( $en_proceso as $lote ) { echo $this->render_lote_card( $lote, 'jefatura' ); } ?>
                    </div>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Últimos lotes cargados</h2>
                        <span>Consulta etiquetas, CSV, bitácora y sincronización.</span>
                    </div>
                    <div class="mm-platform-card-grid">
                        <?php if ( empty( $cargados ) ) : ?><div class="mm-empty-state">Aún no hay lotes cargados.</div><?php endif; ?>
                        <?php foreach ( $cargados as $lote ) { echo $this->render_lote_card( $lote, 'jefatura' ); } ?>
                    </div>
                </section>
            </section>
        </main>
                        <?php
                $selected_lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
                if ( $selected_lote_id ) {
                    echo $this->render_multi_prices_panel( $selected_lote_id );
                }
                ?>
        <?php return ob_get_clean();
    }



    private function price_app_allowed_statuses() {
        return array( 'draft', 'publish', 'mm_p_precios' );
    }

    private function user_can_edit_prices_in_app( $lote_id ) {
        if ( $this->lote_repo->is_synchronized( $lote_id ) ) {
            return false;
        }

        $status = $this->lote_repo->get_status( $lote_id );

        // Precios: liquida costos y precio propuesto antes de aprobación.
        if ( $this->permission_guard->can_edit_finance() && in_array( $status, $this->price_app_allowed_statuses(), true ) ) {
            return true;
        }

        // Jefatura: puede ajustar el precio final antes de aprobar/cargar.
        if ( $this->permission_guard->is_admin() && 'mm_p_aprobacion' === $status ) {
            return true;
        }

        return false;
    }

    private function user_can_send_to_approval_in_app( $lote_id ) {
        if ( ! $this->permission_guard->can_edit_finance() || $this->permission_guard->is_admin() ) {
            return false;
        }
        $status = $this->lote_repo->get_status( $lote_id );
        return in_array( $status, $this->price_app_allowed_statuses(), true ) && ! $this->lote_repo->is_synchronized( $lote_id );
    }

    public function ajax_guardar_precios_app() {
        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_app_precios_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            wp_send_json_error( array( 'message' => 'El lote no existe.' ), 404 );
        }

        if ( ! $this->user_can_edit_prices_in_app( $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'No puedes editar precios en este lote o el lote ya fue cargado.' ), 403 );
        }

        $items_post = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? $_POST['items'] : array();
        if ( empty( $items_post ) ) {
            wp_send_json_error( array( 'message' => 'No se recibieron productos para actualizar.' ), 400 );
        }

        $old_items = $this->item_repo->get_by_lote( $lote_id );
        $old_by_id = array();
        foreach ( $old_items as $old_item ) {
            $old_by_id[ intval( $old_item->id ) ] = $old_item;
        }

        $updated = 0;
        foreach ( $items_post as $item_id => $values ) {
            $item_id = absint( $item_id );
            if ( ! isset( $old_by_id[ $item_id ] ) ) {
                continue;
            }
            $costo  = isset( $values['costo_ia'] ) ? max( 0, floatval( $values['costo_ia'] ) ) : 0;
            $precio = isset( $values['precio_propuesto'] ) ? max( 0, floatval( $values['precio_propuesto'] ) ) : 0;

            $old = $old_by_id[ $item_id ];
            $old_costo = floatval( $old->costo_ia );
            $old_precio = floatval( $old->precio_propuesto );

            if ( abs( $old_costo - $costo ) > 0.0001 ) {
                if ( property_exists( $this, 'audit_repo' ) && $this->audit_repo ) {
                    $this->audit_repo->add_log( $lote_id, 'costo_modificado', sprintf( 'Costo del producto SKU %s modificado desde la plataforma', $old->sku ), $item_id, $old->producto_id, $old->sku, $old_costo, $costo );
                }
            }
            if ( abs( $old_precio - $precio ) > 0.0001 ) {
                if ( property_exists( $this, 'audit_repo' ) && $this->audit_repo ) {
                    $this->audit_repo->add_log( $lote_id, 'precio_modificado', sprintf( 'Precio propuesto del producto SKU %s modificado desde la plataforma', $old->sku ), $item_id, $old->producto_id, $old->sku, $old_precio, $precio );
                }
            }
            $this->item_repo->update_prices( $item_id, $lote_id, $costo, $precio );
            $updated++;
        }

        wp_send_json_success( array( 'message' => 'Precios guardados correctamente.', 'updated' => $updated ) );
    }

    public function ajax_enviar_aprobacion_app() {
        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_app_precios_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }
        if ( ! $this->user_can_edit_prices_in_app( $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para enviar este lote a aprobación.' ), 403 );
        }
        if ( $this->item_repo->count_distinct_items( $lote_id ) === 0 ) {
            wp_send_json_error( array( 'message' => 'No puedes enviar un lote sin productos.' ), 400 );
        }
        $sin_precio = $this->item_repo->count_items_without_price( $lote_id );
        if ( $sin_precio > 0 ) {
            wp_send_json_error( array( 'message' => 'Faltan precios en ' . $sin_precio . ' producto(s).' ), 400 );
        }

        $this->lote_repo->update_status( $lote_id, 'mm_p_aprobacion' );
        wp_send_json_success( array( 'message' => 'Lote enviado a aprobación correctamente.' ) );
    }


    public function ajax_aprobar_cargar_app() {
        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_app_precios_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        if ( ! $this->permission_guard->is_admin() ) {
            wp_send_json_error( array( 'message' => 'Solo jefatura puede aprobar y cargar el lote.' ), 403 );
        }

        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            wp_send_json_error( array( 'message' => 'El lote no existe.' ), 404 );
        }

        $status = $this->lote_repo->get_status( $lote_id );
        if ( ! in_array( $status, array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Este lote no está disponible para autorización de precio.' ), 400 );
        }

        if ( $this->lote_repo->is_synchronized( $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Este lote ya fue cargado anteriormente.' ), 400 );
        }

        if ( $this->item_repo->count_distinct_items( $lote_id ) === 0 ) {
            wp_send_json_error( array( 'message' => 'No puedes aprobar un lote sin productos.' ), 400 );
        }

        $sin_precio = $this->item_repo->count_items_without_price( $lote_id );
        if ( $sin_precio > 0 ) {
            wp_send_json_error( array( 'message' => 'Faltan precios en ' . $sin_precio . ' producto(s).' ), 400 );
        }

        // Primero guarda cualquier precio final ajustado por jefatura.
        if ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ) {
            foreach ( $_POST['items'] as $item_id => $values ) {
                $item_id = absint( $item_id );
                $costo  = isset( $values['costo_ia'] ) ? max( 0, floatval( $values['costo_ia'] ) ) : 0;
                $precio = isset( $values['precio_propuesto'] ) ? max( 0, floatval( $values['precio_propuesto'] ) ) : 0;
                if ( $item_id > 0 ) {
                    $this->item_repo->update_prices( $item_id, $lote_id, $costo, $precio );
                }
            }
        }

        update_post_meta( $lote_id, '_mm_jefatura_aprobado_at', current_time( 'mysql' ) );
        update_post_meta( $lote_id, '_mm_jefatura_aprobado_by', get_current_user_id() );

        $updated = $this->lote_repo->update_status( $lote_id, 'mm_cargado' );
        if ( ! $updated ) {
            wp_send_json_error( array( 'message' => 'No se pudo cambiar el lote a cargado.' ), 500 );
        }

        wp_send_json_success( array(
            'message' => 'Precio autorizado y lote enviado a carga. Cuando termine la sincronización aparecerá en Etiquetas.',
            'redirect' => home_url( '/?mm_logistica_app=etiquetas' ),
        ) );
    }

    private function render_checklist_block( $lote_id ) {
        $checklist = get_post_meta( $lote_id, '_mm_lote_checklist', true );
        $checklist = is_array( $checklist ) ? $checklist : array();
        $labels = ChecklistService::LABELS;
        ob_start(); ?>
        <div class="mm-detail-card">
            <div class="mm-detail-card-head"><h3>Checklist del lote</h3><span>Seguimiento interno</span></div>
            <ul class="mm-checklist-view">
                <?php foreach ( ChecklistService::ALL_ITEMS as $key ) : ?>
                    <li class="<?php echo ! empty( $checklist[ $key ] ) ? 'is-done' : 'is-pending'; ?>">
                        <span><?php echo ! empty( $checklist[ $key ] ) ? '✔' : '•'; ?></span>
                        <small><?php echo esc_html( $labels[ $key ] ?? $key ); ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php return ob_get_clean();
    }


    private function get_product_image_url( $product_id ) {
        $product_id = intval( $product_id );
        if ( $product_id <= 0 ) {
            return '';
        }

        $thumb_url = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
        if ( $thumb_url ) {
            return $thumb_url;
        }

        $attachments = get_children( array(
            'post_parent'    => $product_id,
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'numberposts'    => 1,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ) );

        if ( ! empty( $attachments ) ) {
            $attachment = reset( $attachments );
            $url = wp_get_attachment_image_url( $attachment->ID, 'thumbnail' );
            return $url ? $url : '';
        }

        return '';
    }

    private function render_lote_detail_app( $app, $lote_id ) {
        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            return $this->render_denied_app( 'El lote solicitado no existe.' );
        }
        $status = $this->lote_repo->get_status( $lote_id );
        $items  = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
        $totals = $this->item_repo ? $this->item_repo->get_financial_totals( $lote_id ) : array( 'costo_total' => 0, 'venta_total' => 0, 'margen_total' => 0, 'margen_pct' => 0 );
        $back_url = home_url( '/?mm_logistica_app=' . $app );
        $admin_url = admin_url( 'post.php?post=' . $lote_id . '&action=edit' );
        $can_edit_prices = $this->user_can_edit_prices_in_app( $lote_id );
        $can_send_to_approval = ( 'precios' === $app ) && $this->user_can_send_to_approval_in_app( $lote_id );
        $can_approve_load = (
            'jefatura' === $app
            && $this->permission_guard->is_admin()
            && in_array( $status, array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion' ), true )
            && ! $this->lote_repo->is_synchronized( $lote_id )
        );
        $is_jefatura_price_review = ( 'jefatura' === $app && $can_edit_prices );
        $price_nonce = wp_create_nonce( 'mm_app_precios_' . $lote_id );
        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( $app ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-detail-header">
                    <div>
                        <span class="mm-eyebrow"><?php echo esc_html( 'precios' === $app ? 'Panel de Precios' : 'Panel de Jefatura' ); ?></span>
                        <h1><?php echo esc_html( get_the_title( $lote_id ) ); ?></h1>
                        <p>Vista interna del lote dentro de la plataforma. Aquí puedes revisar el conteo, precios y estado sin salir del panel.</p>
                    </div>
                    <div class="mm-detail-actions">
                        <a class="mm-header-link" href="<?php echo esc_url( $back_url ); ?>">← Volver</a>
                        <a class="mm-secondary-action" href="<?php echo esc_url( $admin_url ); ?>">Abrir editor completo</a>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Estado</small><strong><?php echo esc_html( $this->status_label( $status ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>SKUs</small><strong><?php echo esc_html( count( $items ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Unidades</small><strong><?php echo esc_html( $this->item_repo ? $this->item_repo->sum_total_units( $lote_id ) : 0 ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Venta propuesta</small><strong>$<?php echo esc_html( number_format( (float) $totals['venta_total'], 0, ',', '.' ) ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2><?php echo $is_jefatura_price_review ? 'Revisión de precio final' : ( $can_edit_prices ? 'Liquidar costos y precios' : 'Productos del lote' ); ?></h2>
                        <span><?php echo $is_jefatura_price_review ? 'Si jefatura no está de acuerdo, puede ajustar el precio que quedará en WooCommerce.' : ( $can_edit_prices ? 'Edita aquí. No necesitas entrar a WooCommerce.' : 'Lote #' . esc_html( $lote_id ) ); ?></span>
                    </div>
                    <div class="mm-detail-card mm-table-card">
                        <?php if ( empty( $items ) ) : ?>
                            <div class="mm-empty-state">Este lote aún no tiene productos registrados.</div>
                        <?php else : ?>
                            <form id="mm-app-precios-form" data-lote-id="<?php echo esc_attr( $lote_id ); ?>" data-nonce="<?php echo esc_attr( $price_nonce ); ?>">
                                <div class="mm-table-scroll">
                                    <table class="mm-detail-table mm-price-table">
                                        <thead><tr><th>Foto</th><th>SKU</th><th>Producto</th><th>Cantidad</th><th>Costo unitario</th><th><?php echo $is_jefatura_price_review ? 'Precio final' : 'Precio propuesto'; ?></th><th>Margen</th><th>Sync</th></tr></thead>
                                        <tbody>
                                        <?php foreach ( $items as $item ) :
                                            $producto_id = (int) $item->producto_id;
                                            $nombre = $producto_id ? get_the_title( $producto_id ) : '';
                                            if ( ! $nombre ) { $nombre = 'Producto sin nombre'; }
                                            $thumb_url = $this->get_product_image_url( $producto_id );
                                            $costo = (float) $item->costo_ia;
                                            $precio = (float) $item->precio_propuesto;
                                            $margen = $precio - $costo;
                                        ?>
                                            <tr data-item-id="<?php echo esc_attr( (int) $item->id ); ?>">
                                                <td>
                                                    <?php if ( $thumb_url ) : ?>
                                                        <img class="mm-product-thumb" src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $nombre ); ?>" />
                                                    <?php else : ?>
                                                        <span class="mm-product-thumb mm-product-thumb-empty">Sin foto</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo esc_html( $item->sku ); ?></td>
                                                <td><?php echo $this->mm_render_bodega_img_nombre( $product, $nombre ); ?></td>
                                                <td><?php echo esc_html( (int) $item->cantidad_contada ); ?></td>
                                                <td>
                                                    <?php if ( $can_edit_prices && ! $is_jefatura_price_review ) : ?>
                                                        <input class="mm-price-input mm-costo-input" type="number" min="0" step="0.01" name="items[<?php echo esc_attr( (int) $item->id ); ?>][costo_ia]" value="<?php echo esc_attr( $costo ); ?>" />
                                                    <?php else : ?>
                                                        <span class="mm-readonly-money">$<?php echo esc_html( number_format( $costo, 0, ',', '.' ) ); ?></span>
                                                        <?php if ( $is_jefatura_price_review ) : ?>
                                                            <input type="hidden" name="items[<?php echo esc_attr( (int) $item->id ); ?>][costo_ia]" value="<?php echo esc_attr( $costo ); ?>" />
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ( $can_edit_prices ) : ?>
                                                        <input class="mm-price-input mm-precio-input" type="number" min="0" step="0.01" name="items[<?php echo esc_attr( (int) $item->id ); ?>][precio_propuesto]" value="<?php echo esc_attr( $precio ); ?>" />
                                                    <?php else : ?>
                                                        $<?php echo esc_html( number_format( $precio, 0, ',', '.' ) ); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="mm-margen-cell"><?php echo $precio > 0 ? esc_html( '$' . number_format( $margen, 0, ',', '.' ) ) : '<span class="mm-text-danger">Sin precio</span>'; ?></td>
                                                <td><?php echo ! empty( $item->synced_at ) ? '<span class="mm-badge-soft">OK</span>' : '<span class="mm-badge-soft mm-badge-warn">Pendiente</span>'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ( $can_edit_prices ) : ?>
                                    <div class="mm-price-actions">
                                        <button type="submit" class="mm-primary-action"><?php echo $is_jefatura_price_review ? 'Guardar precio final' : 'Guardar precios'; ?></button>
                                        <?php if ( $can_send_to_approval ) : ?>
                                            <button type="button" id="mm-app-enviar-aprobacion" class="mm-secondary-action">Enviar a aprobación</button>
                                        <?php endif; ?>
                                        <?php if ( $can_approve_load ) : ?>
                                            <button type="button" id="mm-app-aprobar-cargar" class="mm-success-action">Autorizar precios y cargar al sistema</button>
                                        <?php endif; ?>
                                    </div>
                                    <div id="mm-app-precios-msg" class="mm-message" hidden></div>
                                <?php else : ?>
                                    <?php if ( $can_approve_load ) : ?>
                                        <div class="mm-price-actions">
                                            <button type="button" id="mm-app-aprobar-cargar" class="mm-success-action">Autorizar precios y cargar al sistema</button>
                                        </div>
                                        <div id="mm-app-precios-msg" class="mm-message" hidden></div>
                                    <?php else : ?>
                                        <div class="mm-ia-note">Este lote no está disponible para edición. Puede estar cargado, sincronizado o no corresponder a tu rol.</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="mm-detail-grid">
                    <?php echo $this->render_checklist_block( $lote_id ); ?>
                    <div class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Resumen financiero</h3><span>Revisión rápida</span></div>
                        <ul class="mm-summary-list">
                            <li><span>Costo total estimado</span><strong>$<?php echo esc_html( number_format( (float) $totals['costo_total'], 0, ',', '.' ) ); ?></strong></li>
                            <li><span>Venta total propuesta</span><strong>$<?php echo esc_html( number_format( (float) $totals['venta_total'], 0, ',', '.' ) ); ?></strong></li>
                            <li><span>Margen estimado</span><strong>$<?php echo esc_html( number_format( (float) $totals['margen_total'], 0, ',', '.' ) ); ?></strong></li>
                            <li><span>% margen</span><strong><?php echo esc_html( number_format( (float) $totals['margen_pct'], 1, ',', '.' ) ); ?>%</strong></li>
                        </ul>
                    </div>
                </div>
            </section>
        </main>
        <?php return ob_get_clean();
    }


    private function mm45_expected_products_from_lote( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) { return array(); }
        $keys = array('_mm_pedido_productos_internos_lote','_mm_factura_productos_esperados','_mm_productos_esperados','_mm_lote_productos_esperados');
        foreach ( $keys as $key ) {
            $items = get_post_meta( $lote_id, $key, true );
            if ( is_array( $items ) && ! empty( $items ) ) { return $items; }
        }
        $pedido_id = intval( get_post_meta( $lote_id, '_mm_pedido_origen_id', true ) );
        if ( $pedido_id > 0 ) {
            $items = get_post_meta( $pedido_id, '_mm_pedido_productos_internos', true );
            if ( is_array( $items ) && ! empty( $items ) ) { return $items; }
        }
        $items = get_option( 'mm_lote_' . $lote_id . '_expected_products', array() );
        return is_array( $items ) ? $items : array();
    }

    private function mm45_received_products_from_lote( $lote_id ) {
        $saved = get_option( 'mm_lote_' . intval( $lote_id ) . '_received_products', array() );
        if ( is_array( $saved ) && ! empty( $saved ) ) { return $saved; }
        $expected = $this->mm45_expected_products_from_lote( $lote_id );
        $out = array();
        foreach ( $expected as $p ) {
            if ( ! is_array( $p ) ) { continue; }
            $out[] = array(
                'codigo' => sanitize_text_field( $p['codigo'] ?? $p['sku'] ?? '' ),
                'nombre' => sanitize_text_field( $p['nombre'] ?? $p['producto'] ?? $p['descripcion'] ?? '' ),
                'esperado' => intval( $p['cantidad'] ?? 0 ),
                'recibido' => 0,
                'observacion' => sanitize_text_field( $p['observacion'] ?? $p['nota'] ?? '' ),
            );
        }
        return $out;
    }

    private function mm45_apply_lote_to_exhibicion_stock( $lote_id ) {
        $lote_id = intval( $lote_id );
        $received = $this->mm45_received_products_from_lote( $lote_id );
        $items = get_option( 'mm_exhibicion_items', array() );
        $items = is_array( $items ) ? $items : array();
        $updated = 0;
        foreach ( $received as $p ) {
            $codigo = sanitize_text_field( $p['codigo'] ?? '' );
            $nombre = sanitize_text_field( $p['nombre'] ?? '' );
            $cantidad = intval( $p['recibido'] ?? 0 );
            if ( $cantidad <= 0 || ( ! $codigo && ! $nombre ) ) { continue; }
            $found = false;
            foreach ( $items as &$it ) {
                $same_code = $codigo && isset($it['codigo']) && $it['codigo'] === $codigo;
                $same_name = ! $codigo && $nombre && strtolower($it['producto'] ?? '') === strtolower($nombre);
                if ( $same_code || $same_name ) {
                    $it['codigo'] = $codigo ?: ($it['codigo'] ?? '');
                    $it['producto'] = $nombre ?: ($it['producto'] ?? '');
                    $it['bodega'] = intval($it['bodega'] ?? 0) + $cantidad;
                    $it['exhibicion'] = intval($it['exhibicion'] ?? 0);
                    $it['minimo'] = intval($it['minimo'] ?? 0);
                    $it['observacion'] = sanitize_text_field($p['observacion'] ?? ($it['observacion'] ?? ''));
                    $it['updated_at'] = current_time('mysql');
                    $found = true; $updated++;
                    break;
                }
            }
            unset($it);
            if ( ! $found ) {
                $items[] = array('codigo'=>$codigo,'producto'=>$nombre,'exhibicion'=>0,'bodega'=>$cantidad,'minimo'=>0,'observacion'=>sanitize_text_field($p['observacion'] ?? ''),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'));
                $updated++;
            }
        }
        update_option( 'mm_exhibicion_items', array_values($items) );
        update_option( 'mm_lote_' . $lote_id . '_inventario_aplicado', current_time('mysql') );
        update_post_meta( $lote_id, '_mm_lote_estado', 'bodega_finalizada' );
        update_post_meta( $lote_id, '_mm_bodega_finalizada', current_time('mysql') );
        return $updated;
    }

    private function mm45_render_bodega_receipt_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( $lote_id <= 0 ) { return ''; }
        $products = $this->mm45_received_products_from_lote( $lote_id );
        $applied = get_option( 'mm_lote_' . $lote_id . '_inventario_aplicado', '' );
        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-receipt-panel">
            <div class="mm-section-head"><h2>✅ Confirmar cantidades recibidas</h2><span>Al finalizar, estas cantidades pasan al stock en bodega de Exhibición.</span></div>
            <?php if ( $applied ) : ?><div class="mm-safe-note"><strong>Inventario aplicado:</strong><p>Este lote ya alimentó el inventario físico el <?php echo esc_html( $applied ); ?>.</p></div><?php endif; ?>
            <?php if ( empty( $products ) ) : ?>
                <div class="mm-empty-state">Este lote todavía no tiene productos esperados asociados al pedido.</div>
            <?php else : ?>
                <form class="mm-bodega-receipt-form">
                    <div class="mm-bodega-validation-summary">
                        <span>🟢 Completos: <strong data-complete-count>0</strong></span>
                        <span>🔴 Pendientes: <strong data-pending-count>0</strong></span>
                    </div>
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_bodega_receipt_' . $lote_id ) ); ?>">
                    <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">
                    <div class="mm-bodega-receipt-table-wrap"><table class="mm-bodega-receipt-table"><thead><tr><th>Código</th><th>Producto</th><th>Pedido</th><th>Recibido real</th><th>Diferencia</th><th>Observación</th></tr></thead><tbody>
                    <?php foreach ( $products as $i => $p ) : $esp=intval($p['esperado'] ?? 0); $rec=intval($p['recibido'] ?? 0); ?>
                        <tr><td><input type="hidden" name="productos[<?php echo esc_attr($i); ?>][codigo]" value="<?php echo esc_attr($p['codigo'] ?? ''); ?>"><?php echo esc_html($p['codigo'] ?? ''); ?></td><td><input type="hidden" name="productos[<?php echo esc_attr($i); ?>][nombre]" value="<?php echo esc_attr($p['nombre'] ?? ''); ?>"><strong><?php echo esc_html($p['nombre'] ?? ''); ?></strong></td><td><input type="hidden" name="productos[<?php echo esc_attr($i); ?>][esperado]" value="<?php echo esc_attr($esp); ?>"><?php echo esc_html($esp); ?></td><td><input class="mm-input mm-received-input" type="number" min="0" step="1" name="productos[<?php echo esc_attr($i); ?>][recibido]" value="<?php echo esc_attr($rec); ?>" data-esperado="<?php echo esc_attr($esp); ?>"></td><td><span class="mm-receipt-diff"><?php echo esc_html($rec-$esp); ?></span></td><td><input class="mm-input" type="text" name="productos[<?php echo esc_attr($i); ?>][observacion]" value="<?php echo esc_attr($p['observacion'] ?? ''); ?>"></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div><div class="mm-bodega-receipt-actions"><button type="submit" class="mm-mini-secondary">Guardar cantidades</button><button type="button" class="mm-mini-primary mm-finalizar-bodega-lote">Finalizar Bodega y alimentar inventario</button></div><div class="mm-bodega-receipt-msg" hidden></div>
                </form>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }

    public function ajax_guardar_bodega_receipt() {
        $lote_id = isset($_POST['lote_id']) ? intval($_POST['lote_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ( $lote_id <= 0 || ! wp_verify_nonce( $nonce, 'mm_bodega_receipt_' . $lote_id ) ) { wp_send_json_error(array('message'=>'Sesión vencida o lote inválido.'),403); }
        $raw = isset($_POST['productos']) && is_array($_POST['productos']) ? wp_unslash($_POST['productos']) : array();
        $products = array();
        foreach ($raw as $p) { if (is_array($p)) $products[] = array('codigo'=>sanitize_text_field($p['codigo']??''),'nombre'=>sanitize_text_field($p['nombre']??''),'esperado'=>intval($p['esperado']??0),'recibido'=>intval($p['recibido']??0),'observacion'=>sanitize_text_field($p['observacion']??'')); }
        update_option('mm_lote_'.$lote_id.'_received_products',$products);
        wp_send_json_success(array('message'=>'Cantidades recibidas guardadas.'));
    }

    public function ajax_finalizar_bodega_lote() {
        $this->ajax_guardar_bodega_receipt_no_exit();
    }

    private function ajax_guardar_bodega_receipt_no_exit() {
        $lote_id = isset($_POST['lote_id']) ? intval($_POST['lote_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ( $lote_id <= 0 || ! wp_verify_nonce( $nonce, 'mm_bodega_receipt_' . $lote_id ) ) { wp_send_json_error(array('message'=>'Sesión vencida o lote inválido.'),403); }
        $raw = isset($_POST['productos']) && is_array($_POST['productos']) ? wp_unslash($_POST['productos']) : array();
        $products = array();
        foreach ($raw as $p) { if (is_array($p)) $products[] = array('codigo'=>sanitize_text_field($p['codigo']??''),'nombre'=>sanitize_text_field($p['nombre']??''),'esperado'=>intval($p['esperado']??0),'recibido'=>intval($p['recibido']??0),'observacion'=>sanitize_text_field($p['observacion']??'')); }
        update_option('mm_lote_'.$lote_id.'_received_products',$products);
        $updated = $this->mm45_apply_lote_to_exhibicion_stock($lote_id);
        wp_send_json_success(array('message'=>'Inventario físico actualizado. Productos actualizados: '.$updated,'redirect'=>add_query_arg('mm_logistica_app','exhibicion',home_url('/'))));
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
