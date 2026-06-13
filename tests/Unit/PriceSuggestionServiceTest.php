<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Application\Pricing\PriceSuggestionService;
use PHPUnit\Framework\TestCase;

class PriceSuggestionServiceTest extends TestCase {

    private $service;

    protected function setUp(): void {
        $this->service = new PriceSuggestionService();
    }

    public function test_precio_para_margen_despeja_correctamente() {
        // costo 600, margen 40% => 600 / 0.6 = 1000 (múltiplo exacto de 50).
        $this->assertSame( 1000.0, $this->service->price_for_margin( 600, 40 ) );
    }

    public function test_precio_redondea_a_multiplo_de_50() {
        // 1000 / 0.6 = 1666.67 -> redondeado a 1650 (múltiplo de 50 más cercano).
        $this->assertSame( 1650.0, $this->service->price_for_margin( 1000, 40 ) );
    }

    public function test_costo_cero_devuelve_cero() {
        $this->assertSame( 0.0, $this->service->price_for_margin( 0, 40 ) );
    }

    public function test_margen_imposible_se_acota() {
        // Margen >= 100% no debe dar precio infinito ni división por cero.
        $result = $this->service->price_for_margin( 500, 150 );
        $this->assertIsFloat( $result );
        $this->assertGreaterThan( 500, $result );
    }

    public function test_sugerencia_respeta_detal_mayor_gran_mayor() {
        $r = $this->service->suggest( 1000 );
        $this->assertGreaterThanOrEqual( $r['mayor'], $r['detal'] );
        $this->assertGreaterThanOrEqual( $r['gran_mayor'], $r['mayor'] );
    }

    public function test_sugerencia_corrige_margenes_desordenados() {
        // Aunque el usuario invierta los márgenes, el orden de precios se mantiene.
        $r = $this->service->suggest( 1000, array(
            'detal'      => 10,
            'mayor'      => 30,
            'gran_mayor' => 50,
        ) );
        $this->assertGreaterThanOrEqual( $r['mayor'], $r['detal'] );
        $this->assertGreaterThanOrEqual( $r['gran_mayor'], $r['mayor'] );
    }

    public function test_sugerencia_reporta_margen_real_resultante() {
        $r = $this->service->suggest( 600 );
        $this->assertArrayHasKey( 'margenes', $r );
        // detal por defecto 40%, costo 600 => precio 1000 => margen real 40.0.
        $this->assertSame( 40.0, $r['margenes']['detal'] );
    }

    public function test_margenes_no_numericos_caen_a_defaults() {
        $r = $this->service->suggest( 600, array( 'detal' => 'abc' ) );
        $this->assertSame( 1000.0, $r['detal'] ); // usó el default 40%
    }
}
