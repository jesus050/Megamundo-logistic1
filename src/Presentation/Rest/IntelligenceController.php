<?php
namespace MegaMundo\Logistica\Presentation\Rest;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use WP_REST_Request;
use WP_Error;
use MegaMundo\Logistica\Application\Pricing\PriceSuggestionService;
use MegaMundo\Logistica\Application\Quality\CountAnomalyDetector;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;

/**
 * Endpoints de apoyo a la decisión (Fase 3): sugerencia de precios y
 * detección de anomalías de conteo. Aislado de los traits de la app para
 * poder evolucionar sin tocar la capa de presentación grande.
 */
class IntelligenceController {

    private $price_service;
    private $anomaly_detector;
    private $permission_guard;

    public function __construct(
        PriceSuggestionService $price_service,
        CountAnomalyDetector $anomaly_detector,
        PermissionGuard $permission_guard
    ) {
        $this->price_service    = $price_service;
        $this->anomaly_detector = $anomaly_detector;
        $this->permission_guard = $permission_guard;
    }

    public function register_routes() {
        register_rest_route( 'megamundo/v1', '/precios/sugerir', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'sugerir_precios' ),
            'permission_callback' => array( $this, 'puede_precios' ),
        ) );

        register_rest_route( 'megamundo/v1', '/lote/(?P<id>\d+)/anomalias', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'detectar_anomalias' ),
            'permission_callback' => array( $this, 'puede_bodega' ),
        ) );
    }

    public function puede_precios() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Debes iniciar sesión.', array( 'status' => 401 ) );
        }
        if ( ! $this->permission_guard->can_access_precios_panel() && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return new WP_Error( 'rest_forbidden', 'No tienes permiso para sugerir precios.', array( 'status' => 403 ) );
        }
        return true;
    }

    public function puede_bodega() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Debes iniciar sesión.', array( 'status' => 401 ) );
        }
        if ( ! $this->permission_guard->can_access_bodega_panel()
            && ! $this->permission_guard->can_access_precios_panel()
            && ! $this->permission_guard->can_access_jefatura_panel() ) {
            return new WP_Error( 'rest_forbidden', 'No tienes permiso para revisar anomalías.', array( 'status' => 403 ) );
        }
        return true;
    }

    public function sugerir_precios( WP_REST_Request $request ) {
        $costo = (float) $request->get_param( 'costo' );
        if ( $costo <= 0 ) {
            return new WP_Error( 'datos_invalidos', 'El costo debe ser mayor que cero.', array( 'status' => 400 ) );
        }

        $margins = array();
        foreach ( array( 'detal', 'mayor', 'gran_mayor' ) as $tier ) {
            $val = $request->get_param( 'margen_' . $tier );
            if ( is_numeric( $val ) ) {
                $margins[ $tier ] = (float) $val;
            }
        }

        return rest_ensure_response( $this->price_service->suggest( $costo, $margins ) );
    }

    public function detectar_anomalias( WP_REST_Request $request ) {
        $lote_id = intval( $request->get_param( 'id' ) );
        if ( $lote_id <= 0 ) {
            return new WP_Error( 'datos_invalidos', 'Lote inválido.', array( 'status' => 400 ) );
        }

        // Misma fuente que BodegaTrait::mm45_received_products_from_lote.
        $items = get_option( 'mm_lote_' . $lote_id . '_received_products', array() );
        if ( ! is_array( $items ) ) {
            $items = array();
        }

        return rest_ensure_response( $this->anomaly_detector->analyze( $items ) );
    }
}
