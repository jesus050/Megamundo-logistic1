<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_Metaboxes {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'registrar_metaboxes' ) );
        add_action( 'save_post', array( $this, 'guardar_valores_metabox' ) );
        add_action( 'admin_notices', array( $this, 'mostrar_notices_admin' ) );
        add_action( 'admin_post_mm_exportar_csv', array( $this, 'exportar_csv_lote' ) );
    }

    public function registrar_metaboxes() {
        add_meta_box(
            'mm_lote_items_box',
            '📦 Gestión del Lote y Productos Contados',
            array( $this, 'render_metabox_items' ),
            'lotes_ingreso',
            'normal',
            'high'
        );
    }

    public function mostrar_notices_admin() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'lotes_ingreso' ) {
            return;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return;
        }

        $error = get_transient( 'mm_lote_error_' . $post_id );
        if ( $error ) {
            delete_transient( 'mm_lote_error_' . $post_id );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
        }

        $success = get_transient( 'mm_lote_success_' . $post_id );
        if ( $success ) {
            delete_transient( 'mm_lote_success_' . $post_id );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $success ) . '</p></div>';
        }
    }

    public function render_metabox_items( $post ) {
        global $wpdb;
        $tabla = $wpdb->prefix . 'mm_lote_items';

        // Generar campo nonce de seguridad
        wp_nonce_field( 'mm_guardar_metabox', 'mm_metabox_nonce' );

        // Buscar en nuestra tabla SQL todos los items asignados al ID de este lote
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tabla WHERE lote_id = %d", $post->ID ) );

        // Obtener estado del lote y roles del usuario actual
        $estado_lote = get_post_status( $post->ID );
        $user = wp_get_current_user();
        $es_admin = in_array( 'administrator', (array) $user->roles, true );
        $es_ingresador = in_array( 'mm_ingresador', (array) $user->roles, true );
        $es_contador = in_array( 'mm_contador', (array) $user->roles, true );

        $ocultar_financiero = $es_contador && ! $es_admin;

        // Traducir estados del lote
        $estados_lote_labels = array(
            'draft'           => '📝 En Conteo (Borrador)',
            'mm_p_precios'    => '💵 Pendiente de Precios',
            'mm_p_aprobacion' => '⚖️ Pendiente de Aprobación',
            'mm_cargado'      => '🚀 Cargado en Sistema',
        );
        $estado_lote_lbl = isset( $estados_lote_labels[ $estado_lote ] ) ? $estados_lote_labels[ $estado_lote ] : ucfirst( $estado_lote );

        // Determinar si los campos de costos y precios son editables
        $puede_editar_costos = false;
        if ( $es_admin && $estado_lote !== 'mm_cargado' ) {
            $puede_editar_costos = true;
        } elseif ( $es_ingresador && in_array( $estado_lote, array( 'draft', 'mm_p_precios' ), true ) ) {
            $puede_editar_costos = true;
        }

        // Leer la opción de movimiento logístico (por defecto 'sumar')
        $tipo_movimiento = get_post_meta( $post->ID, '_mm_tipo_movimiento', true ) ?: 'sumar';

        // --- CÁLCULO DE RESUMEN ---
        $total_items_diferentes = count( $items );
        $total_unidades = 0;
        $valor_costo_total = 0.0;
        $valor_venta_total = 0.0;

        foreach ( $items as $item ) {
            $total_unidades += intval( $item->cantidad_contada );
            $valor_costo_total += intval( $item->cantidad_contada ) * floatval( $item->costo_ia );
            $valor_venta_total += intval( $item->cantidad_contada ) * floatval( $item->precio_propuesto );
        }

        $margen_total = $valor_venta_total - $valor_costo_total;
        $margen_total_pct = ($valor_venta_total > 0) ? ($margen_total / $valor_venta_total) * 100 : 0.0;

        // --- BLOQUEO VISUAL DESPUÉS DE CARGADO ---
        if ( 'mm_cargado' === $estado_lote ) {
            echo '<div class="mm-status-notice notice-cargado">';
            echo '<strong>✅ Lote Cargado:</strong> Este lote ya fue cargado en WooCommerce. No se permiten modificaciones en cantidades, costos o precios.';
            echo '</div>';
        }

        // --- RESUMEN DEL LOTE (BLOQUE SUPERIOR) ---
        echo '<div class="mm-summary-grid">';
        
        echo '<div class="mm-summary-card">';
        echo '<h4>Ítems Diferentes</h4>';
        echo '<div class="mm-metric">' . esc_html( $total_items_diferentes ) . '</div>';
        echo '</div>';
        
        echo '<div class="mm-summary-card">';
        echo '<h4>Total Unidades</h4>';
        echo '<div class="mm-metric">' . esc_html( $total_unidades ) . '</div>';
        echo '</div>';

        if ( ! $ocultar_financiero ) {
            echo '<div class="mm-summary-card mm-financial">';
            echo '<h4>Costo Total Estimado</h4>';
            echo '<div class="mm-metric">$' . number_format( $valor_costo_total, 2, ',', '.' ) . '</div>';
            echo '</div>';
            
            echo '<div class="mm-summary-card mm-financial">';
            echo '<h4>Venta Total Propuesta</h4>';
            echo '<div class="mm-metric">$' . number_format( $valor_venta_total, 2, ',', '.' ) . '</div>';
            echo '</div>';
            
            $margen_color_style = ($margen_total >= 0) ? 'border-left-color: #46b450;' : 'border-left-color: #dc3232;';
            echo '<div class="mm-summary-card mm-financial" style="' . esc_attr( $margen_color_style ) . '">';
            echo '<h4>Margen Total Estimado</h4>';
            echo '<div class="mm-metric">$' . number_format( $margen_total, 2, ',', '.' ) . '<br>';
            echo '<span style="font-size:11px; font-weight:normal; color:#646970;">(' . number_format( $margen_total_pct, 2, ',', '.' ) . '%)</span></div>';
            echo '</div>';
        } else {
            echo '<div class="mm-summary-card mm-highlight">';
            echo '<h4>Estado del Lote</h4>';
            echo '<div class="mm-metric" style="font-size:16px; padding-top:4px;">' . esc_html( $estado_lote_lbl ) . '</div>';
            echo '</div>';
        }
        
        echo '</div>';

        // --- FLUJO DE ESTADOS DEL LOTE Y ACCIONES ---
        echo '<div class="mm-actions-bar">';
        echo '<div class="mm-actions-bar-left">';
        echo '<strong>Flujo del Lote:</strong> <span class="mm-badge badge-info">' . esc_html( $estado_lote_lbl ) . '</span>';
        echo '</div>';
        
        echo '<div class="mm-actions-bar-right">';
        
        // 1. Botón de Exportar CSV (Solo Administrador e Ingresador)
        if ( $es_admin || $es_ingresador ) {
            $url_csv = wp_nonce_url( add_query_arg( array(
                'action'  => 'mm_exportar_csv',
                'lote_id' => $post->ID
            ), admin_url( 'admin-post.php' ) ), 'mm_exportar_csv_' . $post->ID );
            
            echo '<a href="' . esc_url( $url_csv ) . '" class="button button-secondary" style="margin-right: 5px;">📊 Exportar lote CSV</a>';
        }

        // 2. Botón de Imprimir Etiquetas (Solo Administrador e Ingresador, y lote mm_cargado o _sincronizado_wc)
        $sincronizado = get_post_meta( $post->ID, '_sincronizado_wc', true );
        if ( 'mm_cargado' === $estado_lote || $sincronizado ) {
            if ( $es_admin || $es_ingresador ) {
                $url_impresion = wp_nonce_url( add_query_arg( array(
                    'imprimir_tickets_lote' => $post->ID
                ), home_url() ), 'imprimir_tickets_' . $post->ID );
                
                echo '<a href="' . esc_url( $url_impresion ) . '" target="_blank" class="button button-primary" style="background:#d2691e; border-color:#a0522d; margin-right: 5px;">🖨️ Imprimir etiquetas del lote</a>';
            }
        }

        // 3. Botones de avance de estado
        if ( 'mm_cargado' !== $estado_lote ) {
            if ( 'draft' === $estado_lote ) {
                if ( $es_contador || $es_admin ) {
                    echo '<button type="submit" name="mm_action" value="enviar_p_precios" class="button button-primary">🔒 Cerrar Conteo y Enviar a Precios</button>';
                } else {
                    echo '<span class="description">Esperando que el Contador de Bodega cierre el conteo.</span>';
                }
            } elseif ( 'mm_p_precios' === $estado_lote ) {
                if ( $es_ingresador || $es_admin ) {
                    echo '<button type="submit" name="mm_action" value="enviar_p_aprobacion" class="button button-primary">📨 Enviar a Aprobación</button>';
                } else {
                    echo '<span class="description">Esperando que el Ingresador proponga precios y costos.</span>';
                }
            } elseif ( 'mm_p_aprobacion' === $estado_lote ) {
                if ( $es_admin ) {
                    echo '<button type="submit" name="mm_action" value="aprobar_y_cargar" class="button button-primary" style="background:#46b450; border-color:#3ea248;">✔️ Aprobar y Cargar en Sistema</button>';
                } else {
                    echo '<span class="description">Esperando aprobación de Jefatura (Administrador).</span>';
                }
            }
        } else {
            echo '<span class="mm-badge badge-success">✅ Sincronizado en WooCommerce</span>';
        }
        echo '</div>';
        echo '</div>';

        // Selector de tipo de movimiento (Deshabilitado si ya fue cargado)
        $disabled_movement = ('mm_cargado' === $estado_lote) ? 'disabled' : '';
        echo '<div style="background: #e5f5fa; padding: 15px; margin-bottom: 15px; border-left: 4px solid #00a0d2;">';
        echo '<label style="font-weight: bold; font-size: 14px;">🛠️ Tipo de Movimiento Logístico:</label><br>';
        echo '<select name="mm_tipo_movimiento" style="margin-top: 5px; padding: 5px;" ' . $disabled_movement . '>';
        echo '<option value="sumar" ' . selected( $tipo_movimiento, 'sumar', false ) . '>Sumar al inventario existente (Ingreso de Proveedor)</option>';
        echo '<option value="reemplazar" ' . selected( $tipo_movimiento, 'reemplazar', false ) . '>Reemplazar inventario existente (Auditoría Física)</option>';
        echo '</select>';
        echo '</div>';

        // --- BUSCADOR Y PAGINACIÓN ---
        echo '<div class="mm-table-search-bar">';
        echo '<input type="text" id="mm-table-search" class="mm-search-input" placeholder="🔍 Buscar por SKU o Nombre..." />';
        echo '<div class="mm-pagination-controls">';
        echo '<button type="button" id="mm-prev-btn" class="button button-secondary" disabled>◀ Anterior</button>';
        echo '<span id="mm-page-info" class="mm-pagination-info">Página 1 de 1</span>';
        echo '<button type="button" id="mm-next-btn" class="button button-secondary" disabled>Siguiente ▶</button>';
        echo '</div>';
        echo '</div>';

        // --- TABLA DE ÍTEMS ---
        echo '<div class="mm-table-container">';
        echo '<table id="mm-lote-items-table" class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width: 50px;">ID</th>';
        echo '<th style="width: 120px;">SKU</th>';
        echo '<th>Producto Vinculado</th>';
        echo '<th style="width: 150px;">Estado Producto</th>';
        echo '<th style="width: 90px;">Cantidad</th>';
        if ( ! $ocultar_financiero ) {
            echo '<th style="width: 120px;">Costo Unitario</th>';
            echo '<th style="width: 120px;">Precio Propuesto</th>';
            echo '<th style="width: 120px;">Margen Estimado</th>';
        }
        echo '<th style="width: 120px;">Operario</th>';
        echo '<th style="width: 140px;">Fecha Escaneo</th>';
        echo '<th style="width: 140px;">Sincronizado WC</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        if ( $items ) {
            foreach ( $items as $item ) {
                $nombre_producto = get_the_title( $item->producto_id ) ?: 'Producto desconocido';
                $product_edit_link = get_edit_post_link( $item->producto_id );
                
                // Determinar estado del producto
                $status_prod = get_post_status( $item->producto_id );
                if ( ! $status_prod ) {
                    $estado_txt = 'No encontrado';
                    $estado_class = 'badge-danger';
                } elseif ( 'draft' === $status_prod ) {
                    $estado_txt = 'Borrador / Nuevo';
                    $estado_class = 'badge-warning';
                } elseif ( 'publish' === $status_prod ) {
                    if ( 'mm_cargado' === $estado_lote ) {
                        $estado_txt = 'Publicado';
                        $estado_class = 'badge-success';
                    } else {
                        $estado_txt = 'Existente';
                        $estado_class = 'badge-info';
                    }
                } else {
                    $estado_txt = ucfirst( $status_prod );
                    $estado_class = 'badge-secondary';
                }

                // Obtener operario
                $user_data = get_userdata( $item->created_by );
                $scan_user = $user_data ? $user_data->display_name : 'Desconocido';

                // Synced At
                $synced_at_txt = $item->synced_at && $item->synced_at !== '0000-00-00 00:00:00' 
                    ? '<span style="color:#137e28; font-weight:600;">✔️ ' . esc_html( $item->synced_at ) . '</span>' 
                    : '<span class="mm-badge badge-secondary">Pendiente</span>';

                echo '<tr data-id="' . esc_attr( $item->id ) . '" data-sku="' . esc_attr( $item->sku ) . '" data-name="' . esc_attr( $nombre_producto ) . '">';
                echo '<td>' . esc_html( $item->id ) . '</td>';
                echo '<td><strong>' . esc_html( $item->sku ) . '</strong></td>';
                
                if ( $product_edit_link && $status_prod ) {
                    echo '<td><a href="' . esc_url( $product_edit_link ) . '" target="_blank">' . esc_html( $nombre_producto ) . '</a><br><small style="color:#666;">ID: ' . esc_html( $item->producto_id ) . '</small></td>';
                } else {
                    echo '<td>' . esc_html( $nombre_producto ) . '<br><small style="color:#666;">ID: ' . esc_html( $item->producto_id ) . '</small></td>';
                }

                echo '<td><span class="mm-badge ' . esc_attr( $estado_class ) . '">' . esc_html( $estado_txt ) . '</span></td>';
                echo '<td><strong>' . esc_html( $item->cantidad_contada ) . '</strong></td>';

                if ( ! $ocultar_financiero ) {
                    // Costo unitario
                    echo '<td>';
                    if ( $puede_editar_costos ) {
                        echo '$ <input type="number" step="0.01" class="mm-input-inline" name="mm_items[' . intval( $item->id ) . '][costo_ia]" value="' . esc_attr( $item->costo_ia ) . '" />';
                    } else {
                        echo '$' . number_format( $item->costo_ia, 2, ',', '.' );
                    }
                    echo '</td>';

                    // Precio Propuesto
                    echo '<td>';
                    if ( $puede_editar_costos ) {
                        echo '$ <input type="number" step="0.01" class="mm-input-inline" name="mm_items[' . intval( $item->id ) . '][precio_propuesto]" value="' . esc_attr( $item->precio_propuesto ) . '" />';
                    } else {
                        echo '$' . number_format( $item->precio_propuesto, 2, ',', '.' );
                    }
                    echo '</td>';

                    // Margen unitario
                    echo '<td>';
                    if ( floatval( $item->precio_propuesto ) <= 0 ) {
                        echo '<span style="color:#cc1818; font-weight:600; font-size:12px;">Sin precio</span>';
                    } else {
                        $margen_item = floatval( $item->precio_propuesto ) - floatval( $item->costo_ia );
                        $margen_item_pct = ($margen_item / floatval( $item->precio_propuesto )) * 100;
                        
                        $margen_color = ($margen_item >= 0) ? '#137e28' : '#cc1818';
                        echo '<span style="color:' . $margen_color . '; font-weight:600;">$' . number_format( $margen_item, 2, ',', '.' ) . '</span><br>';
                        echo '<small style="color:#666;">(' . number_format( $margen_item_pct, 2, ',', '.' ) . '%)</small>';
                    }
                    echo '</td>';
                }

                echo '<td>' . esc_html( $scan_user ) . '</td>';
                echo '<td>' . esc_html( $item->created_at ) . '</td>';
                echo '<td>' . $synced_at_txt . '</td>';
                echo '</tr>';
            }
        } else {
            $colspan = $ocultar_financiero ? 8 : 11;
            echo '<tr class="no-results-row"><td colspan="' . $colspan . '" style="text-align: center; padding: 20px;"><em>Aún no hay productos registrados. El Contador debe usar la aplicación de escáner.</em></td></tr>';
        }

        // Fila para sin resultados
        $colspan = $ocultar_financiero ? 8 : 11;
        echo '<tr id="mm-no-results" class="no-results-row" style="display:none;"><td colspan="' . $colspan . '" style="text-align: center; padding: 20px;"><em>No se encontraron productos coincidentes.</em></td></tr>';

        echo '</tbody></table>';
        echo '</div>';

        // --- PANEL DE ESTADO DE SINCRONIZACIÓN ---
        $sync_status = get_post_meta( $post->ID, '_mm_sync_status', true ) ?: 'pendiente';
        $total_items_sync = get_post_meta( $post->ID, '_mm_sync_total_items', true ) ?: 0;
        $processed_items = get_post_meta( $post->ID, '_mm_sync_processed_items', true ) ?: 0;
        $started_at = get_post_meta( $post->ID, '_mm_sync_started_at', true ) ?: '-';
        $completed_at = get_post_meta( $post->ID, '_mm_sync_completed_at', true ) ?: '-';
        $sync_errors = get_post_meta( $post->ID, '_sync_errors', false );
        $scheduler_fallback = get_post_meta( $post->ID, '_mm_sync_scheduler_fallback', true );

        $estados_traducciones = array(
            'pendiente'             => '⏳ Pendiente de sincronización',
            'queued'                => '🔄 En cola (Action Scheduler)',
            'processing'            => '⚡ Procesando chunks...',
            'completed'             => '✅ Completado con éxito',
            'completed_with_errors' => '⚠️ Completado con errores'
        );
        $estado_display = isset( $estados_traducciones[ $sync_status ] ) ? $estados_traducciones[ $sync_status ] : $sync_status;
        if ( $sync_status === 'queued' && $scheduler_fallback ) {
            $estado_display = '🔄 En cola (Fallback WP-Cron)';
        }

        $border_color = '#ccd0d4';
        if ( $sync_status === 'completed' ) {
            $border_color = '#46b450';
        } elseif ( $sync_status === 'completed_with_errors' ) {
            $border_color = '#dc3232';
        } elseif ( $sync_status === 'processing' || $sync_status === 'queued' ) {
            $border_color = '#00a0d2';
        }

        echo '<div style="margin-top: 30px; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; border-left: 4px solid ' . esc_attr( $border_color ) . ';">';
        echo '<h3 style="margin-top: 0; margin-bottom: 15px; font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 8px;">🔀 Sincronización a WooCommerce (Action Scheduler)</h3>';
        echo '<p style="margin: 5px 0;"><strong>Estado Sincronización:</strong> ' . esc_html( $estado_display ) . '</p>';
        echo '<p style="margin: 5px 0;"><strong>Progreso General:</strong> ' . esc_html( $processed_items ) . ' / ' . esc_html( $total_items_sync ) . ' ítems procesados</p>';
        echo '<p style="margin: 5px 0;"><strong>Inicio:</strong> ' . esc_html( $started_at ) . ' | <strong>Fin:</strong> ' . esc_html( $completed_at ) . '</p>';

        echo '<h4 style="margin-top: 15px; margin-bottom: 8px; font-size: 13px;">⚠️ Historial de Errores de Sincronización:</h4>';
        if ( ! empty( $sync_errors ) && is_array( $sync_errors ) ) {
            echo '<div style="background: #fcebeb; border: 1px solid #fcd2d2; color: #cc1818; padding: 12px; border-radius: 4px; max-height: 200px; overflow-y: auto;">';
            echo '<ul style="margin: 0; padding-left: 15px; font-size: 12px;">';
            foreach ( $sync_errors as $err ) {
                $err_sku = ! empty( $err['sku'] ) ? ' [SKU: ' . esc_html( $err['sku'] ) . ']' : '';
                $err_msg = isset( $err['mensaje'] ) ? $err['mensaje'] : ( is_string( $err ) ? $err : 'Error desconocido' );
                $err_date = isset( $err['fecha'] ) ? $err['fecha'] : '-';
                $err_prod = isset( $err['producto_id'] ) ? $err['producto_id'] : 'N/A';
                echo '<li style="margin-bottom:6px;"><strong>' . esc_html( $err_date ) . '</strong> - Producto ID ' . esc_html( $err_prod ) . $err_sku . ': ' . esc_html( $err_msg ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        } else {
            echo '<p style="color: #646970; font-style: italic; font-size: 13px;">No hay errores registrados.</p>';
        }
        echo '</div>';

        // --- SCRIPTS JS PARA BUSCADOR Y PAGINACIÓN ---
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('mm-table-search');
            if (!searchInput) return;

            const tableRows = document.querySelectorAll('#mm-lote-items-table tbody tr:not(.no-results-row)');
            const itemsPerPage = 50;
            let currentPage = 1;
            let filteredRows = Array.from(tableRows);

            function updateTable() {
                const start = (currentPage - 1) * itemsPerPage;
                const end = start + itemsPerPage;

                tableRows.forEach(row => {
                    row.style.display = 'none';
                });
                
                filteredRows.slice(start, end).forEach(row => {
                    row.style.display = '';
                });

                const totalPages = Math.ceil(filteredRows.length / itemsPerPage) || 1;
                document.getElementById('mm-page-info').textContent = `Página ${currentPage} de ${totalPages} (Total: ${filteredRows.length} ítems)`;
                document.getElementById('mm-prev-btn').disabled = currentPage === 1;
                document.getElementById('mm-next-btn').disabled = currentPage === totalPages;
            }

            searchInput.addEventListener('input', function() {
                const query = searchInput.value.toLowerCase().trim();
                
                filteredRows = Array.from(tableRows).filter(row => {
                    const sku = row.getAttribute('data-sku') || '';
                    const name = row.getAttribute('data-name') || '';
                    return sku.toLowerCase().includes(query) || name.toLowerCase().includes(query);
                });

                currentPage = 1;
                updateTable();
                
                const noResultsRow = document.getElementById('mm-no-results');
                if (filteredRows.length === 0 && tableRows.length > 0) {
                    if (noResultsRow) noResultsRow.style.display = '';
                } else {
                    if (noResultsRow) noResultsRow.style.display = 'none';
                }
            });

            document.getElementById('mm-prev-btn').addEventListener('click', function(e) {
                e.preventDefault();
                if (currentPage > 1) {
                    currentPage--;
                    updateTable();
                }
            });

            document.getElementById('mm-next-btn').addEventListener('click', function(e) {
                e.preventDefault();
                const totalPages = Math.ceil(filteredRows.length / itemsPerPage) || 1;
                if (currentPage < totalPages) {
                    currentPage++;
                    updateTable();
                }
            });

            updateTable();
        });
        </script>
        <?php
    }

    public function guardar_valores_metabox( $post_id ) {
        // Verificar nonce de seguridad
        if ( ! isset( $_POST['mm_metabox_nonce'] ) || ! wp_verify_nonce( $_POST['mm_metabox_nonce'], 'mm_guardar_metabox' ) ) {
            return;
        }

        // Verificaciones de seguridad adicionales
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $estado_lote = get_post_status( $post_id );
        
        // Bloqueo total después de cargado
        if ( 'mm_cargado' === $estado_lote ) {
            return;
        }

        // Obtener roles del usuario actual
        $user = wp_get_current_user();
        $es_admin = in_array( 'administrator', (array) $user->roles, true );
        $es_ingresador = in_array( 'mm_ingresador', (array) $user->roles, true );
        $es_contador = in_array( 'mm_contador', (array) $user->roles, true );

        // 1. Guardar Tipo de Movimiento
        if ( isset( $_POST['mm_tipo_movimiento'] ) ) {
            $tipo_movimiento = sanitize_text_field( $_POST['mm_tipo_movimiento'] );
            if ( in_array( $tipo_movimiento, array( 'sumar', 'reemplazar' ), true ) ) {
                update_post_meta( $post_id, '_mm_tipo_movimiento', $tipo_movimiento );
            }
        }

        // 2. Guardar Costos y Precios Propuestos
        $puede_editar = false;
        if ( $es_admin ) {
            $puede_editar = true;
        } elseif ( $es_ingresador && in_array( $estado_lote, array( 'draft', 'mm_p_precios' ), true ) ) {
            $puede_editar = true;
        }

        if ( $puede_editar && isset( $_POST['mm_items'] ) && is_array( $_POST['mm_items'] ) ) {
            global $wpdb;
            $tabla = $wpdb->prefix . 'mm_lote_items';
            foreach ( $_POST['mm_items'] as $item_id => $valores ) {
                $item_id = intval( $item_id );
                $costo   = isset( $valores['costo_ia'] ) ? floatval( $valores['costo_ia'] ) : 0.0;
                $precio  = isset( $valores['precio_propuesto'] ) ? floatval( $valores['precio_propuesto'] ) : 0.0;

                // Sanitizar y no permitir negativos
                $costo   = max( 0.0, $costo );
                $precio  = max( 0.0, $precio );

                $wpdb->update(
                    $tabla,
                    array(
                        'costo_ia'         => $costo,
                        'precio_propuesto' => $precio,
                        'updated_at'       => current_time( 'mysql' )
                    ),
                    array( 'id' => $item_id, 'lote_id' => $post_id ),
                    array( '%f', '%f', '%s' ),
                    array( '%d', '%d' )
                );
            }
        }

        // 3. Procesar Botones de Avance de Estado
        if ( isset( $_POST['mm_action'] ) ) {
            $action = sanitize_text_field( $_POST['mm_action'] );
            $nuevo_estado = '';
            $error_msg = '';

            global $wpdb;
            $tabla = $wpdb->prefix . 'mm_lote_items';

            if ( $action === 'enviar_p_precios' ) {
                if ( ! $es_contador && ! $es_admin ) {
                    $error_msg = 'No tienes permisos para cerrar el conteo de este lote.';
                } elseif ( $estado_lote !== 'draft' ) {
                    $error_msg = 'El lote debe estar en Borrador para poder cerrar el conteo.';
                } else {
                    $nuevo_estado = 'mm_p_precios';
                }
            } elseif ( $action === 'enviar_p_aprobacion' ) {
                if ( ! $es_ingresador && ! $es_admin ) {
                    $error_msg = 'No tienes permisos para enviar el lote a aprobación.';
                } elseif ( $estado_lote !== 'mm_p_precios' ) {
                    $error_msg = 'El lote debe estar en Pendiente de Precios para ser enviado a aprobación.';
                } else {
                    $nuevo_estado = 'mm_p_aprobacion';
                }
            } elseif ( $action === 'aprobar_y_cargar' ) {
                if ( ! $es_admin ) {
                    $error_msg = 'Solo el Administrador puede aprobar y cargar lotes en el sistema.';
                } elseif ( $estado_lote !== 'mm_p_aprobacion' ) {
                    $error_msg = 'El lote debe estar en Pendiente de Aprobación para poder cargarlo.';
                } elseif ( get_post_meta( $post_id, '_sincronizado_wc', true ) ) {
                    $error_msg = 'Este lote ya ha sido sincronizado previamente.';
                } else {
                    // Validar que tenga ítems
                    $item_count = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tabla WHERE lote_id = %d", $post_id ) ) );
                    if ( $item_count === 0 ) {
                        $error_msg = 'No se puede aprobar un lote sin productos registrados.';
                    } else {
                        // Validar que todos tengan precio propuesto
                        $sin_precio_count = intval( $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM $tabla WHERE lote_id = %d AND (precio_propuesto IS NULL OR precio_propuesto <= 0)",
                            $post_id
                        ) ) );
                        if ( $sin_precio_count > 0 ) {
                            $error_msg = 'No se puede aprobar el lote. Hay productos que no tienen definido un precio propuesto de venta.';
                        } else {
                            $nuevo_estado = 'mm_cargado';
                        }
                    }
                }
            }

            if ( $error_msg ) {
                set_transient( 'mm_lote_error_' . $post_id, $error_msg, 45 );
            } elseif ( $nuevo_estado ) {
                // Cambiar estado de forma segura
                remove_action( 'save_post', array( $this, 'guardar_valores_metabox' ) );
                wp_update_post( array(
                    'ID'          => $post_id,
                    'post_status' => $nuevo_estado,
                ) );
                add_action( 'save_post', array( $this, 'guardar_valores_metabox' ) );
                
                set_transient( 'mm_lote_success_' . $post_id, 'Estado del lote actualizado con éxito.', 45 );
            }
        }
    }

    /**
     * Controlador para exportación CSV del lote.
     */
    public function exportar_csv_lote() {
        if ( ! isset( $_GET['lote_id'] ) ) {
            wp_die( 'Lote no especificado.' );
        }
        
        $lote_id = intval( $_GET['lote_id'] );
        
        // 1. Validar Permisos: Solo administrator y mm_ingresador pueden exportar
        $user = wp_get_current_user();
        $es_admin = in_array( 'administrator', (array) $user->roles, true );
        $es_ingresador = in_array( 'mm_ingresador', (array) $user->roles, true );
        
        if ( ! $es_admin && ! $es_ingresador ) {
            wp_die( 'No tienes permisos suficientes para exportar este lote.' );
        }
        
        // 2. Validar Nonce
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'mm_exportar_csv_' . $lote_id ) ) {
            wp_die( 'Acceso no autorizado o enlace de exportación caducado.' );
        }
        
        // 3. Validar existencia del lote
        if ( get_post_type( $lote_id ) !== 'lotes_ingreso' ) {
            wp_die( 'El lote especificado no existe o es inválido.' );
        }
        
        global $wpdb;
        $tabla = $wpdb->prefix . 'mm_lote_items';
        $items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $tabla WHERE lote_id = %d", $lote_id ) );
        
        if ( empty( $items ) ) {
            wp_die( 'El lote no contiene productos registrados para exportar.' );
        }
        
        // Configurar cabeceras del CSV de descarga
        $filename = 'lote-ingreso-' . $lote_id . '-' . date('Y-m-d') . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        
        // Abrir salida estándar
        $output = fopen( 'php://output', 'w' );
        
        // BOM UTF-8 para evitar problemas de codificación de caracteres en Excel
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );
        
        $delimiter = ';';
        
        // Escribir cabecera de columnas
        fputcsv( $output, array(
            'ID Item',
            'ID Lote',
            'SKU',
            'ID Producto',
            'Nombre Producto',
            'Cantidad Contada',
            'Costo Unitario',
            'Precio Propuesto',
            'Margen Unitario',
            'Usuario Escaneo',
            'Fecha Escaneo',
            'Fecha Sincronizacion'
        ), $delimiter );
        
        foreach ( $items as $item ) {
            // Manejo robusto si el producto fue eliminado
            $nombre_producto = get_the_title( $item->producto_id );
            if ( empty( $nombre_producto ) ) {
                $nombre_producto = 'Producto Eliminado (' . $item->sku . ')';
            }
            
            $user_data = get_userdata( $item->created_by );
            $scan_user = $user_data ? $user_data->display_name : 'Desconocido';
            
            $costo  = floatval( $item->costo_ia );
            $precio = floatval( $item->precio_propuesto );
            $margen = $precio - $costo;
            
            $sincronizacion = $item->synced_at && $item->synced_at !== '0000-00-00 00:00:00' ? $item->synced_at : 'Pendiente';
            
            fputcsv( $output, array(
                $item->id,
                $item->lote_id,
                $item->sku,
                $item->producto_id,
                $nombre_producto,
                $item->cantidad_contada,
                number_format( $costo, 2, '.', '' ),
                number_format( $precio, 2, '.', '' ),
                number_format( $margen, 2, '.', '' ),
                $scan_user,
                $item->created_at,
                $sincronizacion
            ), $delimiter );
        }
        
        fclose( $output );
        exit;
    }
}

new MM_Logistica_Metaboxes();
