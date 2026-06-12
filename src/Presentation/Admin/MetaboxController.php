<?php
namespace MegaMundo\Logistica\Presentation\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Domain\Lote\LoteStatuses;
use MegaMundo\Logistica\Domain\Lote\LoteAuditRepository;
use MegaMundo\Logistica\Domain\Lote\LoteCommentRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Export\CsvExportService;
use MegaMundo\Logistica\Application\Lote\ChecklistService;

class MetaboxController {

    private $lote_repo;
    private $item_repo;
    private $permission_guard;
    private $csv_service;
    private $audit_repo;
    private $comment_repo;
    private $checklist_service;

    public function __construct(
        LoteRepository $lote_repo,
        LoteItemRepository $item_repo,
        PermissionGuard $permission_guard,
        CsvExportService $csv_service,
        LoteAuditRepository $audit_repo,
        LoteCommentRepository $comment_repo,
        ChecklistService $checklist_service
    ) {
        $this->lote_repo         = $lote_repo;
        $this->item_repo         = $item_repo;
        $this->permission_guard  = $permission_guard;
        $this->csv_service       = $csv_service;
        $this->audit_repo        = $audit_repo;
        $this->comment_repo      = $comment_repo;
        $this->checklist_service = $checklist_service;
    }

    public function hook() {
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

        add_meta_box(
            'mm_lote_audit_box',
            '📜 Bitácora de Auditoría Interna',
            array( $this, 'render_metabox_audit' ),
            'lotes_ingreso',
            'normal',
            'default'
        );

        add_meta_box(
            'mm_lote_comments_box',
            '💬 Comentarios Internos del Lote',
            array( $this, 'render_metabox_comments' ),
            'lotes_ingreso',
            'normal',
            'default'
        );

        add_meta_box(
            'mm_lote_checklist_box',
            '✅ Checklist Operativo del Lote',
            array( $this, 'render_metabox_checklist' ),
            'lotes_ingreso',
            'side',
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
        wp_nonce_field( 'mm_guardar_metabox', 'mm_metabox_nonce' );

        $items = $this->item_repo->get_by_lote( $post->ID );
        $estado_lote = $this->lote_repo->get_status( $post->ID );

        $es_admin       = $this->permission_guard->is_admin();
        $es_ingresador  = $this->permission_guard->is_ingresador();
        $es_contador    = $this->permission_guard->is_contador();

        $ocultar_financiero = $es_contador && ! $es_admin;

        $estados_lote_labels = array(
            'draft'           => '📝 En Conteo (Borrador)',
            'mm_p_precios'    => '💵 Pendiente de Precios',
            'mm_p_aprobacion' => '⚖️ Pendiente de Aprobación',
            'mm_cargado'      => '🚀 Cargado en Sistema',
        );
        $estado_lote_lbl = isset( $estados_lote_labels[ $estado_lote ] ) ? $estados_lote_labels[ $estado_lote ] : ucfirst( $estado_lote );

        $puede_editar_costos = false;
        if ( $es_admin && $estado_lote !== 'mm_cargado' ) {
            $puede_editar_costos = true;
        } elseif ( $es_ingresador && in_array( $estado_lote, array( 'draft', 'mm_p_precios' ), true ) ) {
            $puede_editar_costos = true;
        }

        $tipo_movimiento = $this->lote_repo->get_movement_type( $post->ID );

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

        if ( 'mm_cargado' === $estado_lote ) {
            echo '<div class="mm-status-notice notice-cargado">';
            echo '<strong>✅ Lote Cargado:</strong> Este lote ya fue cargado en WooCommerce. No se permiten modificaciones en cantidades, costos o precios.';
            echo '</div>';
        }

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

        echo '<div class="mm-actions-bar">';
        echo '<div class="mm-actions-bar-left">';
        echo '<strong>Flujo del Lote:</strong> <span class="mm-badge badge-info">' . esc_html( $estado_lote_lbl ) . '</span>';
        echo '</div>';
        echo '<div class="mm-actions-bar-right">';
        
        if ( $es_admin || $es_ingresador ) {
            $url_csv = wp_nonce_url( add_query_arg( array(
                'action'  => 'mm_exportar_csv',
                'lote_id' => $post->ID
            ), admin_url( 'admin-post.php' ) ), 'mm_exportar_csv_' . $post->ID );
            
            echo '<a href="' . esc_url( $url_csv ) . '" class="button button-secondary" style="margin-right: 5px;">📊 Exportar lote CSV</a>';
        }

        $sincronizado = $this->lote_repo->is_synchronized( $post->ID );
        if ( 'mm_cargado' === $estado_lote || $sincronizado ) {
            if ( $es_admin || $es_ingresador ) {
                $url_impresion = wp_nonce_url( add_query_arg( array(
                    'imprimir_tickets_lote' => $post->ID
                ), home_url() ), 'imprimir_tickets_' . $post->ID );
                
                echo '<a href="' . esc_url( $url_impresion ) . '" target="_blank" class="button button-primary" style="background:#d2691e; border-color:#a0522d; margin-right: 5px;">🖨️ Imprimir etiquetas del lote</a>';
            }
        }

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

        $disabled_movement = ('mm_cargado' === $estado_lote) ? 'disabled' : '';
        echo '<div style="background: #e5f5fa; padding: 15px; margin-bottom: 15px; border-left: 4px solid #00a0d2;">';
        echo '<label style="font-weight: bold; font-size: 14px;">🛠️ Tipo de Movimiento Logístico:</label><br>';
        echo '<select name="mm_tipo_movimiento" style="margin-top: 5px; padding: 5px;" ' . $disabled_movement . '>';
        echo '<option value="sumar" ' . selected( $tipo_movimiento, 'sumar', false ) . '>Sumar al inventario existente (Ingreso de Proveedor)</option>';
        echo '<option value="reemplazar" ' . selected( $tipo_movimiento, 'reemplazar', false ) . '>Reemplazar inventario existente (Auditoría Física)</option>';
        echo '</select>';
        echo '</div>';

        echo '<div class="mm-table-search-bar">';
        echo '<input type="text" id="mm-table-search" class="mm-search-input" placeholder="🔍 Buscar por SKU o Nombre..." />';
        echo '<div class="mm-pagination-controls">';
        echo '<button type="button" id="mm-prev-btn" class="button button-secondary" disabled>◀ Anterior</button>';
        echo '<span id="mm-page-info" class="mm-pagination-info">Página 1 de 1</span>';
        echo '<button type="button" id="mm-next-btn" class="button button-secondary" disabled>Siguiente ▶</button>';
        echo '</div>';
        echo '</div>';

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

                $user_data = get_userdata( $item->created_by );
                $scan_user = $user_data ? $user_data->display_name : 'Desconocido';

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
                    echo '<td>';
                    if ( $puede_editar_costos ) {
                        echo '$ <input type="number" step="0.01" class="mm-input-inline" name="mm_items[' . intval( $item->id ) . '][costo_ia]" value="' . esc_attr( $item->costo_ia ) . '" />';
                    } else {
                        echo '$' . number_format( $item->costo_ia, 2, ',', '.' );
                    }
                    echo '</td>';

                    echo '<td>';
                    if ( $puede_editar_costos ) {
                        echo '$ <input type="number" step="0.01" class="mm-input-inline" name="mm_items[' . intval( $item->id ) . '][precio_propuesto]" value="' . esc_attr( $item->precio_propuesto ) . '" />';
                    } else {
                        echo '$' . number_format( $item->precio_propuesto, 2, ',', '.' );
                    }
                    echo '</td>';

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

        $colspan = $ocultar_financiero ? 8 : 11;
        echo '<tr id="mm-no-results" class="no-results-row" style="display:none;"><td colspan="' . $colspan . '" style="text-align: center; padding: 20px;"><em>No se encontraron productos coincidentes.</em></td></tr>';

        echo '</tbody></table>';
        echo '</div>';

        $sync_meta = $this->lote_repo->get_sync_meta( $post->ID );
        $sync_status = $sync_meta['status'] ?: 'pendiente';
        $total_items_sync = $sync_meta['total_items'] ?: 0;
        $processed_items = $sync_meta['processed_items'] ?: 0;
        $started_at = $sync_meta['started_at'] ?: '-';
        $completed_at = $sync_meta['completed_at'] ?: '-';
        $sync_errors = $this->lote_repo->get_sync_errors( $post->ID );
        $scheduler_fallback = $sync_meta['scheduler_fallback'];

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
                if ( is_array( $err ) ) {
                    $err_sku = ! empty( $err['sku'] ) ? ' [SKU: ' . esc_html( $err['sku'] ) . ']' : '';
                    $err_msg = isset( $err['mensaje'] ) ? $err['mensaje'] : 'Error desconocido';
                    $err_date = isset( $err['fecha'] ) ? $err['fecha'] : '-';
                    $err_prod = isset( $err['producto_id'] ) ? $err['producto_id'] : 'N/A';
                } else {
                    $err_sku = '';
                    $err_msg = is_string( $err ) ? $err : 'Error desconocido';
                    $err_date = '-';
                    $err_prod = 'N/A';
                }
                echo '<li style="margin-bottom:6px;"><strong>' . esc_html( $err_date ) . '</strong> - Producto ID ' . esc_html( $err_prod ) . $err_sku . ': ' . esc_html( $err_msg ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        } else {
            echo '<p style="color: #646970; font-style: italic; font-size: 13px;">No hay errores registrados.</p>';
        }
        echo '</div>';
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
        if ( ! isset( $_POST['mm_metabox_nonce'] ) || ! wp_verify_nonce( $_POST['mm_metabox_nonce'], 'mm_guardar_metabox' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $estado_lote = $this->lote_repo->get_status( $post_id );
        if ( 'mm_cargado' === $estado_lote ) {
            return;
        }

        $es_admin       = $this->permission_guard->is_admin();
        $es_ingresador  = $this->permission_guard->is_ingresador();
        $es_contador    = $this->permission_guard->is_contador();

        if ( isset( $_POST['mm_tipo_movimiento'] ) ) {
            $tipo_movimiento = sanitize_text_field( $_POST['mm_tipo_movimiento'] );
            if ( in_array( $tipo_movimiento, array( 'sumar', 'reemplazar' ), true ) ) {
                $this->lote_repo->update_movement_type( $post_id, $tipo_movimiento );
            }
        }

        $puede_editar = false;
        if ( $es_admin ) {
            $puede_editar = true;
        } elseif ( $es_ingresador && in_array( $estado_lote, array( 'draft', 'mm_p_precios' ), true ) ) {
            $puede_editar = true;
        }

        if ( $puede_editar && isset( $_POST['mm_items'] ) && is_array( $_POST['mm_items'] ) ) {
            $old_items = $this->item_repo->get_by_lote( $post_id );
            $old_items_by_id = array();
            foreach ( $old_items as $old_item ) {
                $old_items_by_id[ intval( $old_item->id ) ] = $old_item;
            }

            foreach ( $_POST['mm_items'] as $item_id => $valores ) {
                $item_id = intval( $item_id );
                $costo   = isset( $valores['costo_ia'] ) ? floatval( $valores['costo_ia'] ) : 0.0;
                $precio  = isset( $valores['precio_propuesto'] ) ? floatval( $valores['precio_propuesto'] ) : 0.0;

                $costo   = max( 0.0, $costo );
                $precio  = max( 0.0, $precio );

                if ( isset( $old_items_by_id[ $item_id ] ) ) {
                    $old_item = $old_items_by_id[ $item_id ];
                    $old_costo = floatval( $old_item->costo_ia );
                    $old_precio = floatval( $old_item->precio_propuesto );

                    if ( abs( $old_costo - $costo ) > 0.0001 ) {
                        $this->audit_repo->add_log(
                            $post_id,
                            'costo_modificado',
                            sprintf( 'Costo del producto SKU %s modificado', $old_item->sku ),
                            $item_id,
                            $old_item->producto_id,
                            $old_item->sku,
                            $old_costo,
                            $costo
                        );
                    }

                    if ( abs( $old_precio - $precio ) > 0.0001 ) {
                        $this->audit_repo->add_log(
                            $post_id,
                            'precio_modificado',
                            sprintf( 'Precio propuesto del producto SKU %s modificado', $old_item->sku ),
                            $item_id,
                            $old_item->producto_id,
                            $old_item->sku,
                            $old_precio,
                            $precio
                        );
                    }
                }

                $this->item_repo->update_prices( $item_id, $post_id, $costo, $precio );
            }
        }

        if ( isset( $_POST['mm_action'] ) ) {
            $action = sanitize_text_field( $_POST['mm_action'] );
            $nuevo_estado = '';
            $error_msg = '';

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
                } elseif ( $this->lote_repo->is_synchronized( $post_id ) ) {
                    $error_msg = 'Este lote ya ha sido sincronizado previamente.';
                } else {
                    // Validar checklist antes de aprobar
                    $checklist_result = $this->checklist_service->validate_for_approval( $post_id );
                    if ( $checklist_result !== true ) {
                        $error_msg = $checklist_result;
                    } else {
                        $item_count = $this->item_repo->count_distinct_items( $post_id );
                        if ( $item_count === 0 ) {
                            $error_msg = 'No se puede aprobar un lote sin productos registrados.';
                        } else {
                            $sin_precio_count = $this->item_repo->count_items_without_price( $post_id );
                            if ( $sin_precio_count > 0 ) {
                                $error_msg = 'No se puede aprobar el lote. Hay ' . $sin_precio_count . ' producto(s) sin precio propuesto de venta.';
                            } else {
                                $nuevo_estado = 'mm_cargado';
                            }
                        }
                    }
                }
            }

            if ( $error_msg ) {
                set_transient( 'mm_lote_error_' . $post_id, $error_msg, 45 );
            } elseif ( $nuevo_estado ) {
                remove_action( 'save_post', array( $this, 'guardar_valores_metabox' ) );
                $this->lote_repo->update_status( $post_id, $nuevo_estado );
                add_action( 'save_post', array( $this, 'guardar_valores_metabox' ) );
                
                set_transient( 'mm_lote_success_' . $post_id, 'Estado del lote actualizado con éxito.', 45 );
            }
        }
    }

    public function exportar_csv_lote() {
        if ( ! isset( $_GET['lote_id'] ) ) {
            wp_die( 'Lote no especificado.' );
        }
        
        $lote_id = intval( $_GET['lote_id'] );
        
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'mm_exportar_csv_' . $lote_id ) ) {
            wp_die( 'Acceso no autorizado o enlace de exportación caducado.' );
        }
        
        // Registrar log de exportación CSV antes de gatillar la descarga
        $this->audit_repo->add_log( $lote_id, 'csv_exportado', 'Lote exportado a formato CSV para control local.' );

        $this->csv_service->exportar_lote( $lote_id );
    }

    public function render_metabox_audit( $post ) {
        $lote_id = $post->ID;
        $logs = $this->audit_repo->get_logs( $lote_id, 100, 0 );

        if ( empty( $logs ) ) {
            echo '<p style="color: #646970; font-style: italic; padding: 10px;">No hay eventos registrados en la bitácora de este lote.</p>';
            return;
        }

        echo '<div class="mm-audit-timeline">';
        echo '<table class="mm-audit-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width: 150px;">Fecha</th>';
        echo '<th style="width: 150px;">Usuario</th>';
        echo '<th style="width: 180px;">Acción</th>';
        echo '<th>Detalle</th>';
        echo '<th style="width: 180px;">IP / Navegador</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ( $logs as $log ) {
            $user_data = get_userdata( $log->user_id );
            $username = $user_data ? esc_html( $user_data->display_name ) : 'Sistema / Desconocido';
            $badge_class = 'mm-audit-action-badge mm-audit-action-' . esc_attr( $log->action );
            
            $action_labels = array(
                'lote_creado'            => 'Lote Creado',
                'producto_escaneado'     => 'Producto Escaneado',
                'producto_nuevo_creado'  => 'Producto Nuevo Creado',
                'cantidad_modificada'    => 'Cantidad Modificada',
                'costo_modificado'       => 'Costo Modificado',
                'precio_modificado'      => 'Precio Modificado',
                'conteo_cerrado'         => 'Conteo Cerrado',
                'enviado_a_precios'      => 'Enviado a Precios',
                'enviado_a_aprobacion'   => 'Enviado a Aprobación',
                'lote_aprobado'          => 'Lote Aprobado',
                'sync_iniciada'          => 'Sync Iniciada',
                'sync_producto_ok'       => 'Sync Producto OK',
                'sync_producto_error'    => 'Sync Producto Error',
                'etiquetas_impresas'     => 'Etiquetas Impresas',
                'csv_exportado'          => 'CSV Exportado',
                'ajuste_manual'          => 'Ajuste Manual',
                'comentario_agregado'    => 'Comentario Interno',
            );
            
            $action_label = isset( $action_labels[ $log->action ] ) ? $action_labels[ $log->action ] : esc_html( $log->action );

            $detail = esc_html( $log->message );
            if ( $log->old_value !== null || $log->new_value !== null ) {
                $detail .= '<br><span style="font-size: 11px; color: #646970;">Valor anterior: <strong>' . esc_html( $log->old_value ) . '</strong> &rarr; Valor nuevo: <strong>' . esc_html( $log->new_value ) . '</strong></span>';
            }

            $ua = esc_attr( $log->user_agent );
            $browser = 'Desconocido';
            if ( stripos( $ua, 'Firefox' ) !== false ) { $browser = 'Firefox'; }
            elseif ( stripos( $ua, 'Chrome' ) !== false ) { $browser = 'Chrome'; }
            elseif ( stripos( $ua, 'Safari' ) !== false ) { $browser = 'Safari'; }
            elseif ( stripos( $ua, 'Edge' ) !== false ) { $browser = 'Edge'; }
            elseif ( stripos( $ua, 'WordPress' ) !== false ) { $browser = 'WordPress'; }
            
            $ip_details = esc_html( $log->ip_address ) . ' (' . $browser . ')';

            echo '<tr>';
            echo '<td style="white-space: nowrap;">' . esc_html( date( 'd-m-Y H:i:s', strtotime( $log->created_at ) ) ) . '</td>';
            echo '<td style="font-weight: 600;">' . $username . '</td>';
            echo '<td><span class="' . $badge_class . '">' . $action_label . '</span></td>';
            echo '<td>' . $detail . '</td>';
            echo '<td style="color: #646970; font-size: 11px;">' . $ip_details . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    // =========================================================================
    // METABOX: COMENTARIOS INTERNOS
    // =========================================================================

    public function render_metabox_comments( $post ) {
        if ( ! $this->permission_guard->can_view_lote_comments() ) {
            echo '<p style="color:#cc1818;">No tienes permisos para ver los comentarios de este lote.</p>';
            return;
        }

        $lote_id     = intval( $post->ID );
        $estado_lote = $this->lote_repo->get_status( $lote_id );
        $can_add     = $this->permission_guard->can_add_lote_comment();
        $comments    = $this->comment_repo->get_comments( $lote_id, 50, 0 );

        // Formulario para agregar comentario (solo si tiene permiso)
        if ( $can_add ) {
            $nonce = wp_create_nonce( 'mm_agregar_comentario_' . $lote_id );
            ?>
            <div class="mm-comments-form">
                <textarea
                    id="mm-comment-text"
                    class="mm-comment-textarea"
                    placeholder="Escribe un comentario interno (ej: Caja 4 llegó golpeada, Revisar precio de audífonos...)"
                    maxlength="1000"
                    rows="3"
                ></textarea>
                <div class="mm-comment-actions">
                    <button
                        type="button"
                        id="mm-comment-submit"
                        class="button button-primary"
                        data-lote-id="<?php echo esc_attr( $lote_id ); ?>"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    >
                        💬 Agregar comentario
                    </button>
                    <span id="mm-comment-status" class="mm-comment-status-msg"></span>
                </div>
            </div>
            <?php
        }

        // Lista de comentarios
        echo '<div class="mm-comments-list" id="mm-comments-list">';

        if ( empty( $comments ) ) {
            echo '<p class="mm-comments-empty">No hay comentarios registrados en este lote.</p>';
        } else {
            foreach ( $comments as $comment ) {
                $user_data = get_userdata( intval( $comment->user_id ) );
                $username  = $user_data ? esc_html( $user_data->display_name ) : 'Desconocido';
                $date_fmt  = esc_html( date_i18n( 'd/m/Y H:i', strtotime( $comment->created_at ) ) );
                $text      = nl2br( esc_html( $comment->comment ) );

                echo '<div class="mm-comment-item">';
                echo '<div class="mm-comment-meta">';
                echo '<span class="mm-comment-author">👤 ' . $username . '</span>';
                echo '<span class="mm-comment-date">🕐 ' . $date_fmt . '</span>';
                echo '</div>';
                echo '<div class="mm-comment-body">' . $text . '</div>';
                echo '</div>';
            }
        }

        echo '</div>';

        // JS inline para envío AJAX
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('mm-comment-submit');
            if (!btn) return;

            btn.addEventListener('click', function() {
                const textarea = document.getElementById('mm-comment-text');
                const statusEl = document.getElementById('mm-comment-status');
                const text = textarea ? textarea.value.trim() : '';

                if (!text) {
                    statusEl.textContent = '⚠️ El comentario no puede estar vacío.';
                    statusEl.style.color = '#cc1818';
                    return;
                }

                btn.disabled = true;
                statusEl.textContent = '⏳ Guardando...';
                statusEl.style.color = '#646970';

                const data = new FormData();
                data.append('action', 'mm_agregar_comentario');
                data.append('lote_id', btn.dataset.loteId);
                data.append('comment', text);
                data.append('nonce', btn.dataset.nonce);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            textarea.value = '';
                            statusEl.textContent = '✅ Comentario guardado.';
                            statusEl.style.color = '#137e28';
                            // Recargar lista de comentarios
                            setTimeout(() => { window.location.reload(); }, 800);
                        } else {
                            statusEl.textContent = '❌ ' + (res.data || 'Error desconocido.');
                            statusEl.style.color = '#cc1818';
                            btn.disabled = false;
                        }
                    })
                    .catch(() => {
                        statusEl.textContent = '❌ Error de conexión.';
                        statusEl.style.color = '#cc1818';
                        btn.disabled = false;
                    });
            });
        });
        </script>
        <?php
    }

    // =========================================================================
    // METABOX: CHECKLIST OPERATIVO
    // =========================================================================

    public function render_metabox_checklist( $post ) {
        $lote_id        = intval( $post->ID );
        $estado_lote    = $this->lote_repo->get_status( $lote_id );
        $checklist      = $this->checklist_service->get_checklist( $lote_id );
        $allowed_items  = $this->checklist_service->get_allowed_items_for_current_user();
        $is_locked      = ( 'mm_cargado' === $estado_lote );
        $nonce          = wp_create_nonce( 'mm_guardar_checklist_' . $lote_id );

        if ( $is_locked ) {
            echo '<p class="mm-checklist-locked">🔒 Lote cargado. Checklist bloqueado para edición.</p>';
        }

        echo '<div class="mm-checklist-wrap" id="mm-checklist-wrap">';

        $all_items = \MegaMundo\Logistica\Application\Lote\ChecklistService::ALL_ITEMS;
        $labels    = \MegaMundo\Logistica\Application\Lote\ChecklistService::LABELS;

        foreach ( $all_items as $key ) {
            $is_checked  = ! empty( $checklist[ $key ] );
            $is_allowed  = in_array( $key, $allowed_items, true ) && ! $is_locked;
            $label       = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
            $item_class  = 'mm-checklist-item' . ( $is_checked ? ' mm-checklist-checked' : '' ) . ( ! $is_allowed ? ' mm-checklist-readonly' : '' );

            echo '<div class="' . esc_attr( $item_class ) . '">';
            echo '<label>';
            echo '<input type="checkbox"';
            echo ' class="mm-checklist-cb"';
            echo ' data-key="' . esc_attr( $key ) . '"';
            echo ' data-lote-id="' . esc_attr( $lote_id ) . '"';
            echo ' data-nonce="' . esc_attr( $nonce ) . '"';
            if ( $is_checked ) { echo ' checked'; }
            if ( ! $is_allowed ) { echo ' disabled'; }
            echo '> ';
            echo esc_html( $label );
            echo '</label>';
            echo '</div>';
        }

        echo '</div>'; // .mm-checklist-wrap
        echo '<div id="mm-checklist-msg" class="mm-checklist-msg"></div>';

        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.mm-checklist-cb:not(:disabled)');
            const msgEl = document.getElementById('mm-checklist-msg');

            checkboxes.forEach(function(cb) {
                cb.addEventListener('change', function() {
                    const key     = cb.dataset.key;
                    const loteId  = cb.dataset.loteId;
                    const nonce   = cb.dataset.nonce;
                    const checked = cb.checked ? '1' : '0';

                    cb.disabled = true;
                    if (msgEl) { msgEl.textContent = '⏳ Guardando...'; msgEl.style.color = '#646970'; }

                    const data = new FormData();
                    data.append('action', 'mm_guardar_checklist');
                    data.append('lote_id', loteId);
                    data.append('nonce', nonce);
                    data.append('item_key', key);
                    data.append('item_value', checked);

                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(r => r.json())
                        .then(res => {
                            cb.disabled = false;
                            const item = cb.closest('.mm-checklist-item');
                            if (res.success) {
                                if (msgEl) { msgEl.textContent = '✅ Checklist actualizado.'; msgEl.style.color = '#137e28'; }
                                if (item) {
                                    if (cb.checked) { item.classList.add('mm-checklist-checked'); }
                                    else { item.classList.remove('mm-checklist-checked'); }
                                }
                            } else {
                                cb.checked = !cb.checked; // revert
                                if (msgEl) { msgEl.textContent = '❌ ' + (res.data || 'Error.'); msgEl.style.color = '#cc1818'; }
                            }
                        })
                        .catch(() => {
                            cb.disabled = false;
                            cb.checked = !cb.checked; // revert
                            if (msgEl) { msgEl.textContent = '❌ Error de conexión.'; msgEl.style.color = '#cc1818'; }
                        });
                });
            });
        });
        </script>
        <?php
    }

    // =========================================================================
    // AJAX: Agregar comentario
    // =========================================================================

    public function ajax_agregar_comentario() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Debes iniciar sesión.' );
        }

        $lote_id = isset( $_POST['lote_id'] ) ? intval( $_POST['lote_id'] ) : 0;
        $nonce   = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_agregar_comentario_' . $lote_id ) ) {
            wp_send_json_error( 'Nonce inválido o lote incorrecto.' );
        }

        if ( ! $this->permission_guard->can_add_lote_comment() ) {
            wp_send_json_error( 'No tienes permisos para comentar en este lote.' );
        }

        $comment = isset( $_POST['comment'] ) ? trim( sanitize_textarea_field( $_POST['comment'] ) ) : '';
        if ( empty( $comment ) ) {
            wp_send_json_error( 'El comentario no puede estar vacío.' );
        }
        if ( mb_strlen( $comment ) > 1000 ) {
            wp_send_json_error( 'El comentario no puede superar los 1000 caracteres.' );
        }

        $user_id     = get_current_user_id();
        $inserted_id = $this->comment_repo->add_comment( $lote_id, $user_id, $comment );

        if ( ! $inserted_id ) {
            wp_send_json_error( 'Error al guardar el comentario en la base de datos.' );
        }

        // Registrar en bitácora
        $this->audit_repo->add_log(
            $lote_id,
            'comentario_agregado',
            sprintf( 'Comentario interno agregado: "%s"', mb_substr( $comment, 0, 120 ) ),
            null,
            null,
            null,
            null,
            null,
            $user_id
        );

        wp_send_json_success( array(
            'id'         => $inserted_id,
            'message'    => 'Comentario guardado con éxito.',
        ) );
    }

    // =========================================================================
    // AJAX: Guardar item del checklist
    // =========================================================================

    public function ajax_guardar_checklist() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( 'Debes iniciar sesión.' );
        }

        $lote_id    = isset( $_POST['lote_id'] ) ? intval( $_POST['lote_id'] ) : 0;
        $nonce      = isset( $_POST['nonce'] ) ? sanitize_text_field( $_POST['nonce'] ) : '';
        $item_key   = isset( $_POST['item_key'] ) ? sanitize_key( $_POST['item_key'] ) : '';
        $item_value = isset( $_POST['item_value'] ) ? ( $_POST['item_value'] === '1' ) : false;

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_guardar_checklist_' . $lote_id ) ) {
            wp_send_json_error( 'Nonce inválido o lote incorrecto.' );
        }

        if ( ! $this->permission_guard->can_update_lote_checklist() ) {
            wp_send_json_error( 'No tienes permisos para modificar el checklist.' );
        }

        // Verificar que el lote no esté bloqueado
        $estado_lote = $this->lote_repo->get_status( $lote_id );
        if ( 'mm_cargado' === $estado_lote ) {
            wp_send_json_error( 'El lote ya está cargado. El checklist está bloqueado.' );
        }

        $result = $this->checklist_service->update_checklist(
            $lote_id,
            array( $item_key => $item_value ),
            get_current_user_id()
        );

        if ( ! $result['success'] ) {
            wp_send_json_error( $result['error'] );
        }

        wp_send_json_success( array( 'changed' => $result['changed'] ) );
    }
}
