<?php
namespace MegaMundo\Logistica\Presentation\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SettingsController {

    public function hook() {
        add_action( 'admin_menu', array( $this, 'agregar_pagina_ajustes' ) );
        add_action( 'admin_init', array( $this, 'registrar_ajustes' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_uploader' ) );
    }


    public function enqueue_media_uploader( $hook ) {
        if ( isset( $_GET['page'] ) && 'mm-logistica-ajustes' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
            wp_enqueue_media();
        }
    }

    public function agregar_pagina_ajustes() {
        add_submenu_page(
            'edit.php?post_type=lotes_ingreso',
            'Ajustes de Logística',
            'Ajustes',
            'manage_options',
            'mm-logistica-ajustes',
            array( $this, 'render_pagina_ajustes' )
        );
    }

    public function registrar_ajustes() {
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_ancho', array(
            'sanitize_callback' => 'absint',
            'default'           => 50,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_alto', array(
            'sanitize_callback' => 'absint',
            'default'           => 25,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_mostrar_precio', array(
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_mostrar_nombre', array(
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_mostrar_calidad', array(
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_font_size', array(
            'sanitize_callback' => 'absint',
            'default'           => 10,
        ) );

        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_logo_url', array(
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_nombre_empresa', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'MegaMundo',
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_texto_inferior', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Calidad Garantizada',
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_mostrar_logo', array(
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_mostrar_sku_texto', array(
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_mostrar_barcode', array(
            'sanitize_callback' => 'absint',
            'default'           => 1,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ticket_barcode_height', array(
            'sanitize_callback' => 'absint',
            'default'           => 25,
        ) );

        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ia_habilitada', array(
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ia_openai_api_key', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_ia_modelo', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'gpt-5.5',
        ) );

        // Webhooks salientes (Fase 4): notificar a sistemas externos en cambios de estado.
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_webhook_url', array(
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ) );
        register_setting( 'mm_logistica_grupo_ajustes', 'mm_webhook_secret', array(
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
    }

    public function render_pagina_ajustes() {
        ?>
        <div class="wrap">
            <h1>⚙️ Ajustes de Logística MegaMundo</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'mm_logistica_grupo_ajustes' );
                do_settings_sections( 'mm_logistica_grupo_ajustes' );
                ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Ancho del Ticket (milímetros)</th>
                        <td>
                            <input type="number" name="mm_ticket_ancho" value="<?php echo esc_attr( get_option( 'mm_ticket_ancho', 50 ) ); ?>" style="width: 100px;" min="10" max="200" /> mm
                            <p class="description">Ancho del rollo de la impresora térmica (ej: 50).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Alto del Ticket (milímetros)</th>
                        <td>
                            <input type="number" name="mm_ticket_alto" value="<?php echo esc_attr( get_option( 'mm_ticket_alto', 25 ) ); ?>" style="width: 100px;" min="10" max="200" /> mm
                            <p class="description">Alto de cada pegatina individual (ej: 25).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tamaño de Fuente Base</th>
                        <td>
                            <input type="number" name="mm_ticket_font_size" value="<?php echo esc_attr( get_option( 'mm_ticket_font_size', 10 ) ); ?>" style="width: 100px;" min="6" max="24" /> px
                            <p class="description">Tamaño de letra por defecto para los textos de la etiqueta (ej: 10).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar Precio de Venta</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_precio" value="1" <?php checked( 1, get_option( 'mm_ticket_mostrar_precio', 1 ) ); ?> />
                            <span class="description">Imprimir el precio de venta propuesto en la etiqueta.</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar Nombre del Producto</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_nombre" value="1" <?php checked( 1, get_option( 'mm_ticket_mostrar_nombre', 1 ) ); ?> />
                            <span class="description">Imprimir el nombre del producto en la etiqueta.</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar Texto "Calidad Garantizada"</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_calidad" value="1" <?php checked( 1, get_option( 'mm_ticket_mostrar_calidad', 1 ) ); ?> />
                            <span class="description">Imprimir la leyenda "Calidad Garantizada" en cada etiqueta.</span>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2">
                            <h2 style="margin-top:30px;">🔗 Webhooks salientes</h2>
                            <p class="description">Notifica a un sistema externo (Slack, n8n, ERP) cuando un lote cambia de estado. Déjalo vacío para desactivar.</p>
                        </th>
                    </tr>
                    <tr valign="top">
                        <th scope="row">URL del Webhook</th>
                        <td>
                            <input type="url" name="mm_webhook_url" value="<?php echo esc_attr( get_option( 'mm_webhook_url', '' ) ); ?>" style="width: 480px;" placeholder="https://hooks.tu-sistema.com/megamundo" />
                            <p class="description">Se enviará un POST con JSON en cada transición (enviado a precios, a aprobación, aprobado).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Secreto del Webhook</th>
                        <td>
                            <input type="text" name="mm_webhook_secret" value="<?php echo esc_attr( get_option( 'mm_webhook_secret', '' ) ); ?>" style="width: 320px;" autocomplete="off" />
                            <p class="description">Opcional. Si lo defines, cada envío incluirá la cabecera <code>X-MegaMundo-Signature</code> (HMAC-SHA256) para que el receptor verifique el origen.</p>
                        </td>
                    </tr>

                    <tr>
                        <th colspan="2">
                            <h2 style="margin-top:30px;">🎫 Diseñador visual de etiquetas</h2>
                            <p class="description">Configura cómo se verán las etiquetas impresas sin tocar código.</p>
                        </th>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Nombre de la empresa</th>
                        <td>
                            <input type="text" name="mm_ticket_nombre_empresa" value="<?php echo esc_attr( get_option( 'mm_ticket_nombre_empresa', 'MegaMundo' ) ); ?>" style="width: 320px;" />
                            <p class="description">Texto superior de la etiqueta.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Logo de la etiqueta</th>
                        <td>
                            <input type="url" id="mm_ticket_logo_url" name="mm_ticket_logo_url" value="<?php echo esc_attr( get_option( 'mm_ticket_logo_url', '' ) ); ?>" style="width: 420px;" placeholder="https://..." />
                            <button type="button" class="button" id="mm_select_ticket_logo">Seleccionar logo</button>
                            <p class="description">Opcional. Sube o selecciona un logo desde Medios.</p>
                            <?php if ( get_option( 'mm_ticket_logo_url', '' ) ) : ?>
                                <div style="margin-top:10px;"><img src="<?php echo esc_url( get_option( 'mm_ticket_logo_url', '' ) ); ?>" style="max-height:50px; background:#fff; border:1px solid #ddd; padding:5px;"></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar logo</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_logo" value="1" <?php checked( 1, get_option( 'mm_ticket_mostrar_logo', 0 ) ); ?> />
                            <span class="description">Mostrar el logo encima del nombre de empresa.</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar SKU en texto</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_sku_texto" value="1" <?php checked( 1, get_option( 'mm_ticket_mostrar_sku_texto', 1 ) ); ?> />
                            <span class="description">Además del código de barras, imprimir el SKU como texto pequeño.</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar código de barras</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_barcode" value="1" <?php checked( 1, get_option( 'mm_ticket_mostrar_barcode', 1 ) ); ?> />
                            <span class="description">Imprimir código de barras en la etiqueta.</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Altura del código de barras</th>
                        <td>
                            <input type="number" name="mm_ticket_barcode_height" value="<?php echo esc_attr( get_option( 'mm_ticket_barcode_height', 25 ) ); ?>" style="width: 100px;" min="10" max="80" /> px
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Texto inferior</th>
                        <td>
                            <input type="text" name="mm_ticket_texto_inferior" value="<?php echo esc_attr( get_option( 'mm_ticket_texto_inferior', 'Calidad Garantizada' ) ); ?>" style="width: 320px;" />
                            <p class="description">Ejemplo: Calidad Garantizada, Gracias por tu compra, MegaMundo.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Vista previa</th>
                        <td>
                            <div style="width:<?php echo esc_attr( get_option( 'mm_ticket_ancho', 50 ) ); ?>mm; min-height:<?php echo esc_attr( get_option( 'mm_ticket_alto', 25 ) ); ?>mm; background:#fff; border:1px dashed #999; padding:6px; text-align:center; font-family:Arial; box-shadow:0 8px 20px rgba(0,0,0,.08);">
                                <?php if ( get_option( 'mm_ticket_mostrar_logo', 0 ) && get_option( 'mm_ticket_logo_url', '' ) ) : ?>
                                    <img src="<?php echo esc_url( get_option( 'mm_ticket_logo_url', '' ) ); ?>" style="max-height:18px; max-width:90%; object-fit:contain;"><br>
                                <?php endif; ?>
                                <strong style="font-size:9px;"><?php echo esc_html( get_option( 'mm_ticket_nombre_empresa', 'MegaMundo' ) ); ?></strong><br>
                                <?php if ( get_option( 'mm_ticket_mostrar_nombre', 1 ) ) : ?><span style="font-size:9px;">Producto de ejemplo</span><br><?php endif; ?>
                                <?php if ( get_option( 'mm_ticket_mostrar_precio', 1 ) ) : ?><strong style="font-size:12px;">$25.000</strong><br><?php endif; ?>
                                <?php if ( get_option( 'mm_ticket_mostrar_barcode', 1 ) ) : ?><div style="height:14px; margin:2px auto; width:80%; background:repeating-linear-gradient(90deg,#111 0,#111 2px,#fff 2px,#fff 4px);"></div><?php endif; ?>
                                <?php if ( get_option( 'mm_ticket_mostrar_sku_texto', 1 ) ) : ?><small style="font-size:7px;">SKU: 770123456789</small><br><?php endif; ?>
                                <?php if ( get_option( 'mm_ticket_mostrar_calidad', 1 ) ) : ?><small style="font-size:7px; border-top:1px dotted #000; display:block; margin-top:2px;"><?php echo esc_html( get_option( 'mm_ticket_texto_inferior', 'Calidad Garantizada' ) ); ?></small><?php endif; ?>
                            </div>
                        </td>
                    </tr>

                    <tr valign="top">
                        <th scope="row">Activar Asistente IA</th>
                        <td>
                            <input type="checkbox" name="mm_ia_habilitada" value="1" <?php checked( 1, get_option( 'mm_ia_habilitada', 0 ) ); ?> />
                            <span class="description">Permite analizar fotos de productos nuevos desde el panel de bodega.</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">OpenAI API Key</th>
                        <td>
                            <input type="password" name="mm_ia_openai_api_key" value="<?php echo esc_attr( get_option( 'mm_ia_openai_api_key', '' ) ); ?>" style="width: 420px;" autocomplete="off" />
                            <p class="description">Pega aquí tu clave de OpenAI para activar el análisis con IA.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Modelo IA</th>
                        <td>
                            <input type="text" name="mm_ia_modelo" value="<?php echo esc_attr( get_option( 'mm_ia_modelo', 'gpt-5.5' ) ); ?>" style="width: 220px;" />
                            <p class="description">Ejemplo recomendado: gpt-4o-mini.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Guardar Ajustes' ); ?>
            </form>
        </div>
        <script>
        jQuery(function($){
            $('#mm_select_ticket_logo').on('click', function(e){
                e.preventDefault();
                const frame = wp.media({
                    title: 'Seleccionar logo para etiqueta',
                    button: { text: 'Usar este logo' },
                    multiple: false
                });
                frame.on('select', function(){
                    const attachment = frame.state().get('selection').first().toJSON();
                    $('#mm_ticket_logo_url').val(attachment.url);
                });
                frame.open();
            });
        });
        </script>
        <?php
    }
}
