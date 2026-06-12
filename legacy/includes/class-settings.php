<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'agregar_pagina_ajustes' ) );
        add_action( 'admin_init', array( $this, 'registrar_ajustes' ) );
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
                            <input type="number" name="mm_ticket_ancho" value="<?php echo esc_attr( get_option('mm_ticket_ancho', 50) ); ?>" style="width: 100px;" min="10" max="200" /> mm
                            <p class="description">Ancho del rollo de la impresora térmica (ej: 50).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Alto del Ticket (milímetros)</th>
                        <td>
                            <input type="number" name="mm_ticket_alto" value="<?php echo esc_attr( get_option('mm_ticket_alto', 25) ); ?>" style="width: 100px;" min="10" max="200" /> mm
                            <p class="description">Alto de cada pegatina individual (ej: 25).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Tamaño de Fuente Base</th>
                        <td>
                            <input type="number" name="mm_ticket_font_size" value="<?php echo esc_attr( get_option('mm_ticket_font_size', 10) ); ?>" style="width: 100px;" min="6" max="24" /> px
                            <p class="description">Tamaño de letra por defecto para los textos de la etiqueta (ej: 10).</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar Precio de Venta</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_precio" value="1" <?php checked( 1, get_option('mm_ticket_mostrar_precio', 1) ); ?> />
                            <span class="description">Imprimir el precio de venta propuesto en la etiqueta.</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar Nombre del Producto</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_nombre" value="1" <?php checked( 1, get_option('mm_ticket_mostrar_nombre', 1) ); ?> />
                            <span class="description">Imprimir el nombre del producto en la etiqueta.</span>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Mostrar Texto "Calidad Garantizada"</th>
                        <td>
                            <input type="checkbox" name="mm_ticket_mostrar_calidad" value="1" <?php checked( 1, get_option('mm_ticket_mostrar_calidad', 1) ); ?> />
                            <span class="description">Imprimir la leyenda "Calidad Garantizada" en cada etiqueta.</span>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button( 'Guardar Ajustes' ); ?>
            </form>
        </div>
        <?php
    }
}

new MM_Logistica_Settings();
