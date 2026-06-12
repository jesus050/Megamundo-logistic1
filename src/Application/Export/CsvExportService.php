<?php
namespace MegaMundo\Logistica\Application\Export;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;

class CsvExportService {

    private $lote_repo;
    private $item_repo;
    private $permission_guard;

    public function __construct( LoteRepository $lote_repo, LoteItemRepository $item_repo, PermissionGuard $permission_guard ) {
        $this->lote_repo        = $lote_repo;
        $this->item_repo        = $item_repo;
        $this->permission_guard = $permission_guard;
    }

    public function exportar_lote( $lote_id ) {
        // 1. Validar Permisos
        if ( ! $this->permission_guard->can_export() ) {
            wp_die( 'No tienes permisos suficientes para exportar este lote.' );
        }
        
        // 2. Validar existencia del lote
        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            wp_die( 'El lote especificado no existe o es inválido.' );
        }
        
        // 3. Obtener items
        $items = $this->item_repo->get_by_lote( $lote_id );
        if ( empty( $items ) ) {
            wp_die( 'El lote no contiene productos registrados para exportar.' );
        }
        
        // Configurar cabeceras del CSV de descarga
        $filename = 'lote-ingreso-' . $lote_id . '-' . date('Y-m-d') . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        
        $output = fopen( 'php://output', 'w' );
        
        // BOM UTF-8 para evitar problemas de codificación de caracteres en Excel
        fprintf( $output, chr(0xEF).chr(0xBB).chr(0xBF) );
        
        $delimiter = ';';
        
        // Cabecera de columnas
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
