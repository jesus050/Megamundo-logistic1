<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait NotificacionesTrait {
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
}
