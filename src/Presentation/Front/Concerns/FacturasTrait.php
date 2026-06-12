<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait FacturasTrait {
    private function get_facturas_lotes_disponibles() {
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
        $lotes = array();

        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( isset( $lote->ID ) ) {
                    $lotes[ intval( $lote->ID ) ] = $lote;
                }
            }
        }

        krsort( $lotes );
        return array_values( $lotes );
    }

    private function get_facturas_lote_id_actual() {
        $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
        if ( $lote_id > 0 && $this->lote_repo->find( $lote_id ) ) {
            return $lote_id;
        }

        $lotes = $this->get_facturas_lotes_disponibles();
        return ! empty( $lotes ) ? intval( $lotes[0]->ID ) : 0;
    }

    private function get_facturas_lote( $lote_id ) {
        $facturas = get_post_meta( intval( $lote_id ), '_mm_facturas_proveedor', true );
        return is_array( $facturas ) ? $facturas : array();
    }

    private function save_facturas_lote( $lote_id, $facturas ) {
        update_post_meta( intval( $lote_id ), '_mm_facturas_proveedor', array_values( $facturas ) );
    }

    private function calcular_costo_lote_factura( $lote_id ) {
        $items = $this->item_repo ? $this->item_repo->get_items( intval( $lote_id ) ) : array();
        $total = 0;

        foreach ( $items as $item ) {
            $qty = isset( $item->cantidad_contada ) ? intval( $item->cantidad_contada ) : 0;
            $cost = isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0;
            $total += $qty * $cost;
        }

        return $total;
    }

    private function format_factura_money( $value ) {
        return '$' . number_format( floatval( $value ), 0, ',', '.' );
    }

    private function handle_factura_upload_file() {
        if ( empty( $_FILES['archivo_factura'] ) || empty( $_FILES['archivo_factura']['name'] ) ) {
            return array(
                'url' => '',
                'attachment_id' => 0,
            );
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $attachment_id = media_handle_upload( 'archivo_factura', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            return array(
                'error' => $attachment_id->get_error_message(),
            );
        }

        return array(
            'url' => wp_get_attachment_url( $attachment_id ),
            'attachment_id' => intval( $attachment_id ),
        );
    }

    public function ajax_guardar_factura_lote() {
        if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
            wp_send_json_error( array( 'message' => 'No tienes permiso para registrar facturas.' ), 403 );
        }

        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_factura_lote_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
        }

        if ( ! $this->lote_repo->find( $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Lote no encontrado.' ), 404 );
        }

        $numero = isset( $_POST['numero_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_factura'] ) ) : '';
        $proveedor = isset( $_POST['proveedor'] ) ? sanitize_text_field( wp_unslash( $_POST['proveedor'] ) ) : '';
        $fecha = isset( $_POST['fecha_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha_factura'] ) ) : '';
        $total = isset( $_POST['total_factura'] ) ? floatval( str_replace( array( '.', ',' ), array( '', '.' ), wp_unslash( $_POST['total_factura'] ) ) ) : 0;
        $estado = isset( $_POST['estado_factura'] ) ? sanitize_key( wp_unslash( $_POST['estado_factura'] ) ) : 'pendiente';
        $observaciones = isset( $_POST['observaciones'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observaciones'] ) ) : '';
            $productos_texto = isset( $_POST['productos_pedido'] ) ? sanitize_textarea_field( wp_unslash( $_POST['productos_pedido'] ) ) : '';
            $productos_internos = $this->parse_pedido_productos_internos( $productos_texto );

        if ( empty( $numero ) ) {
            wp_send_json_error( array( 'message' => 'El número de factura es obligatorio.' ), 400 );
        }

        if ( empty( $proveedor ) ) {
            wp_send_json_error( array( 'message' => 'El proveedor es obligatorio.' ), 400 );
        }

        if ( $total <= 0 ) {
            wp_send_json_error( array( 'message' => 'El total de la factura debe ser mayor a cero.' ), 400 );
        }

        if ( ! in_array( $estado, array( 'pendiente', 'revisada', 'pagada' ), true ) ) {
            $estado = 'pendiente';
        }

        $upload = $this->handle_factura_upload_file();
        if ( isset( $upload['error'] ) ) {
            wp_send_json_error( array( 'message' => 'No se pudo subir el archivo: ' . $upload['error'] ), 400 );
        }

        $facturas = $this->get_facturas_lote( $lote_id );
        $factura_id = uniqid( 'fac_', true );

        $facturas[] = array(
            'id'            => $factura_id,
            'numero'        => $numero,
            'proveedor'     => $proveedor,
            'fecha'         => $fecha,
            'total'         => $total,
            'estado'        => $estado,
            'observaciones' => $observaciones,
            'archivo_url'   => $upload['url'] ?? '',
            'attachment_id' => $upload['attachment_id'] ?? 0,
            'created_at'    => current_time( 'mysql' ),
            'created_by'    => get_current_user_id(),
        );

        $this->save_facturas_lote( $lote_id, $facturas );

        update_post_meta( $lote_id, '_mm_factura_ultima_at', current_time( 'mysql' ) );
        update_post_meta( $lote_id, '_mm_factura_ultima_by', get_current_user_id() );

        wp_send_json_success( array(
            'message' => 'Factura registrada correctamente.',
        ) );
    }

    public function ajax_eliminar_factura_lote() {
        if ( ! is_user_logged_in() || ! $this->permission_guard->can_access_jefatura_panel() ) {
            wp_send_json_error( array( 'message' => 'Solo jefatura puede eliminar facturas.' ), 403 );
        }

        $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
        $factura_id = isset( $_POST['factura_id'] ) ? sanitize_text_field( wp_unslash( $_POST['factura_id'] ) ) : '';
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $lote_id || ! $factura_id || ! wp_verify_nonce( $nonce, 'mm_factura_lote_' . $lote_id ) ) {
            wp_send_json_error( array( 'message' => 'Acceso no autorizado.' ), 403 );
        }

        $facturas = $this->get_facturas_lote( $lote_id );
        $facturas = array_values( array_filter( $facturas, function( $factura ) use ( $factura_id ) {
            return isset( $factura['id'] ) && $factura['id'] !== $factura_id;
        } ) );

        $this->save_facturas_lote( $lote_id, $facturas );

        wp_send_json_success( array(
            'message' => 'Factura eliminada correctamente.',
        ) );
    }

    private function render_facturas_dashboard() {
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return $this->render_denied_app( 'No tienes permiso para registrar facturas.' );
        }

        $lotes = $this->get_facturas_lotes_disponibles();
        $lote_id = $this->get_facturas_lote_id_actual();
        $facturas = $lote_id ? $this->get_facturas_lote( $lote_id ) : array();
        $nonce = $lote_id ? wp_create_nonce( 'mm_factura_lote_' . $lote_id ) : '';
        $costo_lote = $lote_id ? $this->calcular_costo_lote_factura( $lote_id ) : 0;

        $total_facturas = 0;
        $pendientes = 0;
        $pagadas = 0;

        foreach ( $facturas as $factura ) {
            $total_facturas += isset( $factura['total'] ) ? floatval( $factura['total'] ) : 0;
            if ( isset( $factura['estado'] ) && 'pagada' === $factura['estado'] ) {
                $pagadas++;
            } else {
                $pendientes++;
            }
        }

        $diferencia = $total_facturas - $costo_lote;

        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( 'facturas' ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-dashboard-hero">
                    <div>
                        <span class="mm-eyebrow">Facturas de proveedor</span>
                        <h1>Registro de facturas por lote</h1>
                        <p>Asocia facturas de compra al lote, sube soporte y compara el total facturado contra el costo ingresado.</p>
                    </div>
                </header>

                
                
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                    
                <?php endif; ?>



<section class="mm-report-filter-card">
                    <div>
                        <h2>Seleccionar lote</h2>
                        <p>Registra o consulta facturas asociadas a un lote de ingreso.</p>
                    </div>
                    <form class="mm-report-date-form" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                        <input type="hidden" name="mm_logistica_app" value="facturas">
                        <label>Lote
                            <select name="lote_id" class="mm-input">
                                <?php foreach ( $lotes as $lote ) : ?>
                                    <option value="<?php echo esc_attr( $lote->ID ); ?>" <?php selected( $lote_id, $lote->ID ); ?>>
                                        #<?php echo esc_html( $lote->ID ); ?> — <?php echo esc_html( get_the_title( $lote->ID ) ); ?> / <?php echo esc_html( $this->status_label( $this->lote_repo->get_status( $lote->ID ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="submit" class="mm-mini-primary">Ver facturas</button>
                    </form>
                </section>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Facturas</small><strong><?php echo esc_html( count( $facturas ) ); ?></strong></div>
                    <div class="mm-role-summary-card is-warning"><small>Total factura</small><strong><?php echo esc_html( $this->format_factura_money( $total_facturas ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Costo lote</small><strong><?php echo esc_html( $this->format_factura_money( $costo_lote ) ); ?></strong></div>
                    <div class="mm-role-summary-card <?php echo abs( $diferencia ) <= 1000 ? 'is-success' : 'is-warning'; ?>"><small>Diferencia</small><strong><?php echo esc_html( $this->format_factura_money( $diferencia ) ); ?></strong></div>
                </div>

                <div class="mm-factura-layout">
                    <section class="mm-platform-section">
                        <div class="mm-section-head">
                            <h2>Registrar factura</h2>
                            <span>Adjunta foto o PDF de la factura.</span>
                        </div>

                        <?php if ( ! $lote_id ) : ?>
                            <div class="mm-empty-state">No hay lote seleccionado.</div>
                        <?php else : ?>
                            <form class="mm-factura-form" enctype="multipart/form-data">
                                <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">
                                <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">

                                <div class="mm-factura-form-grid">
                                    <label>Número de factura
                                        <input class="mm-input" type="text" name="numero_factura" placeholder="Ej: FV-2034" required>
                                    </label>
                                    <label>Proveedor
                                        <input class="mm-input" type="text" name="proveedor" placeholder="Nombre del proveedor" required>
                                    </label>
                                    <label>Fecha de factura
                                        <input class="mm-input" type="date" name="fecha_factura" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
                                    </label>
                                    <label>Total factura
                                        <input class="mm-input" type="number" name="total_factura" placeholder="2500000" min="0" step="1" required>
                                    </label>
                                    <label>Estado
                                        <select class="mm-input" name="estado_factura">
                                            <option value="pendiente">Pendiente</option>
                                            <option value="revisada">Revisada</option>
                                            <option value="pagada">Pagada</option>
                                        </select>
                                    </label>
                                    <label>Soporte factura
                                        <input class="mm-input" type="file" name="archivo_factura" accept="application/pdf,image/jpeg,image/png,image/webp">
                                    </label>
                                </div>

                                <label>Observaciones
                                    <textarea class="mm-input mm-textarea" name="observaciones" rows="3" placeholder="Notas internas sobre la factura, proveedor, diferencias o pago."></textarea>
                                </label>

                                <button type="submit" class="mm-mini-primary">Guardar factura</button>
                                <div class="mm-factura-msg" hidden></div>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="mm-platform-section">
                        <div class="mm-section-head">
                            <h2>Facturas registradas</h2>
                            <span>Pendientes: <?php echo esc_html( $pendientes ); ?> · Pagadas: <?php echo esc_html( $pagadas ); ?></span>
                        </div>

                        <div class="mm-factura-list">
                            <?php if ( empty( $facturas ) ) : ?>
                                <div class="mm-empty-state">Aún no hay facturas registradas para este lote.</div>
                            <?php endif; ?>

                            <?php foreach ( array_reverse( $facturas ) as $factura ) :
                                $created_by = ! empty( $factura['created_by'] ) ? get_userdata( absint( $factura['created_by'] ) ) : null;
                            ?>
                                <article class="mm-factura-card">
                                    <div class="mm-factura-card-head">
                                        <div>
                                            <span class="mm-badge-soft"><?php echo esc_html( ucfirst( $factura['estado'] ?? 'pendiente' ) ); ?></span>
                                            <h3><?php echo esc_html( $factura['numero'] ?? 'Sin número' ); ?></h3>
                                            <p><?php echo esc_html( $factura['proveedor'] ?? 'Sin proveedor' ); ?></p>
                                        </div>
                                        <strong><?php echo esc_html( $this->format_factura_money( $factura['total'] ?? 0 ) ); ?></strong>
                                    </div>

                                    <ul class="mm-factura-meta">
                                        <li><span>Fecha factura</span><b><?php echo esc_html( $factura['fecha'] ?? '-' ); ?></b></li>
                                        <li><span>Registrada</span><b><?php echo esc_html( $factura['created_at'] ?? '-' ); ?></b></li>
                                        <li><span>Usuario</span><b><?php echo esc_html( $created_by ? $created_by->display_name : '-' ); ?></b></li>
                                    </ul>

                                    <?php if ( ! empty( $factura['observaciones'] ) ) : ?>
                                        <p class="mm-factura-observacion"><?php echo esc_html( $factura['observaciones'] ); ?></p>
                                    <?php endif; ?>

                                    <div class="mm-factura-actions">
                                        <?php if ( ! empty( $factura['archivo_url'] ) ) : ?>
                                            <a class="mm-mini-secondary" href="<?php echo esc_url( $factura['archivo_url'] ); ?>" target="_blank" rel="noopener">Ver soporte</a>
                                        <?php endif; ?>
                                        <?php if ( $this->permission_guard->can_access_jefatura_panel() ) : ?>
                                            <button type="button" class="mm-mini-danger mm-btn-delete-factura" data-lote-id="<?php echo esc_attr( $lote_id ); ?>" data-factura-id="<?php echo esc_attr( $factura['id'] ?? '' ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">Eliminar</button>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </section>
        </main>
        <?php return ob_get_clean();
    }



    private function normalize_factura_number_value( $value ) {
        return trim( preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $value ) );
    }

    private function normalize_factura_money_value( $value ) {
        $value = trim( (string) $value );
        $value = preg_replace( '/[^\d\,\.]/', '', $value );

        if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
            // Si trae ambos, asumimos separador decimal al final y eliminamos miles.
            if ( strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
                $value = str_replace( '.', '', $value );
                $value = str_replace( ',', '.', $value );
            } else {
                $value = str_replace( ',', '', $value );
            }
        } elseif ( false !== strpos( $value, ',' ) ) {
            $parts = explode( ',', $value );
            if ( strlen( end( $parts ) ) === 3 ) {
                $value = str_replace( ',', '', $value );
            } else {
                $value = str_replace( ',', '.', $value );
            }
        }

        return floatval( $value );
    }

    private function mm_pdf_unescape_text( $text ) {
        $text = str_replace( array( '\\(', '\\)', '\\\\', '\n', '\r', '\t' ), array( '(', ')', '\\', "\n", "\r", "\t" ), $text );
        $text = preg_replace( '/\\\\([0-7]{1,3})/', function( $m ) {
            return chr( octdec( $m[1] ) );
        }, $text );
        return $text;
    }

    private function extract_text_from_pdf_file( $path ) {
        if ( empty( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
            return '';
        }

        $size = @filesize( $path );
        if ( $size && $size > 8 * 1024 * 1024 ) {
            return '';
        }

        $raw = @file_get_contents( $path );
        if ( false === $raw || '' === $raw ) {
            return '';
        }

        $texts = array();

        // Intento 1: extraer streams y descomprimir FlateDecode si aplica.
        if ( preg_match_all( '/<<(.*?)>>\s*stream\s*\r?\n?(.*?)\r?\n?endstream/s', $raw, $streams, PREG_SET_ORDER ) ) {
            foreach ( $streams as $stream ) {
                $dict = $stream[1];
                $data = $stream[2];

                if ( false !== strpos( $dict, '/FlateDecode' ) ) {
                    $decoded = @gzuncompress( $data );
                    if ( false === $decoded ) {
                        $decoded = @gzdecode( $data );
                    }
                    if ( false === $decoded ) {
                        $decoded = @gzinflate( substr( $data, 2 ) );
                    }
                    if ( false !== $decoded ) {
                        $data = $decoded;
                    }
                }

                // Texto en paréntesis: (texto) Tj / dentro de TJ.
                if ( preg_match_all( '/\((?:\\\\.|[^\\\\)])*\)/s', $data, $matches ) ) {
                    foreach ( $matches[0] as $match ) {
                        $clean = substr( $match, 1, -1 );
                        $clean = $this->mm_pdf_unescape_text( $clean );
                        if ( trim( $clean ) !== '' ) {
                            $texts[] = $clean;
                        }
                    }
                }

                // Texto en hexadecimal <0048006f006c0061>
                if ( preg_match_all( '/<([0-9A-Fa-f]{4,})>/', $data, $hexes ) ) {
                    foreach ( $hexes[1] as $hex ) {
                        $bin = @hex2bin( $hex );
                        if ( $bin ) {
                            if ( function_exists( 'mb_convert_encoding' ) ) {
                                $utf16 = @mb_convert_encoding( $bin, 'UTF-8', 'UTF-16BE' );
                                $ascii = @mb_convert_encoding( $bin, 'UTF-8', 'ISO-8859-1' );
                                $candidate = strlen( trim( $utf16 ) ) > strlen( trim( $ascii ) ) ? $utf16 : $ascii;
                            } else {
                                $candidate = @iconv( 'UTF-16BE', 'UTF-8//IGNORE', $bin );
                                if ( false === $candidate || '' === trim( $candidate ) ) {
                                    $candidate = @iconv( 'ISO-8859-1', 'UTF-8//IGNORE', $bin );
                                }
                                if ( false === $candidate ) {
                                    $candidate = '';
                                }
                            }
                            if ( trim( $candidate ) !== '' ) {
                                $texts[] = $candidate;
                            }
                        }
                    }
                }
            }
        }

        // Intento 2: buscar texto plano incrustado en el PDF.
        $plain_source = substr( $raw, 0, 500000 );
        $plain = @preg_replace( '/[^\P{C}\r\n\t]+/u', ' ', $plain_source );
        if ( is_string( $plain ) && preg_match( '/Factura|TOTAL|Proveedor|Pedido|Descripción|NOVAVENTA|NIT/i', $plain ) ) {
            $texts[] = $plain;
        }

        $text = implode( "\n", $texts );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( "/[ \t]+/", ' ', $text );
        $text = preg_replace( "/\n{2,}/", "\n", $text );

        return trim( $text );
    }

    private function extract_factura_text_from_uploaded_file( $field_name ) {
        if ( empty( $_FILES[ $field_name ] ) || empty( $_FILES[ $field_name ]['tmp_name'] ) ) {
            return '';
        }

        $file = $_FILES[ $field_name ];
        $name = sanitize_text_field( $file['name'] ?? '' );
        $type = sanitize_text_field( $file['type'] ?? '' );
        $tmp  = $file['tmp_name'];

        $text = '';

        if ( preg_match( '/\.pdf$/i', $name ) || false !== stripos( $type, 'pdf' ) ) {
            $text = $this->extract_text_from_pdf_file( $tmp );
        }

        if ( empty( $text ) ) {
            $text = 'Archivo recibido: ' . $name . '. No se pudo extraer texto real. Si es imagen o PDF escaneado, pega el texto manualmente o conecta OCR externo.';
        }

        return $text;
    }

    private function parse_factura_ia_from_text( $text ) {
        $text = trim( (string) $text );
        $compact = preg_replace( '/[ \t]+/', ' ', $text );

        $data = array(
            'numero_factura'       => '',
            'proveedor'            => '',
            'nit_proveedor'        => '',
            'fecha_factura'        => current_time( 'Y-m-d' ),
            'total_factura'        => '',
            'iva_detectado'        => '',
            'pedido_numero'        => '',
            'productos_detectados' => array(),
            'confianza'            => 'media',
            'observaciones_ia'     => 'Lectura preliminar desde PDF/texto. Revisa y confirma antes de crear el lote.',
            'texto_extraido'       => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 3000 ) : substr( $text, 0, 3000 ),
        );

        // Número factura: prioriza "Factura Electrónica de venta No." o "No."
        if ( preg_match( '/Factura\s+(?:Electr[oó]nica\s+de\s+venta|de\s+venta)?\s*No\.?\s*([0-9A-Za-z\-]+)/iu', $compact, $m ) ) {
            $data['numero_factura'] = $this->normalize_factura_number_value( $m[1] );
        } elseif ( preg_match( '/\bNo\.?\s*([0-9]{5,})\b/u', $compact, $m ) ) {
            $data['numero_factura'] = $this->normalize_factura_number_value( $m[1] );
        } elseif ( preg_match( '/(?:factura|fact\.|fv|nro\.?|número)\s*[:#-]?\s*([A-Z0-9\-]+)/iu', $compact, $m ) ) {
            $data['numero_factura'] = $this->normalize_factura_number_value( $m[1] );
        }

        // Proveedor y NIT.
        if ( preg_match( '/([A-ZÁÉÍÓÚÑ0-9\.\s]+S\.A\.S)\s+([0-9]{6,12}\-?[0-9]?)/u', $compact, $m ) ) {
            $data['proveedor'] = trim( sanitize_text_field( $m[1] ) );
            $data['nit_proveedor'] = trim( sanitize_text_field( $m[2] ) );
        } elseif ( preg_match( '/(NOVAVENTA\s+S\.A\.S)/iu', $compact, $m ) ) {
            $data['proveedor'] = 'NOVAVENTA S.A.S';
        } elseif ( preg_match( '/(?:proveedor|emisor)\s*[:#-]?\s*([^\n\r]+)/iu', $text, $m ) ) {
            $data['proveedor'] = sanitize_text_field( trim( $m[1] ) );
        }

        if ( empty( $data['nit_proveedor'] ) && preg_match( '/NIT\s*([0-9\.\-]+)/iu', $compact, $m ) ) {
            $data['nit_proveedor'] = sanitize_text_field( $m[1] );
        }

        // Fecha factura.
        if ( preg_match( '/Fecha\s+Factura\s+(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/iu', $compact, $m ) ) {
            $data['fecha_factura'] = sprintf( '%04d-%02d-%02d', intval( $m[3] ), intval( $m[2] ), intval( $m[1] ) );
        } elseif ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $compact, $m ) ) {
            $data['fecha_factura'] = sanitize_text_field( $m[1] );
        } elseif ( preg_match( '/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $compact, $m ) ) {
            $data['fecha_factura'] = sprintf( '%04d-%02d-%02d', intval( $m[3] ), intval( $m[2] ), intval( $m[1] ) );
        }

        // Totales.
        if ( preg_match( '/TOTAL\s+FACTURA\s+\$?\s*([0-9\.\,]+)/iu', $compact, $m ) ) {
            $data['total_factura'] = (string) intval( round( $this->normalize_factura_money_value( $m[1] ) ) );
        } elseif ( preg_match( '/TOTAL\s+A\s+PAGAR\s+\$?\s*([0-9\.\,]+)/iu', $compact, $m ) ) {
            $data['total_factura'] = (string) intval( round( $this->normalize_factura_money_value( $m[1] ) ) );
        } elseif ( preg_match( '/(?:total|valor\s+total|gran\s+total)\s*[:$ ]+\s*([0-9\.\,]+)/iu', $compact, $m ) ) {
            $data['total_factura'] = (string) intval( round( $this->normalize_factura_money_value( $m[1] ) ) );
        }

        if ( preg_match( '/Valor\s+Total\s+de\s+Iva\s+([0-9\.\,]+)/iu', $compact, $m ) ) {
            $data['iva_detectado'] = (string) intval( round( $this->normalize_factura_money_value( $m[1] ) ) );
        }

        if ( preg_match( '/Pedido\s+No\.?\s*([0-9]+)/iu', $compact, $m ) ) {
            $data['pedido_numero'] = sanitize_text_field( $m[1] );
        }

        // Productos: patrón de tabla con item/código/descripción/iva/cantidad/unidad/valor...
        $lines = preg_split( '/\r\n|\r|\n/', $text );
        $buffered = array();

        foreach ( $lines as $line ) {
            $line = trim( preg_replace( '/\s+/', ' ', $line ) );
            if ( strlen( $line ) < 8 ) {
                continue;
            }

            // Caso Novaventa / factura tabular:
            // 1 97317 Gel Pote Ego Attraction x 110ml 19 3 unidad 2,580 1,470 7,742
            if ( preg_match( '/^(\d{1,3})\s+(\d{1,8})\s+(.+?)\s+(0|5|19)\s+(\d{1,5})\s+(unidad|und|unds|unid|u)\s+([0-9\.\,]+)(?:\s+([0-9\.\,]+))?(?:\s+([0-9\.\,]+))?(?:\s+([0-9\.\,]+))?$/iu', $line, $m ) ) {
                $descripcion = trim( $m[3] );
                if ( preg_match( '/cargo\s+por\s+manejo/iu', $descripcion ) ) {
                    continue;
                }
                $valor_unitario = $this->normalize_factura_money_value( $m[7] );
                $valor_total = ! empty( $m[10] ) ? $this->normalize_factura_money_value( $m[10] ) : ( ! empty( $m[9] ) ? $this->normalize_factura_money_value( $m[9] ) : 0 );

                $data['productos_detectados'][] = array(
                    'item'          => intval( $m[1] ),
                    'codigo'        => sanitize_text_field( $m[2] ),
                    'nombre'        => sanitize_text_field( $descripcion ),
                    'iva'           => intval( $m[4] ),
                    'cantidad'      => intval( $m[5] ),
                    'costo'         => intval( round( $valor_unitario ) ),
                    'valor_total'   => intval( round( $valor_total ) ),
                );
                continue;
            }

            // Caso manual simple: Shampoo 12 8500
            if ( preg_match( '/^(.+?)\s+(?:x\s*)?(\d{1,5})\s+[$]?\s*([0-9\.\,]{3,})$/iu', $line, $m ) ) {
                $name = trim( $m[1] );
                if ( preg_match( '/factura|proveedor|total|iva|fecha|subtotal|pago|saldo|banco|convenio|resoluci[oó]n/iu', $name ) ) {
                    continue;
                }
                $data['productos_detectados'][] = array(
                    'item'        => count( $data['productos_detectados'] ) + 1,
                    'codigo'      => '',
                    'nombre'      => sanitize_text_field( $name ),
                    'iva'         => '',
                    'cantidad'    => intval( $m[2] ),
                    'costo'       => intval( round( $this->normalize_factura_money_value( $m[3] ) ) ),
                    'valor_total' => 0,
                );
            }
        }

        // Si no se detectaron por línea, intentar sobre texto compacto para líneas que se pegaron juntas.
        if ( empty( $data['productos_detectados'] ) ) {
            if ( preg_match_all( '/(\d{1,3})\s+(\d{3,8})\s+([A-ZÁÉÍÓÚÑa-záéíóúñ0-9\s\.\-]+?)\s+(0|5|19)\s+(\d{1,5})\s+unidad\s+([0-9\.\,]+)/u', $compact, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $m ) {
                    $descripcion = trim( $m[3] );
                    if ( preg_match( '/cargo\s+por\s+manejo/iu', $descripcion ) ) {
                        continue;
                    }
                    $data['productos_detectados'][] = array(
                        'item'        => intval( $m[1] ),
                        'codigo'      => sanitize_text_field( $m[2] ),
                        'nombre'      => sanitize_text_field( $descripcion ),
                        'iva'         => intval( $m[4] ),
                        'cantidad'    => intval( $m[5] ),
                        'costo'       => intval( round( $this->normalize_factura_money_value( $m[6] ) ) ),
                        'valor_total' => 0,
                    );
                }
            }
        }

        if ( empty( $data['productos_detectados'] ) ) {
            $data['confianza'] = 'baja';
            $data['observaciones_ia'] = 'Se extrajo texto, pero no se detectaron productos con seguridad. Revisa el texto extraído o pega la tabla de productos manualmente.';
        } else {
            $data['confianza'] = 'alta';
            $data['observaciones_ia'] = 'Se detectaron ' . count( $data['productos_detectados'] ) . ' productos. Revisa nombres, cantidades y costos antes de crear el lote.';
        }

        return $data;
    }

    public function ajax_analizar_factura_ia() {
        try {
            $texto_manual = isset( $_POST['texto_factura'] ) ? sanitize_textarea_field( wp_unslash( $_POST['texto_factura'] ) ) : '';
            $result = $this->openai_service->analizar_pedido_con_openai_directo( $texto_manual );
            if ( ! is_array( $result ) || ! empty( $result['error'] ) ) { wp_send_json_error( array( 'message' => is_array($result)&&!empty($result['error'])?$result['error']:'No se pudo analizar factura.', 'data'=>$result ), 500 ); }
            wp_send_json_success( array( 'message'=>'Factura analizada con OpenAI directo.', 'data'=>$result ) );
        } catch ( \Throwable $e ) { error_log('[MegaMundo] Error OpenAI factura: '.$e->getMessage()); wp_send_json_error( array( 'message'=> current_user_can('manage_options') ? 'Error OpenAI directo: '.$e->getMessage() : 'No se pudo analizar la factura.' ), 500 ); }
    }

    public function ajax_crear_lote_desde_factura() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para crear lotes desde factura.' ), 403 );
            }

            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
            if ( ! wp_verify_nonce( $nonce, 'mm_factura_ia' ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            $numero = isset( $_POST['numero_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_factura'] ) ) : '';
            $proveedor = isset( $_POST['proveedor'] ) ? sanitize_text_field( wp_unslash( $_POST['proveedor'] ) ) : '';
            $fecha = isset( $_POST['fecha_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha_factura'] ) ) : current_time( 'Y-m-d' );
            $total = isset( $_POST['total_factura'] ) ? floatval( preg_replace( '/[^0-9\.]/', '', wp_unslash( $_POST['total_factura'] ) ) ) : 0;
            $observaciones = isset( $_POST['observaciones_ia'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observaciones_ia'] ) ) : '';
            $productos_esperados = isset( $_POST['productos_detectados_json'] ) ? $this->mm_normalize_expected_products_payload( $_POST['productos_detectados_json'] ) : array();

            if ( empty( $numero ) ) {
                wp_send_json_error( array( 'message' => 'Escribe el número de factura.' ), 400 );
            }

            if ( empty( $proveedor ) ) {
                wp_send_json_error( array( 'message' => 'Escribe el proveedor.' ), 400 );
            }

            $upload = $this->mm_handle_factura_soporte_upload( 'archivo_ia' );
            if ( isset( $upload['error'] ) && ! empty( $upload['error'] ) ) {
                wp_send_json_error( array( 'message' => 'No se pudo subir el soporte: ' . $upload['error'] ), 400 );
            }

            $existing = get_posts( array(
                'post_type'      => 'lotes_ingreso',
                'post_status'    => array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' ),
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'   => '_mm_factura_origen_numero',
                        'value' => $numero,
                    ),
                ),
            ) );

            if ( ! empty( $existing ) ) {
                wp_send_json_error( array(
                    'message' => 'Ya existe un lote creado desde esta factura: lote #' . intval( $existing[0]->ID ),
                ), 400 );
            }

            $lote_id = wp_insert_post( array(
                'post_type'   => 'lotes_ingreso',
                'post_title'  => 'Lote Factura ' . $numero . ' - ' . $proveedor,
                'post_status' => 'draft',
                'post_author' => get_current_user_id(),
            ) );

            if ( is_wp_error( $lote_id ) || ! $lote_id ) {
                wp_send_json_error( array( 'message' => 'No se pudo crear el lote.' ), 500 );
            }

            update_post_meta( $lote_id, '_mm_factura_origen_numero', $numero );
            update_post_meta( $lote_id, '_mm_factura_origen_proveedor', $proveedor );
            update_post_meta( $lote_id, '_mm_factura_origen_fecha', $fecha );
            update_post_meta( $lote_id, '_mm_factura_origen_total', $total );
            update_post_meta( $lote_id, '_mm_factura_origen_observaciones', $observaciones );
            update_post_meta( $lote_id, '_mm_factura_origen_soporte_url', $upload['url'] ?? '' );
            update_post_meta( $lote_id, '_mm_factura_origen_soporte_id', $upload['attachment_id'] ?? 0 );
            update_post_meta( $lote_id, '_mm_tipo_movimiento', 'sumar' );
        update_post_meta( $lote_id, '_mm_pedido_productos_internos_lote', $productos_internos );
        if ( ! empty( $productos_bodega ) ) {
            update_post_meta( $lote_id, '_mm_factura_productos_esperados', $productos_bodega );
            update_post_meta( $lote_id, '_mm_factura_productos_esperados_count', count( $productos_bodega ) );
        }
            if ( ! empty( $productos_esperados ) ) {
                update_post_meta( $lote_id, '_mm_factura_productos_esperados', $productos_esperados );
                update_post_meta( $lote_id, '_mm_factura_productos_esperados_count', count( $productos_esperados ) );
            }

            update_post_meta( $lote_id, '_mm_facturas_proveedor', array(
                array(
                    'id'            => uniqid( 'fac_', true ),
                    'numero'        => $numero,
                    'proveedor'     => $proveedor,
                    'fecha'         => $fecha,
                    'total'         => $total,
                    'estado'        => 'pendiente',
                    'observaciones' => $observaciones,
                    'archivo_url'   => $upload['url'] ?? '',
                    'attachment_id' => $upload['attachment_id'] ?? 0,
                    'created_at'    => current_time( 'mysql' ),
                    'created_by'    => get_current_user_id(),
                ),
            ) );

            wp_send_json_success( array(
                'message'  => 'Lote creado y factura guardada como soporte.',
                'redirect' => home_url( '/?mm_logistica_app=bodega&lote_id=' . intval( $lote_id ) ),
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error creando lote desde factura: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => 'No se pudo crear el lote desde la factura. Revisa los datos e inténtalo de nuevo.',
                'technical' => current_user_can( 'manage_options' ) ? $e->getMessage() : '',
            ), 500 );
        }
    }


    private function mm_handle_factura_soporte_upload( $field_name ) {
        if ( empty( $_FILES[ $field_name ] ) || empty( $_FILES[ $field_name ]['name'] ) ) {
            return array(
                'url' => '',
                'attachment_id' => 0,
            );
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $attachment_id = media_handle_upload( $field_name, 0 );

        if ( is_wp_error( $attachment_id ) ) {
            return array(
                'url' => '',
                'attachment_id' => 0,
                'error' => $attachment_id->get_error_message(),
            );
        }

        return array(
            'url' => wp_get_attachment_url( $attachment_id ),
            'attachment_id' => intval( $attachment_id ),
        );
    }



    private function get_factura_ai_webhook_url() {
        return trim( (string) get_option( 'mm_factura_ai_webhook_url', '' ) );
    }

    private function get_factura_ai_webhook_token() {
        return trim( (string) get_option( 'mm_factura_ai_webhook_token', '' ) );
    }

    public function ajax_guardar_factura_ai_webhook() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Solo administradores pueden configurar el webhook de IA.' ), 403 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mm_factura_ai_config' ) ) {
            wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
        }

        $url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
            $openai_api_key = isset( $_POST['openai_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ) ) : '';
        $token = isset( $_POST['webhook_token'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_token'] ) ) : '';

        if ( ! empty( $url ) && ! wp_http_validate_url( $url ) ) {
            wp_send_json_error( array( 'message' => 'La URL del webhook no es válida.' ), 400 );
        }

        update_option( 'mm_factura_ai_webhook_url', $url );
        update_option( 'mm_factura_ai_webhook_token', $token );

            $invoice_model = isset( $_POST['openai_invoice_model'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_invoice_model'] ) ) : 'gpt-4o-mini';
            $allowed_models = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-5.4-mini', 'gpt-5.4', 'gpt-5.5' );
            if ( ! in_array( $invoice_model, $allowed_models, true ) ) {
                $invoice_model = 'gpt-4o-mini';
            }
            $reasoning_effort = isset( $_POST['openai_reasoning_effort'] ) ? sanitize_text_field( wp_unslash( $_POST['openai_reasoning_effort'] ) ) : 'medium';
            if ( ! in_array( $reasoning_effort, array( 'low', 'medium', 'high' ), true ) ) {
                $reasoning_effort = 'medium';
            }
            $temperature = isset( $_POST['openai_temperature'] ) ? floatval( wp_unslash( $_POST['openai_temperature'] ) ) : 0;
            $temperature = max( 0, min( 1, $temperature ) );
            update_option( 'mm_openai_invoice_model', $invoice_model );
            update_option( 'mm_openai_reasoning_effort', $reasoning_effort );
            update_option( 'mm_openai_temperature', (string) $temperature );

        wp_send_json_success( array( 'message' => 'Webhook de IA guardado correctamente.' ) );
    }



    private function analizar_factura_con_webhook_ia( $texto_manual = '' ) {
        return $this->openai_service->analizar_factura_con_webhook_ia( $texto_manual );
    }

    private function mm_get_factura_productos_esperados( $lote_id ) {
        $lote_id = intval( $lote_id );
        $items = get_post_meta( $lote_id, '_mm_factura_productos_esperados', true );
        if ( is_array( $items ) && ! empty( $items ) ) { return $items; }

        $items = get_post_meta( $lote_id, '_mm_pedido_productos_internos_lote', true );
        if ( is_array( $items ) && ! empty( $items ) ) { return $this->build_bodega_safe_expected_products( $items ); }

        $pedido_id = intval( get_post_meta( $lote_id, '_mm_pedido_origen_id', true ) );
        if ( $pedido_id ) {
            $items = get_post_meta( $pedido_id, '_mm_pedido_productos_internos', true );
            if ( is_array( $items ) && ! empty( $items ) ) { return $this->build_bodega_safe_expected_products( $items ); }
        }

        return array();
    }

    private function mm_normalize_expected_products_payload( $json ) {
        if ( empty( $json ) ) { return array(); }
        $decoded = json_decode( wp_unslash( $json ), true );
        if ( ! is_array( $decoded ) ) { return array(); }
        $products = array();
        foreach ( $decoded as $index => $product ) {
            if ( ! is_array( $product ) ) { continue; }
            $products[] = array(
                'item'        => isset( $product['item'] ) ? intval( $product['item'] ) : $index + 1,
                'codigo'      => sanitize_text_field( $product['codigo'] ?? $product['sku'] ?? '' ),
                'nombre'      => sanitize_text_field( $product['nombre'] ?? $product['descripcion'] ?? '' ),
                'iva'         => sanitize_text_field( $product['iva'] ?? '' ),
                'cantidad'    => isset( $product['cantidad'] ) ? intval( $product['cantidad'] ) : 0,
                'costo'       => isset( $product['costo'] ) ? floatval( $product['costo'] ) : 0,
                'valor_total' => isset( $product['valor_total'] ) ? floatval( $product['valor_total'] ) : 0,
            );
        }
        return $products;
    }

    private function mm_get_scanned_qty_by_sku( $lote_id ) {
        $items = $this->item_repo ? $this->item_repo->get_items( intval( $lote_id ) ) : array();
        $map = array();
        foreach ( $items as $item ) {
            $sku = isset( $item->sku ) ? trim( (string) $item->sku ) : '';
            $qty = isset( $item->cantidad_contada ) ? intval( $item->cantidad_contada ) : 0;
            if ( $sku !== '' ) {
                $map[ $sku ] = ( $map[ $sku ] ?? 0 ) + $qty;
            }
        }
        return $map;
    }

    private function render_bodega_factura_expected_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( ! $lote_id ) { return ''; }

        $expected = $this->mm_get_factura_productos_esperados( $lote_id );
        $factura_numero = get_post_meta( $lote_id, '_mm_factura_origen_numero', true );
        $proveedor = get_post_meta( $lote_id, '_mm_factura_origen_proveedor', true );
        $soporte_url = get_post_meta( $lote_id, '_mm_factura_origen_soporte_url', true );

        if ( empty( $expected ) && empty( $factura_numero ) ) { return ''; }

        $scanned = $this->mm_get_scanned_qty_by_sku( $lote_id );
        $total_expected_units = 0;
        $completed = 0;
        $pending = 0;

        foreach ( $expected as $p ) {
            $qty = intval( $p['cantidad'] ?? 0 );
            $code = trim( (string) ( $p['codigo'] ?? '' ) );
            $scan = $code && isset( $scanned[ $code ] ) ? intval( $scanned[ $code ] ) : 0;
            $total_expected_units += $qty;
            if ( $qty > 0 && $scan >= $qty ) { $completed++; } else { $pending++; }
        }

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-expected-panel">
            <div class="mm-section-head">
                <h2>Productos esperados por factura</h2>
                <span>La IA indica lo que debería llegar; bodega confirma lo real.</span>
            </div>

            <div class="mm-bodega-expected-summary">
                <div><small>Factura</small><strong><?php echo esc_html( $factura_numero ?: 'Sin número' ); ?></strong><span><?php echo esc_html( $proveedor ?: 'Proveedor no registrado' ); ?></span></div>
                <div><small>Productos esperados</small><strong><?php echo esc_html( count( $expected ) ); ?></strong><span>Unidades: <?php echo esc_html( $total_expected_units ); ?></span></div>
                <div><small>Completos</small><strong><?php echo esc_html( $completed ); ?></strong><span>Pendientes: <?php echo esc_html( $pending ); ?></span></div>
                <?php if ( $soporte_url ) : ?><div><small>Soporte</small><a class="mm-mini-secondary" href="<?php echo esc_url( $soporte_url ); ?>" target="_blank" rel="noopener">Ver factura</a></div><?php endif; ?>
            </div>

            <?php if ( empty( $expected ) ) : ?>
                <div class="mm-empty-state">Este lote tiene factura asociada, pero todavía no tiene productos esperados detectados por IA.</div>
            <?php else : ?>
                <div class="mm-bodega-expected-table-wrap">
                    <table class="mm-bodega-expected-table">
                        <thead><tr><th>Estado</th><th>Código</th><th>Producto esperado</th><th>Esperado</th><th>Escaneado</th><th>Diferencia</th></tr></thead>
                        <tbody>
                        <?php foreach ( $expected as $p ) :
                            $code = trim( (string) ( $p['codigo'] ?? '' ) );
                            $qty = intval( $p['cantidad'] ?? 0 );
                            $scan = $code && isset( $scanned[ $code ] ) ? intval( $scanned[ $code ] ) : 0;
                            $diff = $scan - $qty;
                            if ( $scan <= 0 ) { $status = 'No escaneado'; $class = 'is-missing'; }
                            elseif ( $diff < 0 ) { $status = 'Faltante'; $class = 'is-warning'; }
                            elseif ( $diff > 0 ) { $status = 'Sobrante'; $class = 'is-extra'; }
                            else { $status = 'Completo'; $class = 'is-ok'; }
                        ?>
                            <tr>
                                <td><span class="mm-expected-status <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $status ); ?></span></td>
                                <td><strong><?php echo esc_html( $code ?: '-' ); ?></strong></td>
                                <td><?php echo esc_html( $p['nombre'] ?? 'Sin nombre' ); ?></td>
                                <td><?php echo esc_html( $qty ); ?></td>
                                <td><?php echo esc_html( $scan ); ?></td>
                                <td><?php echo esc_html( $diff ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }



    private function render_bodega_factura_ia_panel( $lote_id ) {
        $lote_id = intval( $lote_id );
        if ( ! $lote_id ) {
            return '';
        }

        $nonce = wp_create_nonce( 'mm_factura_ia' );
        $save_nonce = wp_create_nonce( 'mm_bodega_factura_ia_' . $lote_id );
        $factura_numero = get_post_meta( $lote_id, '_mm_factura_origen_numero', true );
        $proveedor = get_post_meta( $lote_id, '_mm_factura_origen_proveedor', true );
        $total = get_post_meta( $lote_id, '_mm_factura_origen_total', true );

        ob_start(); ?>
        <section class="mm-platform-section mm-bodega-ia-panel mm-hide-bodega-ia-factura">
            <div class="mm-section-head">
                <h2>🤖 Analizar factura desde Bodega</h2>
                <span>Sube PDF/foto de factura para detectar productos esperados. Bodega seguirá escaneando normalmente.</span>
            </div>

            <div class="mm-bodega-ia-layout">
                <form class="mm-bodega-ia-analyze-form" enctype="multipart/form-data">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
                    <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">

                    <label>Factura PDF o foto
                        <input class="mm-input" type="file" name="archivo_ia" accept="application/pdf,image/jpeg,image/png,image/webp" capture="environment">
                    </label>

                    <label>Texto manual opcional
                        <textarea class="mm-input mm-textarea" name="texto_factura" rows="5" placeholder="Pega texto de la factura si el PDF/foto no se lee bien."></textarea>
                    </label>

                    <button type="submit" class="mm-mini-primary">Analizar factura con IA</button>
                    <div class="mm-bodega-ia-msg" hidden></div>
                </form>

                <form class="mm-bodega-ia-result-form">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr( $save_nonce ); ?>">
                    <input type="hidden" name="lote_id" value="<?php echo esc_attr( $lote_id ); ?>">
                    <input type="hidden" name="productos_detectados_json" value="">

                    <div class="mm-factura-form-grid">
                        <label>Número factura
                            <input class="mm-input" type="text" name="numero_factura" value="<?php echo esc_attr( $factura_numero ); ?>">
                        </label>
                        <label>Proveedor
                            <input class="mm-input" type="text" name="proveedor" value="<?php echo esc_attr( $proveedor ); ?>">
                        </label>
                        <label>Fecha
                            <input class="mm-input" type="date" name="fecha_factura" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
                        </label>
                        <label>Total factura
                            <input class="mm-input" type="number" name="total_factura" min="0" step="1" value="<?php echo esc_attr( $total ); ?>">
                        </label>
                    </div>

                    <label>Observaciones
                        <textarea class="mm-input mm-textarea" name="observaciones_ia" rows="3"></textarea>
                    </label>

                    <div class="mm-factura-products-preview">
                        <strong>Productos detectados por IA</strong>
                        <div class="mm-bodega-ia-products-list">Aún no hay productos detectados.</div>
                    </div>

                    <div class="mm-bodega-ia-actions">
                        <button type="button" class="mm-mini-primary mm-btn-bodega-guardar-factura-ia">Guardar productos esperados en este lote</button>
                        <span>Esto no carga inventario; solo crea una lista de control para comparar contra el escaneo.</span>
                    </div>
                    <div class="mm-bodega-ia-save-msg" hidden></div>
                </form>
            </div>
        </section>
        <?php return ob_get_clean();
    }

    public function ajax_bodega_guardar_factura_ia() {
        try {
            if ( ! is_user_logged_in() || ( ! $this->permission_guard->can_access_bodega_panel() && ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) ) {
                wp_send_json_error( array( 'message' => 'No tienes permiso para guardar datos de factura en bodega.' ), 403 );
            }

            $lote_id = isset( $_POST['lote_id'] ) ? absint( $_POST['lote_id'] ) : 0;
            $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

            if ( ! $lote_id || ! wp_verify_nonce( $nonce, 'mm_bodega_factura_ia_' . $lote_id ) ) {
                wp_send_json_error( array( 'message' => 'Sesión vencida o acceso no autorizado.' ), 403 );
            }

            if ( ! $this->lote_repo->find( $lote_id ) ) {
                wp_send_json_error( array( 'message' => 'Lote no encontrado.' ), 404 );
            }

            $numero = isset( $_POST['numero_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['numero_factura'] ) ) : '';
            $proveedor = isset( $_POST['proveedor'] ) ? sanitize_text_field( wp_unslash( $_POST['proveedor'] ) ) : '';
            $fecha = isset( $_POST['fecha_factura'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha_factura'] ) ) : '';
            $total = isset( $_POST['total_factura'] ) ? floatval( preg_replace( '/[^0-9\.]/', '', wp_unslash( $_POST['total_factura'] ) ) ) : 0;
            $observaciones = isset( $_POST['observaciones_ia'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observaciones_ia'] ) ) : '';
            $products = isset( $_POST['productos_detectados_json'] ) ? $this->mm_normalize_expected_products_payload( $_POST['productos_detectados_json'] ) : array();

            if ( $numero ) {
                update_post_meta( $lote_id, '_mm_factura_origen_numero', $numero );
            }
            if ( $proveedor ) {
                update_post_meta( $lote_id, '_mm_factura_origen_proveedor', $proveedor );
            }
            if ( $fecha ) {
                update_post_meta( $lote_id, '_mm_factura_origen_fecha', $fecha );
            }
            if ( $total > 0 ) {
                update_post_meta( $lote_id, '_mm_factura_origen_total', $total );
            }
            if ( $observaciones ) {
                update_post_meta( $lote_id, '_mm_factura_origen_observaciones', $observaciones );
            }

            if ( ! empty( $products ) ) {
                update_post_meta( $lote_id, '_mm_factura_productos_esperados', $products );
                update_post_meta( $lote_id, '_mm_factura_productos_esperados_count', count( $products ) );
            }

            wp_send_json_success( array(
                'message' => ! empty( $products ) ? 'Productos esperados guardados en Bodega.' : 'Datos de factura guardados. No se recibieron productos detectados.',
                'count' => count( $products ),
            ) );
        } catch ( \Throwable $e ) {
            error_log( '[MegaMundo Logistica] Error guardando factura IA en bodega: ' . $e->getMessage() );
            wp_send_json_error( array(
                'message' => 'No se pudo guardar la información de factura en bodega.',
                'technical' => current_user_can( 'manage_options' ) ? $e->getMessage() : '',
            ), 500 );
        }
    }
}
