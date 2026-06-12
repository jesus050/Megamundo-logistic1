<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait ExhibicionTrait {
    private function mm_get_exhibicion_items() {
        $items = get_option( 'mm_exhibicion_items', array() );
        return is_array( $items ) ? $items : array();
    }

    private function mm_save_exhibicion_items( $items ) {
        update_option( 'mm_exhibicion_items', array_values( $items ) );
    }

    private function can_access_exhibicion_panel() {
        return is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( 'edit_posts' ) );
    }

    private function render_exhibicion() {
        if ( ! $this->can_access_exhibicion_panel() ) {
            return method_exists( $this, 'render_access_denied' ) ? $this->render_access_denied() : '<main class="mm-platform-main"><div class="mm-platform-section">No tienes permiso.</div></main>';
        }

        $items = $this->mm_get_exhibicion_items();

        $total_exhibicion = 0;
        $total_bodega = 0;
        $alertas = 0;

        foreach ( $items as $item ) {
            $ex = intval( $item['exhibicion'] ?? 0 );
            $bo = intval( $item['bodega'] ?? 0 );
            $min = intval( $item['minimo'] ?? 0 );
            $total_exhibicion += $ex;
            $total_bodega += $bo;
            if ( $min > 0 && $ex <= $min ) {
                $alertas++;
            }
        }

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'exhibicion' ); ?>
            <section class="mm-platform-main">
            <section class="mm-dashboard-hero">
                <div>
                    <span class="mm-eyebrow">Control físico</span>
                    <h1>Exhibición y Bodega</h1>
                    <p>Registra cuánto producto está en exhibición, cuánto queda guardado y qué se debe reponer.</p>
                </div>
            </section>
            <section class="mm-platform-section">
                <div class="mm-section-head">
                    <h2>Flujo recomendado</h2>
                    <span>La exhibición debe alimentarse desde los productos recibidos en Bodega.</span>
                </div>
                <div class="mm-safe-note">
                    <strong>Uso correcto:</strong>
                    <p>Primero Bodega recibe el lote. Luego se mueve una parte a exhibición para controlar qué está en piso y qué sigue guardado.</p>
                </div>
            </section>


            <section class="mm-kpi-grid">
                <article class="mm-kpi-card">
                    <span>Referencias</span>
                    <strong><?php echo esc_html( count( $items ) ); ?></strong>
                </article>
                <article class="mm-kpi-card">
                    <span>Unidades en exhibición</span>
                    <strong><?php echo esc_html( $total_exhibicion ); ?></strong>
                </article>
                <article class="mm-kpi-card">
                    <span>Unidades en bodega</span>
                    <strong><?php echo esc_html( $total_bodega ); ?></strong>
                </article>
                <article class="mm-kpi-card <?php echo $alertas ? 'is-warning' : ''; ?>">
                    <span>Alertas de reposición</span>
                    <strong><?php echo esc_html( $alertas ); ?></strong>
                </article>
            </section>

            <section class="mm-platform-section">
                <div class="mm-section-head">
                    <h2>Registrar producto en exhibición</h2>
                    <span>Usa esto para crear o actualizar el control físico del producto.</span>
                </div>

                <form class="mm-exhibicion-form">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_exhibicion' ) ); ?>">

                    <div class="mm-exhibicion-form-grid">
                        <label>Código / SKU
                            <input class="mm-input" type="text" name="codigo" placeholder="Código de barras o referencia">
                        </label>
                        <label>Producto
                            <input class="mm-input" type="text" name="producto" placeholder="Nombre del producto">
                        </label>
                        <label>En exhibición
                            <input class="mm-input" type="number" name="exhibicion" min="0" step="1" value="0">
                        </label>
                        <label>En bodega
                            <input class="mm-input" type="number" name="bodega" min="0" step="1" value="0">
                        </label>
                        <label>Mínimo en exhibición
                            <input class="mm-input" type="number" name="minimo" min="0" step="1" value="0">
                        </label>
                        <label>Observación
                            <input class="mm-input" type="text" name="observacion" placeholder="Ubicación, estante, nota">
                        </label>
                    </div>

                    <button type="submit" class="mm-mini-primary">Guardar control</button>
                    <div class="mm-exhibicion-msg" hidden></div>
                </form>
            </section>

            <section class="mm-platform-section">
                <div class="mm-section-head">
                    <h2>Inventario físico</h2>
                    <span>Exhibición + Bodega = total físico registrado.</span>
                </div>

                <?php if ( empty( $items ) ) : ?>
                    <div class="mm-empty-state">Todavía no hay productos registrados en exhibición.</div>
                <?php else : ?>
                    <div class="mm-exhibicion-table-wrap">
                        <table class="mm-exhibicion-table">
                            <thead>
                                <tr>
                                    <th>Estado</th>
                                    <th>Código</th>
                                    <th>Producto</th>
                                    <th>Exhibición</th>
                                    <th>Bodega</th>
                                    <th>Total</th>
                                    <th>Mínimo</th>
                                    <th>Estado</th>
                                    <th>Movimiento rápido</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $items as $index => $item ) :
                                    $ex = intval( $item['exhibicion'] ?? 0 );
                                    $bo = intval( $item['bodega'] ?? 0 );
                                    $min = intval( $item['minimo'] ?? 0 );
                                    $estado = ( $min > 0 && $ex <= $min ) ? 'Reponer' : 'OK';
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( $item['codigo'] ?? '' ); ?></td>
                                        <td>
                                            <strong><?php echo esc_html( $item['producto'] ?? '' ); ?></strong>
                                            <small><?php echo esc_html( $item['observacion'] ?? '' ); ?></small>
                                        </td>
                                        <td><?php echo esc_html( $ex ); ?></td>
                                        <td><?php echo esc_html( $bo ); ?></td>
                                        <td><?php echo esc_html( $ex + $bo ); ?></td>
                                        <td><?php echo esc_html( $min ); ?></td>
                                        <td><span class="mm-exhibicion-status <?php echo 'Reponer' === $estado ? 'is-warning' : 'is-ok'; ?>"><?php echo esc_html( $estado ); ?></span></td>
                                        <td>
                                            <form class="mm-exhibicion-move-form">
                                                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'mm_exhibicion' ) ); ?>">
                                                <input type="hidden" name="index" value="<?php echo esc_attr( $index ); ?>">
                                                <input class="mm-input" type="number" name="cantidad" min="1" step="1" placeholder="Cant.">
                                                <select class="mm-input" name="direccion">
                                                    <option value="bodega_a_exhibicion">Bodega → Exhibición</option>
                                                    <option value="exhibicion_a_bodega">Exhibición → Bodega</option>
                                                </select>
                                                <button type="submit" class="mm-mini-secondary">Mover</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
            </section>
        </main>
        <?php return ob_get_clean();
    }

    public function ajax_guardar_exhibicion() {
        try {
            if ( ! $this->can_access_exhibicion_panel() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para editar exhibición.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_exhibicion' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida.' ), 403 );
            }

            $codigo = isset( $_POST['codigo'] ) ? sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) : '';
            $producto = isset( $_POST['producto'] ) ? sanitize_text_field( wp_unslash( $_POST['producto'] ) ) : '';
            $exhibicion = isset( $_POST['exhibicion'] ) ? intval( $_POST['exhibicion'] ) : 0;
            $bodega = isset( $_POST['bodega'] ) ? intval( $_POST['bodega'] ) : 0;
            $minimo = isset( $_POST['minimo'] ) ? intval( $_POST['minimo'] ) : 0;
            $observacion = isset( $_POST['observacion'] ) ? sanitize_text_field( wp_unslash( $_POST['observacion'] ) ) : '';

            if ( '' === $codigo && '' === $producto ) {
                wp_send_json_error( array( 'message' => 'Escribe al menos código o nombre del producto.' ), 400 );
            }

            $items = $this->mm_get_exhibicion_items();
            $found = false;

            foreach ( $items as &$item ) {
                $same_code = '' !== $codigo && isset( $item['codigo'] ) && $item['codigo'] === $codigo;
                $same_name = '' === $codigo && '' !== $producto && strtolower( $item['producto'] ?? '' ) === strtolower( $producto );

                if ( $same_code || $same_name ) {
                    $item['codigo'] = $codigo ?: ( $item['codigo'] ?? '' );
                    $item['producto'] = $producto ?: ( $item['producto'] ?? '' );
                    $item['exhibicion'] = max( 0, $exhibicion );
                    $item['bodega'] = max( 0, $bodega );
                    $item['minimo'] = max( 0, $minimo );
                    $item['observacion'] = $observacion;
                    $item['updated_at'] = current_time( 'mysql' );
                    $found = true;
                    break;
                }
            }
            unset( $item );

            if ( ! $found ) {
                $items[] = array(
                    'codigo' => $codigo,
                    'producto' => $producto,
                    'exhibicion' => max( 0, $exhibicion ),
                    'bodega' => max( 0, $bodega ),
                    'minimo' => max( 0, $minimo ),
                    'observacion' => $observacion,
                    'created_at' => current_time( 'mysql' ),
                    'updated_at' => current_time( 'mysql' ),
                );
            }

            $this->mm_save_exhibicion_items( $items );

            wp_send_json_success( array( 'message' => 'Control de exhibición guardado.' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => 'Error guardando exhibición: ' . $e->getMessage() ), 500 );
        }
    }

    public function ajax_mover_exhibicion() {
        try {
            if ( ! $this->can_access_exhibicion_panel() ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para mover inventario.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_exhibicion' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida.' ), 403 );
            }

            $index = isset( $_POST['index'] ) ? intval( $_POST['index'] ) : -1;
            $cantidad = isset( $_POST['cantidad'] ) ? intval( $_POST['cantidad'] ) : 0;
            $direccion = isset( $_POST['direccion'] ) ? sanitize_text_field( wp_unslash( $_POST['direccion'] ) ) : '';

            if ( $cantidad <= 0 ) {
                wp_send_json_error( array( 'message' => 'La cantidad debe ser mayor a 0.' ), 400 );
            }

            $items = $this->mm_get_exhibicion_items();

            if ( ! isset( $items[ $index ] ) ) {
                wp_send_json_error( array( 'message' => 'Producto no encontrado.' ), 404 );
            }

            $ex = intval( $items[ $index ]['exhibicion'] ?? 0 );
            $bo = intval( $items[ $index ]['bodega'] ?? 0 );

            if ( 'bodega_a_exhibicion' === $direccion ) {
                if ( $bo < $cantidad ) {
                    wp_send_json_error( array( 'message' => 'No hay suficiente cantidad en bodega.' ), 400 );
                }
                $bo -= $cantidad;
                $ex += $cantidad;
            } elseif ( 'exhibicion_a_bodega' === $direccion ) {
                if ( $ex < $cantidad ) {
                    wp_send_json_error( array( 'message' => 'No hay suficiente cantidad en exhibición.' ), 400 );
                }
                $ex -= $cantidad;
                $bo += $cantidad;
            } else {
                wp_send_json_error( array( 'message' => 'Movimiento no válido.' ), 400 );
            }

            $items[ $index ]['exhibicion'] = $ex;
            $items[ $index ]['bodega'] = $bo;
            $items[ $index ]['updated_at'] = current_time( 'mysql' );

            $this->mm_save_exhibicion_items( $items );

            wp_send_json_success( array( 'message' => 'Movimiento registrado.' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( array( 'message' => 'Error moviendo inventario: ' . $e->getMessage() ), 500 );
        }
    }

    private function render_exhibicion_dashboard() {
        return $this->render_exhibicion();
    }

    private function mm45_apply_lote_to_exhibicion_stock( $lote_id ) {
        $lote_id = intval( $lote_id );
        $received = $this->mm45_received_products_from_lote( $lote_id );
        $items = get_option( 'mm_exhibicion_items', array() );
        $items = is_array( $items ) ? $items : array();
        $updated = 0;
        foreach ( $received as $p ) {
            $codigo = sanitize_text_field( $p['codigo'] ?? '' );
            $nombre = sanitize_text_field( $p['nombre'] ?? '' );
            $cantidad = intval( $p['recibido'] ?? 0 );
            if ( $cantidad <= 0 || ( ! $codigo && ! $nombre ) ) { continue; }
            $found = false;
            foreach ( $items as &$it ) {
                $same_code = $codigo && isset($it['codigo']) && $it['codigo'] === $codigo;
                $same_name = ! $codigo && $nombre && strtolower($it['producto'] ?? '') === strtolower($nombre);
                if ( $same_code || $same_name ) {
                    $it['codigo'] = $codigo ?: ($it['codigo'] ?? '');
                    $it['producto'] = $nombre ?: ($it['producto'] ?? '');
                    $it['bodega'] = intval($it['bodega'] ?? 0) + $cantidad;
                    $it['exhibicion'] = intval($it['exhibicion'] ?? 0);
                    $it['minimo'] = intval($it['minimo'] ?? 0);
                    $it['observacion'] = sanitize_text_field($p['observacion'] ?? ($it['observacion'] ?? ''));
                    $it['updated_at'] = current_time('mysql');
                    $found = true; $updated++;
                    break;
                }
            }
            unset($it);
            if ( ! $found ) {
                $items[] = array('codigo'=>$codigo,'producto'=>$nombre,'exhibicion'=>0,'bodega'=>$cantidad,'minimo'=>0,'observacion'=>sanitize_text_field($p['observacion'] ?? ''),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'));
                $updated++;
            }
        }
        update_option( 'mm_exhibicion_items', array_values($items) );
        update_option( 'mm_lote_' . $lote_id . '_inventario_aplicado', current_time('mysql') );
        update_post_meta( $lote_id, '_mm_lote_estado', 'bodega_finalizada' );
        update_post_meta( $lote_id, '_mm_bodega_finalizada', current_time('mysql') );
        return $updated;
    }
}
