<?php
namespace MegaMundo\Logistica\Domain\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Acceso a la tabla wp_mm_inventario (existencias importadas de Mekano).
 * Una fila por SKU; reimportar actualiza (UNIQUE en sku).
 *
 * Por ahora solo se usa stock (la valoración en $ queda para cuando se cargue
 * un reporte con costo); el costo se guarda en 0.
 */
class InventoryRepository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'mm_inventario';
    }

    public function count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
    }

    public function get_existing_skus() {
        global $wpdb;
        $rows = $wpdb->get_col( "SELECT sku FROM {$this->table()}" );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
    }

    /** Todas las filas con stock, para el análisis de rotación. */
    public function get_stock_rows() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT sku, nombre, stock FROM {$this->table()} WHERE stock > 0",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Guarda un lote de filas de existencias por INSERT múltiple (rápido para
     * los ~20k SKU del reporte). $rows: [{sku, nombre, stock}, ...].
     * Inserta en bloques y reemplaza por SKU. Devuelve cuántas guardó.
     */
    public function bulk_replace( array $rows, $chunk = 500 ) {
        global $wpdb;
        $table = $this->table();
        $now   = current_time( 'mysql' );
        $saved = 0;

        foreach ( array_chunk( $rows, $chunk ) as $batch ) {
            $values = array();
            $params = array();
            foreach ( $batch as $r ) {
                $sku = isset( $r['sku'] ) ? (string) $r['sku'] : '';
                if ( '' === $sku ) {
                    continue;
                }
                $values[] = '(%s, %s, %d, 0, %s)';
                $params[] = $sku;
                $params[] = (string) ( $r['nombre'] ?? '' );
                $params[] = (int) ( $r['stock'] ?? 0 );
                $params[] = $now;
            }
            if ( empty( $values ) ) {
                continue;
            }
            $sql = "INSERT INTO $table (sku, nombre, stock, costo, importado_en) VALUES "
                . implode( ', ', $values )
                . " ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), stock = VALUES(stock), importado_en = VALUES(importado_en)";
            $wpdb->query( $wpdb->prepare( $sql, $params ) );
            $saved += count( $values );
        }
        return $saved;
    }
}
