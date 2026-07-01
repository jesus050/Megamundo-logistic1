<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Módulo: Sistema de Ubicaciones e Inventario Físico.
 *
 * Apoyo operativo para saber qué producto hay, cuánto hay y dónde está.
 * NO reemplaza a Mekano: exporta un CSV para ajustar/comparar contra Mekano.
 *
 * Pantallas: conteo físico (escáner), ubicaciones, equivalencias, productos contados.
 * Todos los handlers verifican login + capacidad (bodega/jefatura) + nonce.
 */
trait InventarioFisicoTrait {

    private function can_access_inventario_fisico() {
        return is_user_logged_in() && (
            $this->permission_guard->can_access_bodega_panel()
            || $this->permission_guard->can_access_jefatura_panel()
        );
    }

    /** Nonce único del módulo (todas las acciones comparten la misma capacidad). */
    private function inv_fisico_nonce() {
        return wp_create_nonce( 'mm_inventario_fisico' );
    }

    private function verify_inv_fisico_request() {
        if ( ! $this->can_access_inventario_fisico() ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para el inventario físico.' ), 403 );
        }
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_inventario_fisico' ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
        }
    }

    /* =====================================================================
     * RENDER: Conteo físico (pantalla tipo app, optimizada para celular)
     * ===================================================================== */

    private function render_inventario_fisico_dashboard() {
        if ( ! $this->can_access_inventario_fisico() ) {
            return $this->render_denied_app( 'No tienes permiso para el inventario físico.' );
        }

        $ubicaciones  = $this->ubicacion_repo->all( true );
        $equivalencias = $this->equivalencia_repo->all( true );
        $contados     = $this->inventario_fisico_repo->all();
        $total_unidades = 0;
        foreach ( $contados as $c ) { $total_unidades += intval( $c->cantidad_unidades ); }
        $nonce = $this->inv_fisico_nonce();

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'inventario-fisico' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Inventario físico</span>
                        <h1>Conteo de bodega</h1>
                        <p>Escanea o escribe el código, elige la ubicación y la presentación. El sistema calcula las unidades por ti.</p>
                    </div>
                </header>

                <section class="mm-kpi-grid">
                    <article class="mm-kpi-card"><span>Ubicaciones activas</span><strong><?php echo esc_html( count( $ubicaciones ) ); ?></strong></article>
                    <article class="mm-kpi-card"><span>Conteos registrados</span><strong><?php echo esc_html( count( $contados ) ); ?></strong></article>
                    <article class="mm-kpi-card"><span>Total unidades contadas</span><strong><?php echo esc_html( $total_unidades ); ?></strong></article>
                </section>

                <?php if ( empty( $ubicaciones ) || empty( $equivalencias ) ) : ?>
                    <section class="mm-platform-section">
                        <div class="mm-safe-note">
                            <strong>Antes de contar:</strong>
                            <p>
                                <?php if ( empty( $ubicaciones ) ) : ?>Crea al menos una <a href="<?php echo esc_url( home_url( '/?mm_logistica_app=ubicaciones' ) ); ?>">ubicación</a>. <?php endif; ?>
                                <?php if ( empty( $equivalencias ) ) : ?>Crea al menos una <a href="<?php echo esc_url( home_url( '/?mm_logistica_app=equivalencias' ) ); ?>">equivalencia</a>.<?php endif; ?>
                            </p>
                        </div>
                    </section>
                <?php else : ?>
                    <section class="mm-platform-section mm-inv-count-panel">
                        <div class="mm-section-head">
                            <h2>Registrar conteo</h2>
                            <span>Paso a paso. Botones grandes, poco texto.</span>
                        </div>

                        <form class="mm-inv-count-form" autocomplete="off">
                            <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                            <label class="mm-inv-field">📍 Ubicación
                                <select class="mm-input mm-inv-ubicacion" name="ubicacion_id" required>
                                    <option value="">Selecciona ubicación…</option>
                                    <?php foreach ( $ubicaciones as $u ) : ?>
                                        <option value="<?php echo esc_attr( $u->id ); ?>"><?php echo esc_html( $u->codigo . ' — ' . $u->nombre ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="mm-inv-field">🔎 Código / SKU (escanea o escribe)
                                <input class="mm-input mm-inv-sku" type="text" name="sku" inputmode="text" placeholder="Escanea el código de barras" autofocus>
                            </label>

                            <label class="mm-inv-field">🏷️ Nombre del producto
                                <input class="mm-input mm-inv-nombre" type="text" name="nombre_producto" placeholder="Nombre visible">
                            </label>

                            <label class="mm-inv-field">🗂️ Categoría
                                <input class="mm-input mm-inv-categoria" type="text" name="categoria" placeholder="Opcional">
                            </label>

                            <div class="mm-inv-count-row">
                                <label class="mm-inv-field">🔢 Cantidad
                                    <input class="mm-input mm-inv-cantidad" type="number" name="cantidad" min="0" step="1" value="1" inputmode="numeric">
                                </label>
                                <label class="mm-inv-field">📦 Presentación
                                    <select class="mm-input mm-inv-presentacion" name="presentacion">
                                        <?php foreach ( $equivalencias as $e ) : ?>
                                            <option value="<?php echo esc_attr( $e->nombre ); ?>" data-unidades="<?php echo esc_attr( $e->unidades ); ?>"><?php echo esc_html( ucfirst( $e->nombre ) . ' (×' . intval( $e->unidades ) . ')' ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <div class="mm-inv-total-box">
                                Total en unidades: <strong class="mm-inv-total-unidades">0</strong>
                            </div>

                            <label class="mm-inv-field">📝 Observación
                                <input class="mm-input mm-inv-observacion" type="text" name="observacion" placeholder="Opcional">
                            </label>

                            <div class="mm-inv-count-actions">
                                <button type="submit" class="mm-mini-primary mm-inv-btn-guardar">💾 Guardar conteo</button>
                                <a class="mm-mini-secondary" href="<?php echo esc_url( home_url( '/?mm_logistica_app=inventario-contados' ) ); ?>">📋 Ver productos contados</a>
                            </div>
                            <div class="mm-inv-count-msg" hidden></div>
                        </form>
                    </section>

                    <section class="mm-platform-section">
                        <div class="mm-section-head">
                            <h2>Contados en esta sesión</h2>
                            <span>Los últimos conteos guardados aparecerán aquí.</span>
                        </div>
                        <div class="mm-inv-session-list"><div class="mm-empty-state">Aún no has guardado conteos en esta pantalla.</div></div>
                    </section>
                <?php endif; ?>
            </section>
        </main>
        <?php return ob_get_clean();
    }

    /* =====================================================================
     * RENDER: Ubicaciones
     * ===================================================================== */

    private function render_ubicaciones_dashboard() {
        if ( ! $this->can_access_inventario_fisico() ) {
            return $this->render_denied_app( 'No tienes permiso para gestionar ubicaciones.' );
        }

        $ubicaciones = $this->ubicacion_repo->all();
        $nonce = $this->inv_fisico_nonce();

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'ubicaciones' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Inventario físico</span>
                        <h1>Ubicaciones de bodega</h1>
                        <p>Bodega › Zona › Pasillo › Estante › Nivel › Posición. Ejemplo de código: <code>BOD-01-A-03-02-D</code>.</p>
                    </div>
                </header>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Nueva ubicación</h2>
                        <span>El código se arma solo con las partes, o escríbelo a mano.</span>
                    </div>

                    <form class="mm-ubicacion-form" autocomplete="off">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                        <input type="hidden" name="id" value="0">

                        <div class="mm-ubicacion-parts">
                            <label>Bodega<input class="mm-input mm-ubic-part" data-part="bodega" type="text" name="bodega" placeholder="BOD-01"></label>
                            <label>Zona<input class="mm-input mm-ubic-part" data-part="zona" type="text" name="zona" placeholder="A / FIESTAS"></label>
                            <label>Pasillo<input class="mm-input mm-ubic-part" data-part="pasillo" type="text" name="pasillo" placeholder="03"></label>
                            <label>Estante<input class="mm-input mm-ubic-part" data-part="estante" type="text" name="estante" placeholder="02"></label>
                            <label>Nivel<input class="mm-input mm-ubic-part" data-part="nivel" type="text" name="nivel" placeholder="03"></label>
                            <label>Posición<input class="mm-input mm-ubic-part" data-part="posicion" type="text" name="posicion" placeholder="D"></label>
                        </div>

                        <div class="mm-ubicacion-grid">
                            <label>Código de ubicación
                                <input class="mm-input mm-ubic-codigo" type="text" name="codigo" placeholder="BOD-01-A-03-02-D" required>
                            </label>
                            <label>Nombre visible
                                <input class="mm-input" type="text" name="nombre" placeholder="Estante fiestas derecha">
                            </label>
                            <label>Categoría asociada
                                <input class="mm-input" type="text" name="categoria" placeholder="Fiestas, aseo, cocina…">
                            </label>
                            <label>Estado
                                <select class="mm-input" name="estado">
                                    <option value="activa">Activa</option>
                                    <option value="inactiva">Inactiva</option>
                                </select>
                            </label>
                        </div>

                        <label>Observación
                            <input class="mm-input" type="text" name="observacion" placeholder="Nota opcional">
                        </label>

                        <button type="submit" class="mm-mini-primary">Guardar ubicación</button>
                        <div class="mm-ubicacion-msg" hidden></div>
                    </form>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2>Ubicaciones registradas</h2>
                        <span><?php echo esc_html( count( $ubicaciones ) ); ?> en total</span>
                    </div>

                    <?php if ( empty( $ubicaciones ) ) : ?>
                        <div class="mm-empty-state">Todavía no hay ubicaciones. Crea la primera arriba.</div>
                    <?php else : ?>
                        <div class="mm-exhibicion-table-wrap">
                            <table class="mm-exhibicion-table">
                                <thead><tr><th>Código</th><th>Nombre</th><th>Categoría</th><th>Estado</th><th>Observación</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ( $ubicaciones as $u ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $u->codigo ); ?></strong></td>
                                        <td><?php echo esc_html( $u->nombre ); ?></td>
                                        <td><?php echo esc_html( $u->categoria ); ?></td>
                                        <td><span class="mm-exhibicion-status <?php echo 'activa' === $u->estado ? 'is-ok' : 'is-warning'; ?>"><?php echo esc_html( ucfirst( $u->estado ) ); ?></span></td>
                                        <td><?php echo esc_html( $u->observacion ); ?></td>
                                        <td><button class="mm-mini-secondary mm-ubicacion-delete" data-id="<?php echo esc_attr( $u->id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Eliminar</button></td>
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

    /* =====================================================================
     * RENDER: Equivalencias
     * ===================================================================== */

    private function render_equivalencias_dashboard() {
        if ( ! $this->can_access_inventario_fisico() ) {
            return $this->render_denied_app( 'No tienes permiso para gestionar equivalencias.' );
        }

        $equivalencias = $this->equivalencia_repo->all();
        $nonce = $this->inv_fisico_nonce();

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'equivalencias' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Inventario físico</span>
                        <h1>Equivalencias de presentación</h1>
                        <p>Cuántas unidades trae cada presentación. Ej: 1 docena = 12, 1 paca = 50, 1 caja = 144.</p>
                    </div>
                </header>

                <section class="mm-platform-section">
                    <div class="mm-section-head"><h2>Nueva equivalencia</h2></div>
                    <form class="mm-equivalencia-form" autocomplete="off">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                        <input type="hidden" name="id" value="0">
                        <div class="mm-ubicacion-grid">
                            <label>Nombre<input class="mm-input" type="text" name="nombre" placeholder="bolsa" required></label>
                            <label>Valor en unidades<input class="mm-input" type="number" name="unidades" min="1" step="1" value="1" required></label>
                            <label>Estado
                                <select class="mm-input" name="estado">
                                    <option value="activo">Activo</option>
                                    <option value="inactivo">Inactivo</option>
                                </select>
                            </label>
                        </div>
                        <button type="submit" class="mm-mini-primary">Guardar equivalencia</button>
                        <div class="mm-equivalencia-msg" hidden></div>
                    </form>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head"><h2>Equivalencias registradas</h2></div>
                    <?php if ( empty( $equivalencias ) ) : ?>
                        <div class="mm-empty-state">No hay equivalencias.</div>
                    <?php else : ?>
                        <div class="mm-exhibicion-table-wrap">
                            <table class="mm-exhibicion-table">
                                <thead><tr><th>Nombre</th><th>Unidades</th><th>Estado</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ( $equivalencias as $e ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( ucfirst( $e->nombre ) ); ?></strong></td>
                                        <td>×<?php echo esc_html( intval( $e->unidades ) ); ?></td>
                                        <td><span class="mm-exhibicion-status <?php echo 'activo' === $e->estado ? 'is-ok' : 'is-warning'; ?>"><?php echo esc_html( ucfirst( $e->estado ) ); ?></span></td>
                                        <td><button class="mm-mini-secondary mm-equivalencia-delete" data-id="<?php echo esc_attr( $e->id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Eliminar</button></td>
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

    /* =====================================================================
     * RENDER: Productos contados + exportación
     * ===================================================================== */

    private function render_inventario_contados_dashboard() {
        if ( ! $this->can_access_inventario_fisico() ) {
            return $this->render_denied_app( 'No tienes permiso para ver los conteos.' );
        }

        $contados = $this->inventario_fisico_repo->all();
        $total_unidades = 0;
        foreach ( $contados as $c ) { $total_unidades += intval( $c->cantidad_unidades ); }
        $nonce = $this->inv_fisico_nonce();
        $export_url = add_query_arg( array(
            'action' => 'mm_app_exportar_inventario_fisico',
            'nonce'  => wp_create_nonce( 'mm_inventario_fisico_export' ),
        ), admin_url( 'admin-ajax.php' ) );

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'inventario-contados' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Inventario físico</span>
                        <h1>Productos contados</h1>
                        <p>Qué producto hay, cuánto hay y dónde está. Exporta a Excel/CSV para comparar con Mekano.</p>
                    </div>
                    <div class="mm-hero-actions">
                        <a class="mm-mini-primary" href="<?php echo esc_url( $export_url ); ?>">📤 Exportar CSV para Mekano</a>
                    </div>
                </header>

                <section class="mm-kpi-grid">
                    <article class="mm-kpi-card"><span>Conteos</span><strong><?php echo esc_html( count( $contados ) ); ?></strong></article>
                    <article class="mm-kpi-card"><span>Total unidades</span><strong><?php echo esc_html( $total_unidades ); ?></strong></article>
                </section>

                <section class="mm-platform-section">
                    <div class="mm-section-head"><h2>Listado</h2></div>
                    <?php if ( empty( $contados ) ) : ?>
                        <div class="mm-empty-state">Todavía no hay productos contados.</div>
                    <?php else : ?>
                        <div class="mm-exhibicion-table-wrap">
                            <table class="mm-exhibicion-table">
                                <thead><tr><th>SKU</th><th>Producto</th><th>Categoría</th><th>Ubicación</th><th>Cant.</th><th>Present.</th><th>Equiv.</th><th>Unidades</th><th>Fecha</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ( $contados as $c ) : ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $c->sku ); ?></strong></td>
                                        <td><?php echo esc_html( $c->nombre_producto ); ?></td>
                                        <td><?php echo esc_html( $c->categoria ); ?></td>
                                        <td><?php echo esc_html( $c->ubicacion_codigo ?: '—' ); ?></td>
                                        <td><?php echo esc_html( intval( $c->cantidad ) ); ?></td>
                                        <td><?php echo esc_html( $c->presentacion ); ?></td>
                                        <td>×<?php echo esc_html( intval( $c->equivalencia ) ); ?></td>
                                        <td><strong><?php echo esc_html( intval( $c->cantidad_unidades ) ); ?></strong></td>
                                        <td><?php echo esc_html( mysql2date( 'd/m H:i', $c->counted_at ) ); ?></td>
                                        <td><button class="mm-mini-secondary mm-conteo-delete" data-id="<?php echo esc_attr( $c->id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Eliminar</button></td>
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

    /* =====================================================================
     * AJAX: Ubicaciones
     * ===================================================================== */

    public function ajax_guardar_ubicacion() {
        $this->verify_inv_fisico_request();

        $id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $codigo = isset( $_POST['codigo'] ) ? sanitize_text_field( wp_unslash( $_POST['codigo'] ) ) : '';

        if ( '' === $codigo ) {
            wp_send_json_error( array( 'message' => 'El código de ubicación es obligatorio.' ), 400 );
        }

        // Código único (salvo que sea la misma fila en edición).
        $existing = $this->ubicacion_repo->find_by_codigo( $codigo );
        if ( $existing && intval( $existing->id ) !== $id ) {
            wp_send_json_error( array( 'message' => 'Ya existe una ubicación con ese código.' ), 409 );
        }

        $saved = $this->ubicacion_repo->save( array(
            'codigo'      => $codigo,
            'nombre'      => wp_unslash( $_POST['nombre'] ?? '' ),
            'bodega'      => wp_unslash( $_POST['bodega'] ?? '' ),
            'zona'        => wp_unslash( $_POST['zona'] ?? '' ),
            'pasillo'     => wp_unslash( $_POST['pasillo'] ?? '' ),
            'estante'     => wp_unslash( $_POST['estante'] ?? '' ),
            'nivel'       => wp_unslash( $_POST['nivel'] ?? '' ),
            'posicion'    => wp_unslash( $_POST['posicion'] ?? '' ),
            'categoria'   => wp_unslash( $_POST['categoria'] ?? '' ),
            'estado'      => wp_unslash( $_POST['estado'] ?? 'activa' ),
            'observacion' => wp_unslash( $_POST['observacion'] ?? '' ),
        ), $id );

        if ( ! $saved ) {
            wp_send_json_error( array( 'message' => 'No se pudo guardar la ubicación.' ), 500 );
        }

        wp_send_json_success( array( 'message' => 'Ubicación guardada.', 'id' => $saved ) );
    }

    public function ajax_eliminar_ubicacion() {
        $this->verify_inv_fisico_request();
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id || ! $this->ubicacion_repo->delete( $id ) ) {
            wp_send_json_error( array( 'message' => 'No se pudo eliminar la ubicación.' ), 400 );
        }
        wp_send_json_success( array( 'message' => 'Ubicación eliminada.' ) );
    }

    /* =====================================================================
     * AJAX: Equivalencias
     * ===================================================================== */

    public function ajax_guardar_equivalencia() {
        $this->verify_inv_fisico_request();

        $id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $nombre   = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
        $unidades = isset( $_POST['unidades'] ) ? absint( $_POST['unidades'] ) : 0;

        if ( '' === $nombre || $unidades < 1 ) {
            wp_send_json_error( array( 'message' => 'Escribe un nombre y un valor en unidades (mínimo 1).' ), 400 );
        }

        $existing = $this->equivalencia_repo->find_by_nombre( $nombre );
        if ( $existing && intval( $existing->id ) !== $id ) {
            wp_send_json_error( array( 'message' => 'Ya existe una equivalencia con ese nombre.' ), 409 );
        }

        $saved = $this->equivalencia_repo->save( array(
            'nombre'   => $nombre,
            'unidades' => $unidades,
            'estado'   => wp_unslash( $_POST['estado'] ?? 'activo' ),
        ), $id );

        if ( ! $saved ) {
            wp_send_json_error( array( 'message' => 'No se pudo guardar la equivalencia.' ), 500 );
        }

        wp_send_json_success( array( 'message' => 'Equivalencia guardada.', 'id' => $saved ) );
    }

    public function ajax_eliminar_equivalencia() {
        $this->verify_inv_fisico_request();
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id || ! $this->equivalencia_repo->delete( $id ) ) {
            wp_send_json_error( array( 'message' => 'No se pudo eliminar la equivalencia.' ), 400 );
        }
        wp_send_json_success( array( 'message' => 'Equivalencia eliminada.' ) );
    }

    /* =====================================================================
     * AJAX: Conteo físico
     * ===================================================================== */

    public function ajax_guardar_conteo() {
        $this->verify_inv_fisico_request();

        $sku          = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
        $nombre       = isset( $_POST['nombre_producto'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre_producto'] ) ) : '';
        $ubicacion_id = isset( $_POST['ubicacion_id'] ) ? absint( $_POST['ubicacion_id'] ) : 0;
        $cantidad     = isset( $_POST['cantidad'] ) ? absint( $_POST['cantidad'] ) : 0;
        $presentacion = isset( $_POST['presentacion'] ) ? sanitize_text_field( wp_unslash( $_POST['presentacion'] ) ) : 'unidad';

        if ( '' === $sku && '' === $nombre ) {
            wp_send_json_error( array( 'message' => 'Escanea/escribe el código o el nombre del producto.' ), 400 );
        }
        if ( ! $ubicacion_id || ! $this->ubicacion_repo->find( $ubicacion_id ) ) {
            wp_send_json_error( array( 'message' => 'Selecciona una ubicación válida.' ), 400 );
        }
        if ( $cantidad < 1 ) {
            wp_send_json_error( array( 'message' => 'La cantidad debe ser mayor a 0.' ), 400 );
        }

        // La equivalencia se resuelve en servidor desde la tabla (no confiar en el cliente).
        $equiv = $this->equivalencia_repo->find_by_nombre( $presentacion );
        $equivalencia = $equiv ? intval( $equiv->unidades ) : 1;

        $id = $this->inventario_fisico_repo->insert( array(
            'sku'             => $sku,
            'nombre_producto' => $nombre,
            'categoria'       => wp_unslash( $_POST['categoria'] ?? '' ),
            'ubicacion_id'    => $ubicacion_id,
            'cantidad'        => $cantidad,
            'presentacion'    => $presentacion,
            'equivalencia'    => $equivalencia,
            'observacion'     => wp_unslash( $_POST['observacion'] ?? '' ),
        ) );

        if ( ! $id ) {
            wp_send_json_error( array( 'message' => 'No se pudo guardar el conteo.' ), 500 );
        }

        $total = $cantidad * $equivalencia;
        wp_send_json_success( array(
            'message' => 'Conteo guardado: ' . $total . ' unidades.',
            'id'      => $id,
            'total_unidades' => $total,
            'sku'     => $sku,
            'nombre'  => $nombre,
        ) );
    }

    public function ajax_eliminar_conteo() {
        $this->verify_inv_fisico_request();
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id || ! $this->inventario_fisico_repo->delete( $id ) ) {
            wp_send_json_error( array( 'message' => 'No se pudo eliminar el conteo.' ), 400 );
        }
        wp_send_json_success( array( 'message' => 'Conteo eliminado.' ) );
    }

    /* =====================================================================
     * Exportación CSV para Mekano (comparar / ajustar, NO carga automática)
     * ===================================================================== */

    public function ajax_exportar_inventario_fisico() {
        if ( ! $this->can_access_inventario_fisico() ) {
            wp_die( 'No tienes permiso para exportar el inventario físico.', 403 );
        }
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_inventario_fisico_export' ) ) {
            wp_die( 'Acceso no autorizado.', 403 );
        }

        $rows = $this->inventario_fisico_repo->all();

        while ( ob_get_level() ) { ob_end_clean(); }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="inventario-fisico-' . gmdate( 'Ymd-His' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        // BOM para que Excel abra bien los acentos.
        fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
        fputcsv( $out, array( 'SKU', 'Nombre producto', 'Categoria', 'Cantidad final unidades', 'Ubicacion fisica', 'Presentacion', 'Equivalencia', 'Observacion' ) );

        foreach ( $rows as $r ) {
            fputcsv( $out, array(
                $r->sku,
                $r->nombre_producto,
                $r->categoria,
                intval( $r->cantidad_unidades ),
                $r->ubicacion_codigo ?: '',
                $r->presentacion,
                intval( $r->equivalencia ),
                $r->observacion,
            ) );
        }

        fclose( $out );
        exit;
    }
}
