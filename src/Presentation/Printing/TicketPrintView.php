<?php
namespace MegaMundo\Logistica\Presentation\Printing;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Application\Ticket\TicketService;

class TicketPrintView {

    private $lote_id;
    private $items;
    private $ticket_service;

    public function __construct( $lote_id, array $items, TicketService $ticket_service ) {
        $this->lote_id        = $lote_id;
        $this->items          = $items;
        $this->ticket_service = $ticket_service;
    }

    public function render() {
        $settings        = $this->ticket_service->get_ticket_settings();
        $ancho_mm        = $settings['ancho_mm'];
        $alto_mm         = $settings['alto_mm'];
        $font_size_px    = $settings['font_size_px'];
        $mostrar_precio    = $settings['mostrar_precio'];
        $mostrar_nombre    = $settings['mostrar_nombre'];
        $mostrar_calidad   = $settings['mostrar_calidad'];
        $logo_url          = $settings['logo_url'];
        $nombre_empresa    = $settings['nombre_empresa'];
        $texto_inferior    = $settings['texto_inferior'];
        $mostrar_logo      = $settings['mostrar_logo'];
        $mostrar_sku_texto = $settings['mostrar_sku_texto'];
        $mostrar_barcode   = $settings['mostrar_barcode'];
        $barcode_height    = $settings['barcode_height'];
        
        $lote_id = $this->lote_id;
        $items   = $this->items;

        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Impresión de Etiquetas - Lote #<?php echo esc_html( $lote_id ); ?></title>
            <script src="<?php echo esc_url( plugins_url( 'assets/vendor/jsbarcode/JsBarcode.all.min.js', dirname( dirname( dirname( __DIR__ ) ) ) . '/megamundo-logistica.php' ) ); ?>"></script>
            <style>
                /* Reset básico */
                body { margin: 0; padding: 0; background: #eaeaea; font-family: 'Arial', sans-serif; }
                
                .print-header {
                    position: sticky;
                    top: 0;
                    z-index: 1000;
                }
                
                /* Contenedor de las etiquetas en pantalla */
                .tickets-container {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    padding: 20px;
                    gap: 15px;
                }
                
                /* Diseño de cada etiqueta (Ajustado a térmica estándar ej: 50x25mm) */
                .ticket {
                    width: <?php echo esc_html( $ancho_mm ); ?>mm;
                    height: <?php echo esc_html( $alto_mm ); ?>mm;
                    background: #fff;
                    border: 1px dashed #ccc;
                    box-sizing: border-box;
                    text-align: center;
                    padding: 1.5mm;
                    overflow: hidden;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    align-items: center;
                    font-size: <?php echo esc_html( $font_size_px ); ?>px;
                }
                
                .ticket-logo {
                    max-height: 5mm;
                    max-width: 90%;
                    object-fit: contain;
                    margin-bottom: 1px;
                }

                .nombre-empresa {
                    font-size: 8px;
                    font-weight: bold;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    color: #333;
                    margin-bottom: 1px;
                    width: 100%;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                
                .nombre-producto {
                    font-size: 9px;
                    line-height: 1.1;
                    margin-bottom: 1px;
                    width: 100%;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    font-weight: 500;
                }
                
                .precio {
                    font-size: 11px;
                    font-weight: bold;
                    margin-top: 1px;
                    margin-bottom: 1px;
                }
                
                .sku-texto {
                    font-size: 7px;
                    font-weight: bold;
                    width: 100%;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }

                .calidad {
                    font-size: 7px;
                    text-transform: uppercase;
                    letter-spacing: 0.3px;
                    width: 100%;
                    border-top: 1.5px dotted #000;
                    padding-top: 1px;
                    margin-top: 1px;
                }
                
                /* Código de barras */
                .codigo-barras {
                    max-width: 95%;
                    height: auto;
                    max-height: 8mm;
                    margin: 0 auto;
                }

                /* Al imprimir, ocultamos el fondo gris y los botones */
                @media print {
                    body { background: #fff; margin: 0; padding: 0; }
                    .no-print { display: none !important; }
                    .tickets-container { padding: 0; gap: 0; display: block; }
                    .ticket {
                        margin: 0;
                        border: none;
                        page-break-after: always;
                        page-break-inside: avoid;
                    }
                    @page {
                        margin: 0;
                        size: <?php echo esc_html( $ancho_mm ); ?>mm <?php echo esc_html( $alto_mm ); ?>mm;
                    }
                }
            </style>
        </head>
        <body>

        <!-- Cabecera de control no imprimible -->
        <div class="no-print print-header" style="background:#f1f1f1; border-bottom:1px solid #ccc; padding:10px; display:flex; justify-content:space-between; align-items:center; font-family:sans-serif;">
            <div>
                <strong>MegaMundo Logística</strong> - Impresión de Lote #<?php echo esc_html( $lote_id ); ?>
            </div>
            <div>
                <button onclick="window.print();" class="print-btn" style="background:#007cba; color:#fff; border:none; padding:8px 15px; border-radius:3px; cursor:pointer; font-weight:bold; margin-right:10px;">🖨️ Imprimir etiquetas</button>
                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $lote_id . '&action=edit' ) ); ?>" class="back-link" style="color:#666; text-decoration:none; font-size:14px; font-weight:600;">Volver al lote</a>
            </div>
        </div>

        <div class="tickets-container">
            <?php
            foreach ( $items as $item ) {
                $cantidad = intval( $item->cantidad_contada );
                
                // Manejo robusto para productos eliminados
                $nombre = get_the_title( $item->producto_id );
                if ( empty( $nombre ) ) {
                    $nombre = 'Producto Eliminado (' . $item->sku . ')';
                }
                
                $precio_val = floatval( $item->precio_propuesto );
                $precio_formatted = number_format( $precio_val, 0, ',', '.' );

                // Si no hay SKU en meta, usar el SKU que guardamos en nuestra base de datos
                $sku = get_post_meta( $item->producto_id, '_sku', true ) ?: $item->sku;
                if ( empty( $sku ) ) {
                    $sku = 'ID-' . $item->producto_id;
                }

                for ( $i = 0; $i < $cantidad; $i++ ) {
                    ?>
                    <div class="ticket">
                        <?php if ( $mostrar_logo && ! empty( $logo_url ) ) : ?>
                            <img class="ticket-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $nombre_empresa ); ?>">
                        <?php endif; ?>
                        <div class="nombre-empresa"><?php echo esc_html( $nombre_empresa ); ?></div>
                        
                        <?php if ( $mostrar_nombre ) : ?>
                            <div class="nombre-producto"><?php echo esc_html( $nombre ); ?></div>
                        <?php endif; ?>
                        
                        <?php if ( $mostrar_precio && $precio_val > 0 ) : ?>
                            <div class="precio">$<?php echo esc_html( $precio_formatted ); ?></div>
                        <?php endif; ?>
                        
                        <svg class="codigo-barras" jsbarcode-value="<?php echo esc_attr( $sku ); ?>" jsbarcode-displayvalue="true" jsbarcode-textmargin="0" jsbarcode-fontoptions="bold" jsbarcode-fontsize="8" jsbarcode-height="25" jsbarcode-width="1.2" jsbarcode-margin="0"></svg>
                        
                        <?php if ( $mostrar_calidad ) : ?>
                            <div class="calidad">Calidad Garantizada</div>
                        <?php endif; ?>
                    </div>
                    <?php
                }
            }
            ?>
        </div>

        <script>
            // Inicializar todos los códigos de barras de la página al instante
            if (document.querySelector(".codigo-barras")) {
                JsBarcode(".codigo-barras").init();
            }
            
            // Disparar el cuadro de impresión automáticamente
            window.onload = function() {
                window.print();
            };
        </script>
        </body>
        </html>
        <?php
    }
}
