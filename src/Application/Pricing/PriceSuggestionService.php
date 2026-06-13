<?php
namespace MegaMundo\Logistica\Application\Pricing;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Sugiere precios de venta (detal / mayor / gran mayor) a partir del costo
 * y de márgenes objetivo, respetando la regla del negocio detal ≥ mayor ≥
 * gran mayor (ver validar_precios_multiples_item en PreciosTrait).
 *
 * Lógica determinista a propósito: para calcular un margen no hace falta IA,
 * y un resultado reproducible es auditable y gratis. La IA se reserva para
 * lo que sí la necesita (lectura de facturas, clasificación).
 */
class PriceSuggestionService {

    /** Márgenes por defecto en porcentaje, si no hay configuración guardada. */
    const DEFAULT_MARGINS = array(
        'detal'      => 40.0,
        'mayor'      => 25.0,
        'gran_mayor' => 15.0,
    );

    /** Precio se redondea al múltiplo más cercano de este valor (pesos). */
    const ROUND_TO = 50;

    /**
     * Precio de venta para un margen dado: margin = (precio - costo) / precio.
     * Despejado: precio = costo / (1 - margin/100).
     */
    public function price_for_margin( $cost, $margin_percent ) {
        $cost = floatval( $cost );
        $margin = floatval( $margin_percent );

        if ( $cost <= 0 ) {
            return 0.0;
        }
        // Margen >= 100% es matemáticamente imposible (precio infinito); lo acotamos.
        if ( $margin >= 100 ) {
            $margin = 99.0;
        }
        if ( $margin < 0 ) {
            $margin = 0.0;
        }

        $price = $cost / ( 1 - ( $margin / 100 ) );
        return $this->round_price( $price );
    }

    /**
     * Sugiere los tres precios para un costo. $margins puede sobrescribir los
     * porcentajes por tier; las claves ausentes usan DEFAULT_MARGINS.
     *
     * Devuelve precios + el margen real resultante de cada uno (puede diferir
     * levemente del objetivo por el redondeo).
     */
    public function suggest( $cost, array $margins = array() ) {
        $cost = floatval( $cost );
        $margins = array_merge( self::DEFAULT_MARGINS, array_filter(
            $margins,
            function( $v ) { return is_numeric( $v ); }
        ) );

        // Garantizar detal ≥ mayor ≥ gran mayor a nivel de margen objetivo.
        $detal_m = max( $margins['detal'], $margins['mayor'], $margins['gran_mayor'] );
        $gran_m  = min( $margins['detal'], $margins['mayor'], $margins['gran_mayor'] );
        $mayor_m = $margins['detal'] + $margins['mayor'] + $margins['gran_mayor'] - $detal_m - $gran_m;

        $detal      = $this->price_for_margin( $cost, $detal_m );
        $mayor      = $this->price_for_margin( $cost, $mayor_m );
        $gran_mayor = $this->price_for_margin( $cost, $gran_m );

        return array(
            'costo'      => $cost,
            'detal'      => $detal,
            'mayor'      => $mayor,
            'gran_mayor' => $gran_mayor,
            'margenes'   => array(
                'detal'      => $this->actual_margin( $cost, $detal ),
                'mayor'      => $this->actual_margin( $cost, $mayor ),
                'gran_mayor' => $this->actual_margin( $cost, $gran_mayor ),
            ),
        );
    }

    private function actual_margin( $cost, $price ) {
        $price = floatval( $price );
        if ( $price <= 0 ) {
            return 0.0;
        }
        return round( ( ( $price - floatval( $cost ) ) / $price ) * 100, 1 );
    }

    private function round_price( $price ) {
        if ( self::ROUND_TO <= 0 ) {
            return round( $price, 2 );
        }
        return (float) ( round( $price / self::ROUND_TO ) * self::ROUND_TO );
    }
}
