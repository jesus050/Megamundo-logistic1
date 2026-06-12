<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait UsuariosTrait {
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
}
