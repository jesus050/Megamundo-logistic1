<?php
namespace MegaMundo\Logistica\Application\Ticket;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class TicketService {

    public function get_ticket_settings() {
        return array(
            'ancho_mm'        => intval( get_option( 'mm_ticket_ancho', 50 ) ),
            'alto_mm'         => intval( get_option( 'mm_ticket_alto', 25 ) ),
            'font_size_px'    => intval( get_option( 'mm_ticket_font_size', 10 ) ),
            'mostrar_precio'  => intval( get_option( 'mm_ticket_mostrar_precio', 1 ) ) === 1,
            'mostrar_nombre'  => intval( get_option( 'mm_ticket_mostrar_nombre', 1 ) ) === 1,
            'mostrar_calidad'     => intval( get_option( 'mm_ticket_mostrar_calidad', 1 ) ) === 1,
            'logo_url'            => esc_url_raw( get_option( 'mm_ticket_logo_url', '' ) ),
            'nombre_empresa'      => sanitize_text_field( get_option( 'mm_ticket_nombre_empresa', 'MegaMundo' ) ),
            'texto_inferior'      => sanitize_text_field( get_option( 'mm_ticket_texto_inferior', 'Calidad Garantizada' ) ),
            'mostrar_logo'        => intval( get_option( 'mm_ticket_mostrar_logo', 0 ) ) === 1,
            'mostrar_sku_texto'   => intval( get_option( 'mm_ticket_mostrar_sku_texto', 1 ) ) === 1,
            'mostrar_barcode'     => intval( get_option( 'mm_ticket_mostrar_barcode', 1 ) ) === 1,
            'barcode_height'      => max( 10, intval( get_option( 'mm_ticket_barcode_height', 25 ) ) ),
        );
    }
}
