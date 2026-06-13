<?php
namespace MegaMundo\Logistica\Application\Quality;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compara cantidades esperadas vs recibidas/contadas en un lote y marca
 * anomalías ANTES de sincronizar a WooCommerce, para que un error de conteo
 * no termine inflando o vaciando el stock real.
 *
 * Opera sobre el array de productos que produce mm45_received_products_from_lote
 * (BodegaTrait): cada item con claves codigo, nombre, esperado, recibido.
 * Lógica pura: sin estado, sin dependencias de WordPress, testeable.
 */
class CountAnomalyDetector {

    const OK          = 'ok';
    const FALTANTE    = 'faltante';     // recibido < esperado
    const EXCESO      = 'exceso';       // recibido > esperado
    const NO_ESPERADO = 'no_esperado';  // llegó algo que no estaba en la lista
    const SIN_RECIBIR = 'sin_recibir';  // esperado > 0 y recibido 0

    /** Desviación relativa a partir de la cual una anomalía es "alta". */
    const HIGH_DEVIATION_PCT = 20.0;

    /**
     * @param array $items Lista de {codigo, nombre, esperado, recibido}.
     * @return array {
     *   anomalies: lista de anomalías (sin los items OK),
     *   summary: conteos por tipo + total revisado,
     *   has_blocking: true si hay faltantes/sin_recibir (deberían frenar el cierre)
     * }
     */
    public function analyze( array $items ) {
        $anomalies = array();
        $summary = array(
            self::OK          => 0,
            self::FALTANTE    => 0,
            self::EXCESO      => 0,
            self::NO_ESPERADO => 0,
            self::SIN_RECIBIR => 0,
        );

        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $esperado = intval( $item['esperado'] ?? 0 );
            $recibido = intval( $item['recibido'] ?? 0 );
            $tipo = $this->classify( $esperado, $recibido );

            $summary[ $tipo ]++;

            if ( self::OK === $tipo ) {
                continue;
            }

            $anomalies[] = array(
                'codigo'        => (string) ( $item['codigo'] ?? $item['sku'] ?? '' ),
                'nombre'        => (string) ( $item['nombre'] ?? '' ),
                'esperado'      => $esperado,
                'recibido'      => $recibido,
                'diferencia'    => $recibido - $esperado,
                'tipo'          => $tipo,
                'desviacion'    => $this->deviation_pct( $esperado, $recibido ),
                'severidad'     => $this->severity( $tipo, $esperado, $recibido ),
            );
        }

        $summary['total'] = count( $items );

        return array(
            'anomalies'    => $anomalies,
            'summary'      => $summary,
            'has_blocking' => ( $summary[ self::FALTANTE ] > 0 || $summary[ self::SIN_RECIBIR ] > 0 ),
        );
    }

    private function classify( $esperado, $recibido ) {
        if ( $esperado <= 0 && $recibido > 0 ) {
            return self::NO_ESPERADO;
        }
        if ( $esperado > 0 && $recibido <= 0 ) {
            return self::SIN_RECIBIR;
        }
        if ( $recibido < $esperado ) {
            return self::FALTANTE;
        }
        if ( $recibido > $esperado ) {
            return self::EXCESO;
        }
        return self::OK;
    }

    private function deviation_pct( $esperado, $recibido ) {
        if ( $esperado <= 0 ) {
            return $recibido > 0 ? 100.0 : 0.0;
        }
        return round( ( abs( $recibido - $esperado ) / $esperado ) * 100, 1 );
    }

    private function severity( $tipo, $esperado, $recibido ) {
        if ( self::NO_ESPERADO === $tipo || self::SIN_RECIBIR === $tipo ) {
            return 'alta';
        }
        return $this->deviation_pct( $esperado, $recibido ) >= self::HIGH_DEVIATION_PCT ? 'alta' : 'media';
    }
}
