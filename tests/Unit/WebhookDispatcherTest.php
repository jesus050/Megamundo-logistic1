<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Application\Integration\WebhookDispatcher;
use PHPUnit\Framework\TestCase;

class WebhookDispatcherTest extends TestCase {

    public function test_no_configurado_sin_url() {
        $d = new WebhookDispatcher( '', '' );
        $this->assertFalse( $d->is_configured() );
    }

    public function test_configurado_con_url() {
        $d = new WebhookDispatcher( 'https://hooks.example.com/abc', '' );
        $this->assertTrue( $d->is_configured() );
    }

    public function test_mapea_estados_a_eventos() {
        $d = new WebhookDispatcher( 'https://x', '' );
        $this->assertSame( 'lote.enviado_a_precios', $d->event_for_status( 'mm_p_precios' ) );
        $this->assertSame( 'lote.aprobado', $d->event_for_status( 'mm_cargado' ) );
        $this->assertSame( '', $d->event_for_status( 'draft' ) );
    }

    public function test_payload_incluye_evento_lote_y_extra() {
        $d = new WebhookDispatcher( 'https://x', '' );
        $payload = $d->build_payload( 'lote.aprobado', 42, array( 'title' => 'Lote junio' ) );

        $this->assertSame( 'lote.aprobado', $payload['event'] );
        $this->assertSame( 42, $payload['lote_id'] );
        $this->assertSame( 'Lote junio', $payload['title'] );
        $this->assertArrayHasKey( 'timestamp', $payload );
        $this->assertArrayHasKey( 'site', $payload );
    }

    public function test_firma_vacia_sin_secreto() {
        $d = new WebhookDispatcher( 'https://x', '' );
        $this->assertSame( '', $d->sign( '{"a":1}' ) );
    }

    public function test_firma_hmac_estable_con_secreto() {
        $d = new WebhookDispatcher( 'https://x', 'mi-secreto' );
        $body = '{"event":"lote.aprobado"}';
        $expected = hash_hmac( 'sha256', $body, 'mi-secreto' );
        $this->assertSame( $expected, $d->sign( $body ) );
        // Determinista: misma entrada, misma firma.
        $this->assertSame( $d->sign( $body ), $d->sign( $body ) );
    }

    public function test_dispatch_sin_configurar_no_hace_nada() {
        $d = new WebhookDispatcher( '', '' );
        $this->assertFalse( $d->dispatch( 'lote.aprobado', 1 ) );
    }
}
