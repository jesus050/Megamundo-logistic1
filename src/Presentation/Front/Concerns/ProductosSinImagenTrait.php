<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait ProductosSinImagenTrait {
    private function mm_get_productos_sin_imagen() {
        $items = get_option( 'mm_productos_sin_imagen', array() );
        return is_array( $items ) ? $items : array();
    }

    private function mm_save_productos_sin_imagen( $items ) {
        update_option( 'mm_productos_sin_imagen', array_values( $items ) );
    }

    private function mm_registrar_producto_sin_imagen( $codigo, $producto, $origen = 'bodega' ) {
        $codigo = sanitize_text_field( (string) $codigo );
        $producto = sanitize_text_field( (string) $producto );

        if ( '' === $codigo && '' === $producto ) {
            return;
        }

        $items = $this->mm_get_productos_sin_imagen();
        $key = '' !== $codigo ? 'sku:' . $codigo : 'nombre:' . strtolower( $producto );

        foreach ( $items as &$item ) {
            $item_key = ! empty( $item['codigo'] ) ? 'sku:' . $item['codigo'] : 'nombre:' . strtolower( $item['producto'] ?? '' );
            if ( $item_key === $key ) {
                $item['producto'] = $producto ?: ( $item['producto'] ?? '' );
                $item['updated_at'] = current_time( 'mysql' );
                return;
            }
        }
        unset( $item );

        $items[] = array(
            'codigo' => $codigo,
            'producto' => $producto,
            'origen' => sanitize_text_field( $origen ),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        );

        $this->mm_save_productos_sin_imagen( $items );
    }

    private function mm_producto_tiene_imagen_upload() {
        foreach ( array( 'producto_imagen', 'imagen_producto', 'foto_producto', 'producto_foto', 'image', 'foto' ) as $field ) {
            if ( ! empty( $_FILES[ $field ]['name'] ) && ! empty( $_FILES[ $field ]['tmp_name'] ) ) {
                return true;
            }
        }
        return false;
    }

    private function mm_render_productos_sin_imagen_card() {
        $count = count( $this->mm_get_productos_sin_imagen() );
        ob_start(); ?>
        <section class="mm-platform-section mm-no-image-alert">
            <div class="mm-section-head">
                <h2>📷 Productos sin imagen</h2>
                <span>Pendientes para completar catálogo, bodega y exhibición.</span>
            </div>
            <div class="mm-no-image-summary">
                <div>
                    <small>Pendientes</small>
                    <strong><?php echo esc_html( $count ); ?></strong>
                    <span>productos necesitan fotografía</span>
                </div>
                <a class="mm-mini-primary" href="<?php echo esc_url( add_query_arg( 'mm_logistica_app', 'productos_sin_imagen', home_url( '/' ) ) ); ?>">Ver pendientes</a>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    private function render_productos_sin_imagen() {
        $items = $this->mm_get_productos_sin_imagen();
        ob_start(); ?>
        <main class="mm-platform-main">
            <section class="mm-dashboard-hero">
                <div>
                    <span class="mm-eyebrow">Calidad visual</span>
                    <h1>Productos pendientes de fotografía</h1>
                    <p>Productos guardados sin imagen. Puedes completar la foto después sin detener bodega.</p>
                </div>
            </section>
            <section class="mm-platform-section">
                <div class="mm-section-head">
                    <h2>Listado</h2>
                    <span>Marca como resuelto cuando ya tenga imagen.</span>
                </div>
                <?php if ( empty( $items ) ) : ?>
                    <div class="mm-empty-state">No hay productos pendientes de imagen.</div>
                <?php else : ?>
                    <div class="mm-no-image-table-wrap">
                        <table class="mm-no-image-table">
                            <thead><tr><th>Estado</th><th>Código</th><th>Producto</th><th>Origen</th><th>Fecha</th><th>Acción</th></tr></thead>
                            <tbody>
                            <?php foreach ( $items as $index => $item ) : ?>
                                <tr>
                                    <td><span class="mm-image-status is-missing">🟡 Sin imagen</span></td>
                                    <td><?php echo esc_html( $item['codigo'] ?? '' ); ?></td>
                                    <td><strong><?php echo esc_html( $item['producto'] ?? '' ); ?></strong></td>
                                    <td><?php echo esc_html( $item['origen'] ?? '' ); ?></td>
                                    <td><?php echo esc_html( $item['created_at'] ?? '' ); ?></td>
                                    <td><button class="mm-mini-secondary mm-resolver-producto-sin-imagen" data-index="<?php echo esc_attr( $index ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'mm_resolver_producto_sin_imagen' ) ); ?>">Marcar resuelto</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <?php return ob_get_clean();
    }

    public function ajax_resolver_producto_sin_imagen() {
        if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_bodega_panel() && ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para resolver productos sin imagen.' ), 403 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_resolver_producto_sin_imagen' ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
        }

        $index = isset( $_POST['index'] ) ? intval( $_POST['index'] ) : -1;
        $items = $this->mm_get_productos_sin_imagen();

        if ( ! isset( $items[ $index ] ) ) {
            wp_send_json_error( array( 'message' => 'Producto no encontrado.' ), 404 );
        }

        unset( $items[ $index ] );
        $this->mm_save_productos_sin_imagen( $items );

        
            if ( ! $this->mm_producto_tiene_imagen_upload() ) {
                $mm_codigo_tmp = isset( $_POST['codigo'] ) ? sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) : ( isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '' );
                $mm_producto_tmp = isset( $_POST['producto'] ) ? sanitize_text_field( wp_unslash( $_POST['producto'] ) ) : ( isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '' );
                $this->mm_registrar_producto_sin_imagen( $mm_codigo_tmp, $mm_producto_tmp, 'bodega' );
            }

wp_send_json_success( array( 'message' => 'Producto marcado como resuelto.' ) );
    }
}
