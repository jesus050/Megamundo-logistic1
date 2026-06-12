<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait SistemaTrait {
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
}
