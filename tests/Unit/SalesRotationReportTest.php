<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Application\Inventory\SalesRotationReport;
use PHPUnit\Framework\TestCase;

class SalesRotationReportTest extends TestCase {

    private $report;
    private $cfg;

    protected function setUp(): void {
        $this->report = new SalesRotationReport();
        $this->cfg = array( 'hoy' => '2026-06-14', 'umbral_no_rota' => 90, 'umbral_lento' => 45 );
    }

    public function test_clasifica_por_dias_sin_venta() {
        $r = $this->report->build( array(
            array( 'sku' => 'VIVO', 'ultima_venta' => '2026-06-10' ),   // 4 días -> ok
            array( 'sku' => 'LENTO', 'ultima_venta' => '2026-04-20' ),  // 55 días -> lento
            array( 'sku' => 'MUERTO', 'ultima_venta' => '2026-01-01' ), // 164 días -> no_rota
        ), $this->cfg );

        $by = array();
        foreach ( $r['items'] as $it ) { $by[ $it['sku'] ] = $it['estado']; }
        $this->assertSame( SalesRotationReport::OK, $by['VIVO'] );
        $this->assertSame( SalesRotationReport::LENTO, $by['LENTO'] );
        $this->assertSame( SalesRotationReport::NO_ROTA, $by['MUERTO'] );
    }

    public function test_resumen_cuenta_estados() {
        $r = $this->report->build( array(
            array( 'sku' => 'A', 'ultima_venta' => '2026-06-13' ),
            array( 'sku' => 'B', 'ultima_venta' => '2026-01-01' ),
            array( 'sku' => 'C', 'ultima_venta' => '2026-02-01' ),
        ), $this->cfg );
        $this->assertSame( 1, $r['summary'][ SalesRotationReport::OK ] );
        $this->assertSame( 2, $r['summary'][ SalesRotationReport::NO_ROTA ] );
        $this->assertSame( 3, $r['summary']['total'] );
    }

    public function test_ordena_mas_dormido_primero() {
        $r = $this->report->build( array(
            array( 'sku' => 'RECIENTE', 'ultima_venta' => '2026-06-10' ),
            array( 'sku' => 'VIEJO', 'ultima_venta' => '2025-06-10' ),
            array( 'sku' => 'MEDIO', 'ultima_venta' => '2026-03-10' ),
        ), $this->cfg );
        $this->assertSame( 'VIEJO', $r['items'][0]['sku'] );
        $this->assertSame( 'MEDIO', $r['items'][1]['sku'] );
        $this->assertSame( 'RECIENTE', $r['items'][2]['sku'] );
    }

    public function test_dias_sin_venta_calculado() {
        $r = $this->report->build( array( array( 'sku' => 'X', 'ultima_venta' => '2026-05-15' ) ), $this->cfg );
        $this->assertSame( 30, $r['items'][0]['dias_sin_venta'] );
    }

    public function test_sin_fecha_se_trata_como_no_rota() {
        $r = $this->report->build( array( array( 'sku' => 'X', 'ultima_venta' => '' ) ), $this->cfg );
        $this->assertSame( SalesRotationReport::NO_ROTA, $r['items'][0]['estado'] );
        $this->assertNull( $r['items'][0]['dias_sin_venta'] );
    }
}
