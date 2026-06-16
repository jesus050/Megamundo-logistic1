<?php
namespace MegaMundo\Logistica\Domain\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Acceso a la tabla wp_mm_inventario (existencias importadas de Mekano).
 * Una fila por SKU; reimportar actualiza (UNIQUE en sku).
 *
 * Columnas de Mekano que guardamos:
 *   viene    = saldo inicial del periodo
 *   entradas = unidades que ingresaron al bodegaje
 *   salidas  = unidades vendidas / despachadas
 *   stock    = EXISTENCIA = viene + entradas − salidas
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

    /**
     * Filas con stock > 0 para el análisis de rotación.
     * Incluye viene, entradas y salidas para mostrar el detalle completo.
     */
    public function get_stock_rows() {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT sku, nombre, viene, entradas, salidas, stock
             FROM {$this->table()}
             WHERE stock > 0
             ORDER BY sku ASC",
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Guarda un lote de filas de existencias en bloques rápidos (INSERT múltiple).
     * Si el SKU ya existe, actualiza todos los campos importados.
     *
     * @param array $rows  [{sku, nombre, viene, entradas, salidas, stock}, ...]
     * @param int   $chunk Tamaño del lote de INSERT.
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
                if ( '' === $sku ) { continue; }

                $values[] = '(%s, %s, %d, %d, %d, %d, 0, %s)';
                $params[] = $sku;
                $params[] = (string) ( isset( $r['nombre'] )   ? $r['nombre']   : '' );
                $params[] = (int)    ( isset( $r['viene'] )    ? $r['viene']    : 0 );
                $params[] = (int)    ( isset( $r['entradas'] ) ? $r['entradas'] : 0 );
                $params[] = (int)    ( isset( $r['salidas'] )  ? $r['salidas']  : 0 );
                $params[] = (int)    ( isset( $r['stock'] )    ? $r['stock']    : 0 );
                $params[] = $now;
            }

            if ( empty( $values ) ) { continue; }

            $sql = "INSERT INTO $table (sku, nombre, viene, entradas, salidas, stock, costo, importado_en) VALUES "
                . implode( ', ', $values )
                . " ON DUPLICATE KEY UPDATE
                    nombre       = VALUES(nombre),
                    viene        = VALUES(viene),
                    entradas     = VALUES(entradas),
                    salidas      = VALUES(salidas),
                    stock        = VALUES(stock),
                    importado_en = VALUES(importado_en)";

            $wpdb->query( $wpdb->prepare( $sql, $params ) );
            $saved += count( $values );
        }

        return $saved;
    }
}
