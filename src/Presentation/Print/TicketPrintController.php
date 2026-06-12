<?php
namespace MegaMundo\Logistica\Presentation\Print;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Domain\Lote\LoteAuditRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Ticket\TicketService;

class TicketPrintController {

    private $lote_repo;
    private $item_repo;
    private $permission_guard;
    private $ticket_service;
    private $audit_repo;

    public function __construct(
        LoteRepository $lote_repo,
        LoteItemRepository $item_repo,
        PermissionGuard $permission_guard,
        TicketService $ticket_service,
        LoteAuditRepository $audit_repo
    ) {
        $this->lote_repo        = $lote_repo;
        $this->item_repo        = $item_repo;
        $this->permission_guard = $permission_guard;
        $this->ticket_service   = $ticket_service;
        $this->audit_repo       = $audit_repo;
    }

    public function hook() {
        add_action( 'template_redirect', array( $this, 'renderizar_vista_impresion' ) );
    }

    public function renderizar_vista_impresion() {
        if ( isset( $_GET['imprimir_tickets_lote'] ) ) {
            $lote_id = intval( $_GET['imprimir_tickets_lote'] );
            
            // 1. Verificación de permisos
            if ( ! $this->permission_guard->can_print() ) {
                wp_die( 'No tienes permisos para acceder a la impresión de etiquetas de este lote.' );
            }

            // 2. Verificación de Nonce
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'imprimir_tickets_' . $lote_id ) ) {
                wp_die( 'Acceso no autorizado o enlace de impresión caducado.' );
            }

            // 3. Verificación de tipo de post y existencia
            $lote = $this->lote_repo->find( $lote_id );
            if ( ! $lote ) {
                wp_die( 'El lote especificado no es válido o no existe.' );
            }

            // 4. Verificación de estado del lote: Solo mm_cargado o con _sincronizado_wc
            $estado_lote = $this->lote_repo->get_status( $lote_id );
            $sincronizado = $this->lote_repo->is_synchronized( $lote_id );
            
            if ( 'mm_cargado' !== $estado_lote && ! $sincronizado ) {
                wp_die( 'Este lote aún no ha sido cargado en WooCommerce. No se pueden imprimir etiquetas.' );
            }

            $items = $this->item_repo->get_by_lote( $lote_id );

            // Impresión individual por producto / ítem del lote.
            $item_id_filter = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;
            $print_qty      = isset( $_GET['print_qty'] ) ? absint( $_GET['print_qty'] ) : 0;

            if ( $item_id_filter > 0 ) {
                $items = array_values( array_filter( $items, function( $item ) use ( $item_id_filter ) {
                    return intval( $item->id ) === $item_id_filter;
                } ) );

                // Impresión personalizada: cantidad específica solicitada por el usuario.
                if ( ! empty( $items ) && $print_qty > 0 ) {
                    $items[0]->cantidad_contada = $print_qty;
                }
            }

            if ( empty( $items ) ) {
                wp_die( $item_id_filter > 0 ? 'Este producto no existe dentro del lote o no está disponible para imprimir.' : 'El lote no contiene productos para imprimir.' );
            }

            // Registrar log de impresión de etiquetas
            $this->audit_repo->add_log(
                $lote_id,
                'etiquetas_impresas',
                $item_id_filter > 0
                    ? sprintf( 'Se cargó la vista de impresión individual para el ítem #%d del lote con cantidad %d.', $item_id_filter, $print_qty > 0 ? $print_qty : intval( $items[0]->cantidad_contada ) )
                    : sprintf( 'Se cargó la vista de impresión para %d productos del lote.', count( $items ) )
            );

            // Instanciar y renderizar la vista
            $view = new TicketPrintView( $lote_id, $items, $this->ticket_service );
            $view->render();
            exit;
        }
    }
}
