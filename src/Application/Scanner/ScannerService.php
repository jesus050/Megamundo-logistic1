<?php
namespace MegaMundo\Logistica\Application\Scanner;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Domain\Lote\LoteStatuses;
use MegaMundo\Logistica\Domain\Lote\LoteAuditRepository;
use MegaMundo\Logistica\Infrastructure\WooCommerce\WooCommerceGateway;

class ScannerService {

    private $lote_repo;
    private $item_repo;
    private $wc_gateway;
    private $audit_repo;

    public function __construct( LoteRepository $lote_repo, LoteItemRepository $item_repo, WooCommerceGateway $wc_gateway, LoteAuditRepository $audit_repo ) {
        $this->lote_repo  = $lote_repo;
        $this->item_repo  = $item_repo;
        $this->wc_gateway = $wc_gateway;
        $this->audit_repo = $audit_repo;
    }

    public function registrar_escaneo( $lote_id, $sku_producto, $cantidad, $nombre_nuevo, $foto_producto = null, $user_id = 0 ) {
        // 1. Validar que el lote exista
        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            return new \WP_Error( 'lote_invalido', 'El lote especificado no existe o es inválido.', array( 'status' => 400 ) );
        }

        // 2. Validar que el lote no esté cerrado
        $estado = $this->lote_repo->get_status( $lote_id );
        if ( LoteStatuses::LOADED === $estado ) {
            return new \WP_Error( 'lote_cerrado', 'El lote ya ha sido procesado e inyectado. No se permiten más escaneos.', array( 'status' => 400 ) );
        }

        $producto_id = $this->wc_gateway->find_product_id_by_sku( $sku_producto );
        $es_nuevo = false;

        if ( ! $producto_id ) {
            if ( empty( $nombre_nuevo ) ) {
                return new \WP_Error( 'requiere_nombre', 'Este código no existe. Digita su nombre.', array( 'status' => 404 ) );
            }

            // Crear el producto en borrador
            $producto_id = $this->wc_gateway->create_draft_product( $sku_producto, $nombre_nuevo );
            if ( ! $producto_id ) {
                return new \WP_Error( 'error_creacion', 'No se pudo crear el producto en el sistema.', array( 'status' => 500 ) );
            }

            // Procesar foto si viene en el request
            if ( ! empty( $foto_producto ) && ! empty( $foto_producto['tmp_name'] ) ) {
                require_once( ABSPATH . 'wp-admin/includes/image.php' );
                require_once( ABSPATH . 'wp-admin/includes/file.php' );
                require_once( ABSPATH . 'wp-admin/includes/media.php' );

                $archivo_temp    = $foto_producto['tmp_name'];
                $nombre_original = pathinfo( $foto_producto['name'], PATHINFO_FILENAME );

                // Validar tipo de archivo y mime type
                $check = wp_check_filetype( $foto_producto['name'] );
                $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
                if ( ! in_array( $check['type'], $allowed_mimes, true ) ) {
                    return new \WP_Error( 'formato_invalido', 'El archivo no es una imagen válida.', array( 'status' => 400 ) );
                }

                // Validar peso máximo (5MB)
                $max_size = 5 * 1024 * 1024;
                if ( $foto_producto['size'] > $max_size ) {
                    return new \WP_Error( 'archivo_muy_grande', 'La foto no debe superar los 5MB.', array( 'status' => 400 ) );
                }

                // Redimensionar e intentar guardar como WebP
                $editor = wp_get_image_editor( $archivo_temp );
                if ( ! is_wp_error( $editor ) ) {
                    $editor->resize( 1200, 1200, false );
                    $nuevo_temp = $archivo_temp . '.webp';
                    $guardado   = $editor->save( $nuevo_temp, 'image/webp' );

                    if ( ! is_wp_error( $guardado ) ) {
                        $foto_producto['tmp_name'] = $nuevo_temp;
                        $foto_producto['type']     = 'image/webp';
                        $foto_producto['name']     = sanitize_title( $nombre_original ) . '.webp';
                        $foto_producto['size']     = filesize( $nuevo_temp );
                    }
                }

                // Sideload a WordPress
                $attachment_id = media_handle_sideload( $foto_producto, $producto_id );
                if ( ! is_wp_error( $attachment_id ) ) {
                    set_post_thumbnail( $producto_id, $attachment_id );
                }
            }

            $es_nuevo = true;
        }

        // Registrar en nuestra base de datos
        $existing_item = $this->item_repo->find_by_sku( $lote_id, $sku_producto );

        if ( $existing_item ) {
            $old_qty = intval( $existing_item->cantidad_contada );
            $new_qty = $old_qty + $cantidad;
            $updated = $this->item_repo->update_item( $existing_item->id, $lote_id, array(
                'cantidad_contada' => $new_qty
            ) );

            if ( ! $updated ) {
                return new \WP_Error( 'db_error', 'Error actualizando cantidad en base de datos.', array( 'status' => 500 ) );
            }

            $inserted_id = $existing_item->id;

            // Auditar el incremento de cantidad
            $this->audit_repo->add_log(
                $lote_id,
                'cantidad_modificada',
                sprintf( 'Cantidad de SKU %s incrementada de %d a %d por escaneo.', $sku_producto, $old_qty, $new_qty ),
                $inserted_id,
                $producto_id,
                $sku_producto,
                $old_qty,
                $new_qty,
                $user_id
            );
        } else {
            $data = array(
                'lote_id'          => $lote_id,
                'producto_id'      => $producto_id,
                'sku'              => $sku_producto,
                'cantidad_contada' => $cantidad,
                'created_by'       => $user_id
            );

            $inserted_id = $this->item_repo->insert_item( $data );
            if ( ! $inserted_id ) {
                return new \WP_Error( 'db_error', 'Error guardando en base de datos el escaneo.', array( 'status' => 500 ) );
            }

            // Auditar el evento de creación de producto si es nuevo
            if ( $es_nuevo ) {
                $this->audit_repo->add_log(
                    $lote_id,
                    'producto_nuevo_creado',
                    sprintf( 'Nuevo producto borrador creado con SKU: %s, Nombre: %s', $sku_producto, $nombre_nuevo ),
                    $inserted_id,
                    $producto_id,
                    $sku_producto,
                    null,
                    null,
                    $user_id
                );
            }

            // Auditar el escaneo inicial
            $this->audit_repo->add_log(
                $lote_id,
                'producto_escaneado',
                sprintf( 'Producto escaneado. SKU: %s, Cantidad: %d', $sku_producto, $cantidad ),
                $inserted_id,
                $producto_id,
                $sku_producto,
                null,
                $cantidad,
                $user_id
            );
        }

        $nombre_oficial = get_the_title( $producto_id );
        $mensaje = '✅ ' . $cantidad . 'x ' . $nombre_oficial . ' guardado.';
        if ( $es_nuevo ) {
            $mensaje = '✨ Creado con éxito: ' . $nombre_oficial;
        }

        return array(
            'success' => true,
            'mensaje' => $mensaje,
            'item_id' => $inserted_id
        );
    }
}
