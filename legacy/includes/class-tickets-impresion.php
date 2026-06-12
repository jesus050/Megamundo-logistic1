<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_Tickets {

    public function __construct() {
        // Interceptamos la URL para generar la vista de impresión
        add_action( 'template_redirect', array( $this, 'renderizar_vista_impresion' ) );
    }

    public function renderizar_vista_impresion() {
        if ( isset( $_GET['imprimir_tickets_lote'] ) ) {
            $lote_id = intval( $_GET['imprimir_tickets_lote'] );
            
            // 1. Verificación de permisos: Solo administrator y mm_ingresador pueden imprimir
            $user = wp_get_current_user();
            $es_admin = in_array( 'administrator', (array) $user->roles, true );
            $es_ingresador = in_array( 'mm_ingresador', (array) $user->roles, true );
            
            if ( ! $es_admin && ! $es_ingresador ) {
                wp_die( 'No tienes permisos para acceder a la impresión de etiquetas de este lote.' );
            }

            // 2. Verificación de Nonce
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'imprimir_tickets_' . $lote_id ) ) {
                wp_die( 'Acceso no autorizado o enlace de impresión caducado.' );
            }

            // 3. Verificación de tipo de post y existencia
            if ( get_post_type( $lote_id ) !== 'lotes_ingreso' ) {
                wp_die( 'El lote especificado no es válido.' );
            }

            // 4. Verificación de estado del lote: Solo mm_cargado o con _sincronizado_wc
            $estado_lote = get_post_status( $lote_id );
            $sincronizado = get_post_meta( $lote_id, '_sincronizado_wc', true );
            
            if ( 'mm_cargado' !== $estado_lote && ! $sincronizado ) {
                wp_die( 'Este lote aún no ha sido cargado en WooCommerce. No se pueden imprimir etiquetas.' );
            }

            global $wpdb;
            $tabla = $wpdb->prefix . 'mm_lote_items';
            $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tabla WHERE lote_id = %d", $lote_id ) );

            if ( empty( $items ) ) {
                wp_die( 'El lote no contiene productos para imprimir.' );
            }

            // --- LEER CONFIGURACIÓN DE ETIQUETAS ---
            $ancho_mm        = intval( get_option( 'mm_ticket_ancho', 50 ) );
            $alto_mm         = intval( get_option( 'mm_ticket_alto', 25 ) );
            $font_size_px    = intval( get_option( 'mm_ticket_font_size', 10 ) );
            $mostrar_precio  = intval( get_option( 'mm_ticket_mostrar_precio', 1 ) ) === 1;
            $mostrar_nombre  = intval( get_option( 'mm_ticket_mostrar_nombre', 1 ) ) === 1;
            $mostrar_calidad = intval( get_option( 'mm_ticket_mostrar_calidad', 1 ) ) === 1;

            ?>
            <!DOCTYPE html>
            <html lang="es">
            <head>
                <meta charset="UTF-8">
                <title>Impresión de Etiquetas - Lote #<?php echo esc_html( $lote_id ); ?></title>
                <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
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
                            <div class="nombre-empresa">MegaMundo</div>
                            
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
                JsBarcode(".codigo-barras").init();
                
                // Disparar el cuadro de impresión automáticamente
                window.onload = function() {
                    window.print();
                };
            </script>
            </body>
            </html>
            <?php
            exit; // Detenemos la carga del resto de WordPress
        }
    }
}

new MM_Logistica_Tickets();
