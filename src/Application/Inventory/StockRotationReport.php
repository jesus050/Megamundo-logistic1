<?php
namespace MegaMundo\Logistica\Application\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Rotación combinada: cruza las EXISTENCIAS con las VENTAS para detectar
 * mercancía parada.
 *
 * Fuente 1 (preferida): CSV de ventas cargado → da la fecha exacta de la
 * última venta y clasifica por días sin vender.
 *
 * Fuente 2 (fallback): columna SALIDAS del reporte de existencias de Mekano.
 * Si no hay CSV de ventas pero sí hay salidas en el inventario, el producto
 * SÍ rotó en el periodo. Se clasifica así:
 *
 *   salidas = 0                        → No rota   🔴  (no se vendió nada)
 *   salidas > 0 y ratio < umbral_lento → Lento     🟡  (se movió pero poco)
 *   salidas > 0 y ratio ≥ umbral_ok   → Rotando   🟢  (rotación aceptable)
 *
 * El ratio se calcula como: salidas / (viene + entradas).
 * Si viene + entradas = 0, se usa salidas / stock como proxy.
 *
 * Umbrales de ratio (configurables):
 *   ratio_ok    = 0.20  → vendió ≥ 20 % del disponible → Rotando
 *   ratio_lento = 0.01  → vendió algo (≥ 1 %) pero < 20 % → Lento
 *
 * Nota: cargar el CSV de ventas siempre da información más precisa
 * (fecha exacta, días sin vender), pero incluso sin él la tabla ya
 * distingue correctamente entre "no rotó nada" / "rotó poco" / "rotó bien".
 */
class StockRotationReport {

    const NO_ROTA = 'no_rota';
    const LENTO   = 'lento';
    const OK      = 'ok';

    const DEFAULT_UMBRAL_NO_ROTA = 90;
    const DEFAULT_UMBRAL_LENTO   = 45;
    const SEGUNDOS_DIA = 86400;

    // Umbrales de ratio de salidas (cuando no hay CSV de ventas)
    const RATIO_OK    = 0.20;   // vendió ≥ 20 % del disponible → Rotando
    const RATIO_LENTO = 0.01;   // vendió algo (≥ 1 %) pero poco → Lento

    /**
     * @param array $stock_rows      Existencias: [{sku, nombre, viene, entradas, salidas, stock}, ...].
     * @param array $ventas_por_sku  Mapa sku => ultima_venta (Y-m-d). Vacío = no se cargó CSV de ventas.
     * @param array $config          hoy, umbral_no_rota, umbral_lento, ratio_ok, ratio_lento.
     */
    public function build( array $stock_rows, array $ventas_por_sku, array $config = array() ) {
        $u_no_rota  = isset( $config['umbral_no_rota'] ) ? (int) $config['umbral_no_rota'] : self::DEFAULT_UMBRAL_NO_ROTA;
        $u_lento    = isset( $config['umbral_lento'] )   ? (int) $config['umbral_lento']   : self::DEFAULT_UMBRAL_LENTO;
        $ratio_ok   = isset( $config['ratio_ok'] )       ? (float) $config['ratio_ok']     : self::RATIO_OK;
        $ratio_lento= isset( $config['ratio_lento'] )    ? (float) $config['ratio_lento']  : self::RATIO_LENTO;
        $hoy        = isset( $config['hoy'] ) ? $config['hoy'] : ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' ) );
        $hoy_ts     = strtotime( $hoy );

        $hay_ventas_csv = ! empty( $ventas_por_sku );

        $out     = array();
        $summary = array(
            self::NO_ROTA => 0, self::LENTO => 0, self::OK => 0,
            'total' => 0, 'unidades_paradas' => 0,
        );

        foreach ( $stock_rows as $row ) {
            if ( ! is_array( $row ) ) { continue; }

            $sku      = (string) ( $row['sku']      ?? '' );
            $stock    = (int)    ( $row['stock']    ?? 0 );
            $viene    = (int)    ( $row['viene']    ?? 0 );
            $entradas = (int)    ( $row['entradas'] ?? 0 );
            $salidas  = (int)    ( $row['salidas']  ?? 0 );

            if ( '' === $sku || $stock <= 0 ) { continue; }

            // ── Fuente 1: CSV de ventas (fecha exacta) ────────────────────
            $ultima = isset( $ventas_por_sku[ $sku ] ) ? (string) $ventas_por_sku[ $sku ] : '';
            $dias   = $this->dias_sin_venta( $ultima, $hoy_ts );

            if ( null !== $dias ) {
                // Tenemos fecha exacta → clasificar por días sin vender.
                if ( $dias >= $u_no_rota ) {
                    $estado = self::NO_ROTA;
                } elseif ( $dias >= $u_lento ) {
                    $estado = self::LENTO;
                } else {
                    $estado = self::OK;
                }
            } else {
                // ── Fuente 2: columna SALIDAS del inventario ──────────────
                // No hay fecha de venta en el CSV, pero la columna SALIDAS del
                // reporte de Mekano ya nos dice cuántas unidades salieron en
                // el periodo importado.
                if ( $salidas <= 0 ) {
                    // No salió nada → definitivamente no rotó.
                    $estado = self::NO_ROTA;
                } else {
                    // Sí salió algo: calcular ratio vendido / disponible.
                    $disponible = $viene + $entradas;
                    if ( $disponible > 0 ) {
                        $ratio = $salidas / $disponible;
                    } else {
                        // Caso raro: salidas pero viene+entradas = 0 → usar stock+salidas
                        $ratio = $salidas / ( $stock + $salidas );
                    }

                    if ( $ratio >= $ratio_ok ) {
                        $estado = self::OK;    // rotó bien (≥ 20 % del disponible)
                    } else {
                        $estado = self::LENTO; // rotó algo pero poco (< 20 %)
                    }
                }
            }

            $summary[ $estado ]++;
            $summary['total']++;
            if ( self::NO_ROTA === $estado ) {
                $summary['unidades_paradas'] += $stock;
            }

            $out[] = array(
                'sku'            => $sku,
                'nombre'         => (string) ( $row['nombre'] ?? '' ),
                'viene'          => $viene,
                'entradas'       => $entradas,
                'salidas'        => $salidas,
                'stock'          => $stock,
                'ultima_venta'   => $ultima,
                'dias_sin_venta' => $dias,
                'estado'         => $estado,
            );
        }

        // Orden: No rota primero (más problemático), luego Lento, luego OK.
        // Dentro de cada grupo, mayor stock arriba.
        $rank = array( self::NO_ROTA => 0, self::LENTO => 1, self::OK => 2 );
        usort( $out, function( $a, $b ) use ( $rank ) {
            if ( $rank[ $a['estado'] ] !== $rank[ $b['estado'] ] ) {
                return $rank[ $a['estado'] ] - $rank[ $b['estado'] ];
            }
            return $b['stock'] <=> $a['stock'];
        } );

        return array(
            'items'          => $out,
            'summary'        => $summary,
            'fuente_ventas'  => $hay_ventas_csv ? 'csv' : 'salidas_inventario',
        );
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
