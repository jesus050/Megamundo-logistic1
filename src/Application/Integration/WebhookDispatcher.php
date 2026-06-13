<?php
namespace MegaMundo\Logistica\Application\Integration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Dispara webhooks salientes cuando un lote cambia de estado, para integrar
 * MegaMundo con sistemas externos (Slack, n8n, un ERP, Mekano, etc.) sin
 * acoplar el plugin a ninguno en particular.
 *
 * La URL de destino se configura en la opción mm_webhook_url. El envío es
 * best-effort y no bloqueante (timeout corto, errores solo a log): un webhook
 * caído nunca debe frenar el flujo de bodega.
 */
class WebhookDispatcher {

    const OPTION_URL    = 'mm_webhook_url';
    const OPTION_SECRET = 'mm_webhook_secret';

    /** Estados de lote cuya transición notificamos. Mapea estado -> evento. */
    const EVENTS = array(
        'mm_p_precios'    => 'lote.enviado_a_precios',
        'mm_p_aprobacion' => 'lote.enviado_a_aprobacion',
        'mm_cargado'      => 'lote.aprobado',
    );

    private $url;
    private $secret;

    public function __construct( $url = null, $secret = null ) {
        $this->url = ( null !== $url ) ? $url : (string) get_option( self::OPTION_URL, '' );
        $this->secret = ( null !== $secret ) ? $secret : (string) get_option( self::OPTION_SECRET, '' );
    }

    public function is_configured() {
        return '' !== trim( (string) $this->url );
    }

    public function event_for_status( $status ) {
        return self::EVENTS[ $status ] ?? '';
    }

    /**
     * Construye el cuerpo del webhook. Lógica pura (testeable sin red).
     */
    public function build_payload( $event, $lote_id, array $extra = array() ) {
        return array_merge( array(
            'event'     => (string) $event,
            'lote_id'   => intval( $lote_id ),
            'site'      => function_exists( 'home_url' ) ? home_url() : '',
            'timestamp' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : date( 'Y-m-d H:i:s' ),
        ), $extra );
    }

    /**
     * Firma HMAC-SHA256 del cuerpo, para que el receptor verifique el origen.
     * Devuelve '' si no hay secreto configurado.
     */
    public function sign( $body ) {
        if ( '' === trim( (string) $this->secret ) ) {
            return '';
        }
        return hash_hmac( 'sha256', (string) $body, $this->secret );
    }

    /**
     * Envía el webhook. No bloqueante; los fallos van al log, nunca al usuario.
     */
    public function dispatch( $event, $lote_id, array $extra = array() ) {
        if ( ! $this->is_configured() || '' === (string) $event ) {
            return false;
        }

        $payload = $this->build_payload( $event, $lote_id, $extra );
        $body = wp_json_encode( $payload );

        $headers = array( 'Content-Type' => 'application/json' );
        $signature = $this->sign( $body );
        if ( '' !== $signature ) {
            $headers['X-MegaMundo-Signature'] = $signature;
        }

        $response = wp_remote_post( $this->url, array(
            'timeout'  => 5,
            'blocking' => false,
            'headers'  => $headers,
            'body'     => $body,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( '[MegaMundo] Webhook ' . $event . ' falló: ' . $response->get_error_message() );
            return false;
        }

        return true;
    }

    /**
     * Conecta el dispatcher a la transición de estado del lote.
     */
    public function hook() {
        add_action( 'transition_post_status', array( $this, 'on_transition' ), 20, 3 );
    }

    public function on_transition( $new_status, $old_status, $post ) {
        if ( ! isset( $post->post_type ) || 'lotes_ingreso' !== $post->post_type ) {
            return;
        }
        if ( $old_status === $new_status ) {
            return;
        }
        $event = $this->event_for_status( $new_status );
        if ( '' === $event ) {
            return;
        }
        $this->dispatch( $event, $post->ID, array(
            'title'      => isset( $post->post_title ) ? $post->post_title : '',
            'old_status' => (string) $old_status,
            'new_status' => (string) $new_status,
        ) );
    }
}
