<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_Frontend {

    public function __construct() {
        add_shortcode( 'mm_escaner_bodega', array( $this, 'render_escaner' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'cargar_scripts' ) );
    }

    public function cargar_scripts() {
        global $post;
        // Solo cargamos el JS si la página contiene nuestro shortcode
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'mm_escaner_bodega' ) ) {
            wp_enqueue_script( 'mm-app-bodega', plugin_dir_url( __DIR__ ) . 'assets/js/app-bodega.js', array(), '1.1.0', true );
            wp_localize_script( 'mm-app-bodega', 'mmApiSettings', array(
                'root'  => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' )
            ) );
        }
    }

    public function render_escaner( $atts ) {
        $atts = shortcode_atts( array(
            'lote_id' => 0,
        ), $atts, 'mm_escaner_bodega' );

        $lote_id_forzado = intval( $atts['lote_id'] );
        $lote_forzado_valido = false;

        if ( $lote_id_forzado > 0 ) {
            $post_lote = get_post( $lote_id_forzado );
            if ( $post_lote && $post_lote->post_type === 'lotes_ingreso' && $post_lote->post_status === 'draft' ) {
                $lote_forzado_valido = true;
            }
        }

        $lotes_abiertos = get_posts( array(
            'post_type'   => 'lotes_ingreso',
            'post_status' => 'draft',
            'numberposts' => -1
        ) );

        ob_start(); ?>

        <div id="mm-contenedor-escaner" style="max-width: 400px; margin: 0 auto; padding: 20px; background: #f9f9f9; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <h2 style="text-align: center;">📦 Ingreso Rápido</h2>

            <!-- Contenedores para la cola offline y el estado de conexión -->
            <div id="mm-estado-conexion" style="display: none; padding: 10px; margin-bottom: 15px; border-radius: 4px; text-align: center; font-weight: bold; font-size: 13px;"></div>
            <div id="mm-pendientes-contador" style="display: none; background: #e5f5fa; border-left: 4px solid #00a0d2; padding: 8px; margin-bottom: 15px; font-size: 13px; font-weight: bold;"></div>

            <?php if ( $lote_id_forzado > 0 && ! $lote_forzado_valido ) : ?>
                <div style="background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 15px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; text-align: center;">
                    ⚠️ No hay un lote activo seleccionado para escanear o el lote especificado es inválido.
                </div>
            <?php endif; ?>

            <form id="mm-form-escaner" <?php if ( $lote_id_forzado > 0 && ! $lote_forzado_valido ) echo 'style="opacity:0.5; pointer-events:none;"'; ?>>
                <div style="margin-bottom: 15px;">
                    <label><strong>1. Lote Activo:</strong></label>
                    <?php if ( $lote_forzado_valido ) : ?>
                        <input type="hidden" id="mm-lote-id" value="<?php echo esc_attr( $lote_id_forzado ); ?>" />
                        <div style="padding: 10px; background: #e2e3e5; border: 1px solid #ced4da; border-radius: 4px; font-weight: bold;">
                            Lote #<?php echo esc_html( $lote_id_forzado ); ?> - <?php echo esc_html( get_the_title( $lote_id_forzado ) ); ?> (Forzado)
                        </div>
                    <?php else : ?>
                        <select id="mm-lote-id" required style="width: 100%; padding: 10px;">
                            <option value="">-- Selecciona --</option>
                            <?php foreach ( $lotes_abiertos as $lote ) : ?>
                                <option value="<?php echo esc_attr( $lote->ID ); ?>">Lote #<?php echo esc_html( $lote->ID ); ?> - <?php echo esc_html( $lote->post_title ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom: 15px;">
                    <label><strong>2. Código de Barras (SKU):</strong></label>
                    <input type="text" id="mm-codigo-producto" required autofocus style="width: 100%; padding: 15px; font-size: 18px;" autocomplete="off" <?php if ( $lote_id_forzado > 0 && ! $lote_forzado_valido ) echo 'disabled'; ?> />
                </div>

                <div id="mm-caja-nombre-nuevo" style="display: none; margin-bottom: 15px; background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107;">
                    <label><strong>⚠️ Producto Nuevo Detectado.</strong><br>Escribe el nombre visible en la caja:</label>
                    <input type="text" id="mm-nombre-nuevo" style="width: 100%; padding: 10px; font-size: 16px; margin-top: 5px; margin-bottom: 10px;" placeholder="Ej: Carro Control Remoto" />

                    <label><strong>📸 Tomar foto de la caja (Opcional):</strong></label>
                    <input type="file" id="mm-foto-nuevo" accept="image/*" capture="environment" style="width: 100%; padding: 10px; background: #ffffff; border: 1px solid #ccc; margin-top: 5px;" />
                </div>

                <div style="margin-bottom: 15px;">
                    <label><strong>3. Cantidad Física:</strong></label>
                    <input type="number" id="mm-cantidad" value="1" min="1" required style="width: 100%; padding: 10px; font-size: 16px;" <?php if ( $lote_id_forzado > 0 && ! $lote_forzado_valido ) echo 'disabled'; ?> />
                </div>

                <button type="submit" id="mm-btn-submit" style="width: 100%; padding: 15px; background: #0073aa; color: white; border: none; font-size: 18px; cursor: pointer; border-radius: 4px;" <?php if ( $lote_id_forzado > 0 && ! $lote_forzado_valido ) echo 'disabled'; ?>>Guardar Escaneo</button>
            </form>

            <div id="mm-mensaje-estado" style="margin-top: 15px; text-align: center; font-weight: bold; min-height: 24px; padding: 10px; border-radius: 4px;"></div>
        </div>

        <?php
        return ob_get_clean();
    }
}

new MM_Logistica_Frontend();
