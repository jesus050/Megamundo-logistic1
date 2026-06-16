<?php
namespace MegaMundo\Logistica\Application\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Rotación combinada: cruza las EXISTENCIAS (stock por SKU) con las VENTAS
 * (última venta por SKU) para detectar mercancía parada. Por ahora mide en
 * unidades (sin valor en $, hasta cargar costo).
 *
 * El análisis es relativo al periodo de datos cargado (p. ej. 1 de abril a
 * hoy): un producto "no rota" si tiene stock y NO se vendió en ese periodo, o
 * si lleva muchos días sin venderse. Por eso el módulo muestra el periodo.
 */
class StockRotationReport {

    const NO_ROTA = 'no_rota';
    const LENTO   = 'lento';
    const OK      = 'ok';

    const DEFAULT_UMBRAL_NO_ROTA = 90;
    const DEFAULT_UMBRAL_LENTO   = 45;
    const SEGUNDOS_DIA = 86400;

    /**
     * @param array $stock_rows  Existencias: [{sku, nombre, stock}, ...] (stock > 0).
     * @param array $ventas_por_sku  Mapa sku => ultima_venta (Y-m-d). SKU ausente = sin ventas en el periodo.
     * @param array $config  hoy, umbral_no_rota, umbral_lento.
     */
    public function build( array $stock_rows, array $ventas_por_sku, array $config = array() ) {
        $u_no_rota = isset( $config['umbral_no_rota'] ) ? (int) $config['umbral_no_rota'] : self::DEFAULT_UMBRAL_NO_ROTA;
        $u_lento   = isset( $config['umbral_lento'] ) ? (int) $config['umbral_lento'] : self::DEFAULT_UMBRAL_LENTO;
        $hoy       = isset( $config['hoy'] ) ? $config['hoy'] : ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' ) );
        $hoy_ts    = strtotime( $hoy );

        $out = array();
        $summary = array(
            self::NO_ROTA => 0, self::LENTO => 0, self::OK => 0,
            'total' => 0, 'unidades_paradas' => 0,
        );

        foreach ( $stock_rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }
            $sku   = (string) ( $row['sku'] ?? '' );
            $stock = (int) ( $row['stock'] ?? 0 );
            if ( '' === $sku || $stock <= 0 ) { continue; }

            $ultima = isset( $ventas_por_sku[ $sku ] ) ? (string) $ventas_por_sku[ $sku ] : '';
            $dias   = $this->dias_sin_venta( $ultima, $hoy_ts );

            if ( null === $dias || $dias >= $u_no_rota ) {
                $estado = self::NO_ROTA;          // con stock y sin venta (o muy vieja)
            } elseif ( $dias >= $u_lento ) {
                $estado = self::LENTO;
            } else {
                $estado = self::OK;
            }

            $summary[ $estado ]++;
            $summary['total']++;
            if ( self::NO_ROTA === $estado ) {
                $summary['unidades_paradas'] += $stock;
            }

            $out[] = array(
                'sku'            => $sku,
                'nombre'         => (string) ( $row['nombre'] ?? '' ),
                'stock'          => $stock,
                'ultima_venta'   => $ultima,
                'dias_sin_venta' => $dias,
                'estado'         => $estado,
            );
        }

        // Más unidades paradas primero (el problema más grande arriba), y dentro
        // de eso, lo más dormido.
        $rank = array( self::NO_ROTA => 0, self::LENTO => 1, self::OK => 2 );
        usort( $out, function( $a, $b ) use ( $rank ) {
            if ( $rank[ $a['estado'] ] !== $rank[ $b['estado'] ] ) {
                return $rank[ $a['estado'] ] - $rank[ $b['estado'] ];
            }
            return $b['stock'] <=> $a['stock'];
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
