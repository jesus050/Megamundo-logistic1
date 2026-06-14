<?php
namespace MegaMundo\Logistica\Domain\Sales;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Acceso a la tabla wp_mm_ventas (movimientos de venta importados de Mekano).
 * Clave única (sku, fecha): reimportar la misma fecha reemplaza, no duplica.
 */
class SalesRepository {

    private function table() {
        global $wpdb;
        return $wpdb->prefix . 'mm_ventas';
    }

    /** Fechas ya importadas, para avisar de reimportaciones. */
    public function get_imported_dates() {
        global $wpdb;
        $rows = $wpdb->get_col( "SELECT DISTINCT fecha FROM {$this->table()}" );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
    }

    /** Inserta o reemplaza el total de un SKU en una fecha. */
    public function upsert_dia( $sku, $nombre, $fecha, $unidades, $valor_neto ) {
        global $wpdb;
        $sku = (string) $sku;
        if ( '' === $sku || empty( $fecha ) ) {
            return false;
        }
        $data = array(
            'sku'          => $sku,
            'nombre'       => (string) $nombre,
            'fecha'        => (string) $fecha,
            'unidades'     => (int) $unidades,
            'valor_neto'   => (float) $valor_neto,
            'importado_en' => current_time( 'mysql' ),
        );
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE sku = %s AND fecha = %s",
            $sku,
            $fecha
        ) );
        if ( $existing ) {
            return false !== $wpdb->update( $this->table(), $data, array( 'id' => (int) $existing ) );
        }
        return false !== $wpdb->insert( $this->table(), $data );
    }

    public function bulk_upsert( array $dias ) {
        $saved = 0;
        foreach ( $dias as $d ) {
            if ( $this->upsert_dia( $d['sku'] ?? '', $d['nombre'] ?? '', $d['fecha'] ?? '', $d['unidades'] ?? 0, $d['valor_neto'] ?? 0 ) ) {
                $saved++;
            }
        }
        return $saved;
    }

    /**
     * Resumen por SKU para el análisis de rotación: última venta, unidades
     * totales y unidades en los últimos 30/90 días.
     */
    public function get_rotation_rows( $hoy = null ) {
        global $wpdb;
        $hoy = $hoy ?: current_time( 'Y-m-d' );
        $d30 = date( 'Y-m-d', strtotime( $hoy . ' -30 days' ) );
        $d90 = date( 'Y-m-d', strtotime( $hoy . ' -90 days' ) );

        $sql = $wpdb->prepare(
            "SELECT sku,
                    MAX(nombre) AS nombre,
                    MAX(fecha) AS ultima_venta,
                    SUM(unidades) AS unidades_total,
                    SUM(CASE WHEN fecha >= %s THEN unidades ELSE 0 END) AS unidades_30,
                    SUM(CASE WHEN fecha >= %s THEN unidades ELSE 0 END) AS unidades_90
             FROM {$this->table()}
             GROUP BY sku",
            $d30,
            $d90
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }
}
