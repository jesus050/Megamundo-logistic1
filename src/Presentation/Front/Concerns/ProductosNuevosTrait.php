<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait ProductosNuevosTrait {
    private function get_productos_nuevos_para_revision() {
        $args = array(
            'post_type'      => 'product',
            'post_status'    => array( 'draft', 'pending', 'publish' ),
            'posts_per_page' => 80,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_mm_producto_nuevo_lote',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_mm_ia_nombre_sugerido',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'     => '_mm_requiere_revision',
                    'value'   => '1',
                    'compare' => '=',
                ),
            ),
        );

        return get_posts( $args );
    }

    private function get_producto_revision_data( $product_id ) {
        $product_id = intval( $product_id );

        return array(
            'nombre_ia'       => get_post_meta( $product_id, '_mm_ia_nombre_sugerido', true ),
            'categoria_ia'    => get_post_meta( $product_id, '_mm_ia_categoria_sugerida', true ),
            'descripcion_ia'  => get_post_meta( $product_id, '_mm_ia_descripcion_corta', true ),
            'palabras_ia'     => get_post_meta( $product_id, '_mm_ia_palabras_clave', true ),
            'marca_ia'        => get_post_meta( $product_id, '_mm_ia_marca_detectada', true ),
            'observaciones'   => get_post_meta( $product_id, '_mm_ia_observaciones', true ),
            'confianza'       => get_post_meta( $product_id, '_mm_ia_confianza', true ),
            'lote_id'         => absint( get_post_meta( $product_id, '_mm_producto_nuevo_lote', true ) ),
            'revision_estado' => get_post_meta( $product_id, '_mm_revision_estado', true ) ?: 'pendiente',
        );
    }

    public function ajax_guardar_revision_producto_nuevo() {
        if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para revisar productos nuevos.' ), 403 );
        }

        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $nonce      = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $product_id || ! wp_verify_nonce( $nonce, 'mm_producto_nuevo_' . $product_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado o sesión vencida.' ), 403 );
        }

        if ( 'product' !== get_post_type( $product_id ) ) {
            wp_send_json_error( array( 'message' => 'Producto inválido.' ), 404 );
        }

        $nombre      = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
        $descripcion = isset( $_POST['descripcion'] ) ? sanitize_textarea_field( wp_unslash( $_POST['descripcion'] ) ) : '';
        $categoria   = isset( $_POST['categoria'] ) ? sanitize_text_field( wp_unslash( $_POST['categoria'] ) ) : '';
        $palabras    = isset( $_POST['palabras'] ) ? sanitize_text_field( wp_unslash( $_POST['palabras'] ) ) : '';

        if ( empty( $nombre ) ) {
            wp_send_json_error( array( 'message' => 'El nombre del producto es obligatorio.' ), 400 );
        }

        wp_update_post( array(
            'ID'           => $product_id,
            'post_title'   => $nombre,
            'post_excerpt' => $descripcion,
        ) );

        update_post_meta( $product_id, '_mm_revision_estado', 'revisado' );
        update_post_meta( $product_id, '_mm_revision_at', current_time( 'mysql' ) );
        update_post_meta( $product_id, '_mm_revision_by', get_current_user_id() );
        update_post_meta( $product_id, '_mm_categoria_revisada', $categoria );
        update_post_meta( $product_id, '_mm_palabras_clave_revisadas', $palabras );
        update_post_meta( $product_id, '_mm_requiere_revision', '0' );

        
            if ( ! $this->mm_producto_tiene_imagen_upload() ) {
                $mm_codigo_tmp = isset( $_POST['codigo'] ) ? sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) : ( isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '' );
                $mm_producto_tmp = isset( $_POST['producto'] ) ? sanitize_text_field( wp_unslash( $_POST['producto'] ) ) : ( isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '' );
                $this->mm_registrar_producto_sin_imagen( $mm_codigo_tmp, $mm_producto_tmp, 'bodega' );
            }

wp_send_json_success( array(
            'message' => 'Producto revisado y guardado correctamente.',
        ) );
    }

    private function render_productos_nuevos_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para revisar productos nuevos.' );
        }

        $products = $this->get_productos_nuevos_para_revision();
        $pendientes = 0;
        $revisados = 0;

        foreach ( $products as $product ) {
            $estado = get_post_meta( $product->ID, '_mm_revision_estado', true ) ?: 'pendiente';
            if ( 'revisado' === $estado ) {
                $revisados++;
            } else {
                $pendientes++;
            }
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'productos-nuevos' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Productos nuevos + IA</span>
                        <h1>Revisión de productos nuevos</h1>
                        <p>Revisa los productos creados desde bodega, corrige nombre, descripción, categoría y palabras clave antes de dejarlos listos.</p>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Total productos</small><strong><?php echo esc_html( count( $products ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Pendientes</small><strong><?php echo esc_html( $pendientes ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Revisados</small><strong><?php echo esc_html( $revisados ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Fuente</small><strong>IA + Bodega</strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Productos por revisar</h2>
                        <span>La IA sugiere. El personal confirma.</span>
                    </div>

                    <div class="mm-new-products-grid">
                        <?php if ( empty( $products ) ) : ?>
                            <div class="mm-empty-state">No hay productos nuevos pendientes de revisión.</div>
                        <?php endif; ?>

                        <?php foreach ( $products as $product ) :
                            $data = $this->get_producto_revision_data( $product->ID );
                            $thumb = get_the_post_thumbnail_url( $product->ID, 'medium' );
                            $nonce = wp_create_nonce( 'mm_producto_nuevo_' . $product->ID );
                            $edit_url = admin_url( 'post.php?post=' . $product->ID . '&action=edit' );
                        ?>
                            <article class="mm-new-product-review-card" data-product-id="<?php echo esc_attr( $product->ID ); ?>">
                                <div class="mm-new-product-image">
                                    <?php if ( $thumb ) : ?>
                                        <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( get_the_title( $product->ID ) ); ?>">
                                    <?php else : ?>
                                        <span>Sin foto</span>
                                    <?php endif; ?>
                                </div>

                                <form class="mm-product-review-form">
                                    <input type="hidden" name="product_id" value="<?php echo esc_attr( $product->ID ); ?>">
                                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                                    <div class="mm-product-review-head">
                                        <span class="mm-badge-soft <?php echo 'revisado' === $data['revision_estado'] ? '' : 'mm-badge-warn'; ?>">
                                            <?php echo esc_html( ucfirst( $data['revision_estado'] ) ); ?>
                                        </span>
                                        <?php if ( $data['lote_id'] ) : ?>
                                            <small>Lote #<?php echo esc_html( $data['lote_id'] ); ?></small>
                                        <?php endif; ?>
                                    </div>

                                    <label>Nombre del producto
                                        <input class="mm-input" type="text" name="nombre" value="<?php echo esc_attr( get_the_title( $product->ID ) ?: $data['nombre_ia'] ); ?>" placeholder="Nombre comercial">
                                    </label>

                                    <label>Categoría sugerida / revisada
                                        <input class="mm-input" type="text" name="categoria" value="<?php echo esc_attr( $data['categoria_ia'] ); ?>" placeholder="Ej: Hogar / Cocina">
                                    </label>

                                    <label>Descripción corta
                                        <textarea class="mm-input mm-textarea" name="descripcion" rows="4" placeholder="Descripción para WooCommerce"><?php echo esc_textarea( $product->post_excerpt ?: $data['descripcion_ia'] ); ?></textarea>
                                    </label>

                                    <label>Palabras clave
                                        <input class="mm-input" type="text" name="palabras" value="<?php echo esc_attr( $data['palabras_ia'] ); ?>" placeholder="palabra1, palabra2, palabra3">
                                    </label>

                                    <div class="mm-ai-observations">
                                        <strong>Lectura IA</strong>
                                        <p><b>Marca:</b> <?php echo esc_html( $data['marca_ia'] ?: 'No detectada' ); ?></p>
                                        <p><b>Observaciones:</b> <?php echo esc_html( $data['observaciones'] ?: 'Sin observaciones.' ); ?></p>
                                        <?php if ( $data['confianza'] ) : ?><p><b>Confianza:</b> <?php echo esc_html( $data['confianza'] ); ?></p><?php endif; ?>
                                    </div>

                                    <div class="mm-product-review-actions">
                                        <button type="submit" class="mm-mini-primary">Guardar revisión</button>
                                        <a class="mm-mini-secondary" href="<?php echo esc_url( $edit_url ); ?>" target="_blank" rel="noopener">Abrir en WooCommerce</a>
                                    </div>
                                    <div class="mm-product-review-msg" hidden></div>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }
}
