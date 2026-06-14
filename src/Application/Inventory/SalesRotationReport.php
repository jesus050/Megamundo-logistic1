<?php
namespace MegaMundo\Logistica\Application\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Clasifica la rotación de productos a partir SOLO de los movimientos de venta
 * (sin stock ni costo todavía): el criterio es cuántos días lleva un producto
 * sin venderse. Lógica pura y testeable.
 *
 * Más adelante, cuando se cargue stock/costo, se complementa con la condición
 * de sobrestock y el valor inmovilizado (ver InventoryRotationAnalyzer).
 */
class SalesRotationReport {

    const NO_ROTA = 'no_rota';
    const LENTO   = 'lento';
    const OK      = 'ok';

    const DEFAULT_UMBRAL_NO_ROTA = 90;
    const DEFAULT_UMBRAL_LENTO   = 45;

    const SEGUNDOS_DIA = 86400;

    /**
     * @param array $rows  Filas de SalesRepository::get_rotation_rows:
     *                     sku, nombre, ultima_venta (Y-m-d), unidades_total,
     *                     unidades_30, unidades_90.
     * @param array $config umbral_no_rota, umbral_lento, hoy (Y-m-d).
     */
    public function build( array $rows, array $config = array() ) {
        $u_no_rota = isset( $config['umbral_no_rota'] ) ? (int) $config['umbral_no_rota'] : self::DEFAULT_UMBRAL_NO_ROTA;
        $u_lento   = isset( $config['umbral_lento'] ) ? (int) $config['umbral_lento'] : self::DEFAULT_UMBRAL_LENTO;
        $hoy       = isset( $config['hoy'] ) ? $config['hoy'] : ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' ) );
        $hoy_ts    = strtotime( $hoy );

        $out = array();
        $summary = array( self::NO_ROTA => 0, self::LENTO => 0, self::OK => 0, 'total' => 0 );

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $ultima = isset( $row['ultima_venta'] ) ? (string) $row['ultima_venta'] : '';
            $dias = $this->dias_sin_venta( $ultima, $hoy_ts );

            if ( null === $dias || $dias >= $u_no_rota ) {
                $estado = self::NO_ROTA;
            } elseif ( $dias >= $u_lento ) {
                $estado = self::LENTO;
            } else {
                $estado = self::OK;
            }

            $summary[ $estado ]++;
            $summary['total']++;

            $out[] = array(
                'sku'            => (string) ( $row['sku'] ?? '' ),
                'nombre'         => (string) ( $row['nombre'] ?? '' ),
                'ultima_venta'   => $ultima,
                'dias_sin_venta' => $dias,
                'unidades_total' => (int) ( $row['unidades_total'] ?? 0 ),
                'unidades_30'    => (int) ( $row['unidades_30'] ?? 0 ),
                'unidades_90'    => (int) ( $row['unidades_90'] ?? 0 ),
                'estado'         => $estado,
            );
        }

        // Lo más dormido primero (null = sin fecha válida, va al final con orden alto).
        usort( $out, function( $a, $b ) {
            $da = null === $a['dias_sin_venta'] ? PHP_INT_MAX : $a['dias_sin_venta'];
            $db = null === $b['dias_sin_venta'] ? PHP_INT_MAX : $b['dias_sin_venta'];
            return $db <=> $da;
        } );

        return array( 'items' => $out, 'summary' => $summary );
    }

    private function dias_sin_venta( $ultima_venta, $hoy_ts ) {
        if ( empty( $ultima_venta ) ) {
            return null;
        }
        $ts = strtotime( (string) $ultima_venta );
        if ( false === $ts ) {
            return null;
        }
        return max( 0, (int) floor( ( $hoy_ts - $ts ) / self::SEGUNDOS_DIA ) );
    }
}
