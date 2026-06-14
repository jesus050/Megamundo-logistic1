<?php
namespace MegaMundo\Logistica\Application\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Analiza la rotación del inventario importado (de Mekano) para detectar
 * mercancía que no rota. Lógica pura, sin dependencias de WordPress ni de la
 * base de datos: recibe filas como arrays y devuelve la clasificación.
 *
 * Definición acordada (combinada): un producto es "muerto" cuando cumple
 * AMBAS condiciones — lleva mucho tiempo sin venderse Y tiene stock acumulado
 * para varios meses. Si cumple solo una, es "lento". El número que más importa
 * es el valor inmovilizado (stock x costo): el dinero atascado en bodega.
 */
class InventoryRotationAnalyzer {

    const MUERTO    = 'muerto';
    const LENTO     = 'lento';
    const OK        = 'ok';
    const SIN_DATOS = 'sin_datos';

    const SEGUNDOS_DIA = 86400;

    /** Umbrales por defecto (configurables vía $config). */
    const DEFAULT_DIAS_SIN_VENTA = 90;   // días sin venderse para considerarlo estancado
    const DEFAULT_MESES_INV      = 6;    // meses de inventario acumulado para considerarlo sobrestock

    /**
     * @param array $items  Filas con: sku, nombre, stock, costo,
     *                      ultima_venta (Y-m-d|null), vendido_periodo (int|null),
     *                      periodo_dias (int|null).
     * @param array $config dias_sin_venta_umbral, meses_inventario_umbral, hoy (Y-m-d).
     * @return array { items: [...], summary: {...} }
     */
    public function analyze( array $items, array $config = array() ) {
        $umbral_dias  = isset( $config['dias_sin_venta_umbral'] ) ? (int) $config['dias_sin_venta_umbral'] : self::DEFAULT_DIAS_SIN_VENTA;
        $umbral_meses = isset( $config['meses_inventario_umbral'] ) ? (float) $config['meses_inventario_umbral'] : self::DEFAULT_MESES_INV;
        $hoy          = isset( $config['hoy'] ) ? $config['hoy'] : ( function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : date( 'Y-m-d' ) );
        $hoy_ts       = strtotime( $hoy );

        $out = array();
        $summary = array(
            self::MUERTO => 0, self::LENTO => 0, self::OK => 0, self::SIN_DATOS => 0,
            'total' => 0, 'valor_inmovilizado' => 0.0, 'valor_total' => 0.0,
        );

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) { continue; }

            $stock = isset( $item['stock'] ) ? (int) $item['stock'] : 0;
            $costo = isset( $item['costo'] ) ? (float) $item['costo'] : 0.0;
            $valor = round( $stock * $costo, 2 );

            $dias_sin_venta = $this->dias_sin_venta( $item['ultima_venta'] ?? null, $hoy_ts );
            $meses_inv      = $this->meses_inventario( $stock, $item['vendido_periodo'] ?? null, $item['periodo_dias'] ?? null );

            $cond_tiempo = ( null !== $dias_sin_venta && $dias_sin_venta >= $umbral_dias );
            $cond_stock  = ( null !== $meses_inv && $meses_inv >= $umbral_meses );

            if ( null === $dias_sin_venta && null === $meses_inv ) {
                $estado = self::SIN_DATOS;
            } elseif ( $cond_tiempo && $cond_stock ) {
                $estado = self::MUERTO;
            } elseif ( $cond_tiempo || $cond_stock ) {
                $estado = self::LENTO;
            } else {
                $estado = self::OK;
            }

            $summary[ $estado ]++;
            $summary['total']++;
            $summary['valor_total'] += $valor;
            if ( self::MUERTO === $estado || self::LENTO === $estado ) {
                $summary['valor_inmovilizado'] += $valor;
            }

            $out[] = array(
                'sku'             => (string) ( $item['sku'] ?? '' ),
                'nombre'          => (string) ( $item['nombre'] ?? '' ),
                'stock'           => $stock,
                'costo'           => $costo,
                'valor'           => $valor,
                'dias_sin_venta'  => $dias_sin_venta,
                'meses_inventario'=> $meses_inv,
                'estado'          => $estado,
            );
        }

        $summary['valor_inmovilizado'] = round( $summary['valor_inmovilizado'], 2 );
        $summary['valor_total']        = round( $summary['valor_total'], 2 );

        // Ordenar peor rotación primero, y dentro de eso por mayor valor atascado.
        $rank = array( self::MUERTO => 0, self::LENTO => 1, self::SIN_DATOS => 2, self::OK => 3 );
        usort( $out, function( $a, $b ) use ( $rank ) {
            if ( $rank[ $a['estado'] ] !== $rank[ $b['estado'] ] ) {
                return $rank[ $a['estado'] ] - $rank[ $b['estado'] ];
            }
            return $b['valor'] <=> $a['valor'];
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
        $dias = (int) floor( ( $hoy_ts - $ts ) / self::SEGUNDOS_DIA );
        return max( 0, $dias );
    }

    /**
     * Meses de inventario acumulado = stock / ventas mensuales.
     * Si hay stock pero cero ventas en el periodo, devuelve un valor alto
     * acotado (PHP no serializa INF a JSON).
     */
    private function meses_inventario( $stock, $vendido_periodo, $periodo_dias ) {
        if ( null === $vendido_periodo || null === $periodo_dias || (int) $periodo_dias <= 0 ) {
            return null;
        }
        $ventas_mensuales = ( (float) $vendido_periodo / (int) $periodo_dias ) * 30.0;
        if ( $ventas_mensuales <= 0 ) {
            return $stock > 0 ? 999.0 : 0.0;
        }
        return round( $stock / $ventas_mensuales, 1 );
    }
}
