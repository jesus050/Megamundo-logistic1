<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Application\Inventory\StockRotationReport;
use PHPUnit\Framework\TestCase;

class StockRotationReportTest extends TestCase {

    private $report;
    private $cfg;

    protected function setUp(): void {
        $this->report = new StockRotationReport();
        $this->cfg = array( 'hoy' => '2026-06-15', 'umbral_no_rota' => 90, 'umbral_lento' => 45 );
    }

    public function test_con_stock_y_sin_ventas_es_no_rota() {
        $r = $this->report->build(
            array( array( 'sku' => 'A', 'nombre' => 'X', 'stock' => 100 ) ),
            array(), // sin ventas
            $this->cfg
        );
        $this->assertSame( StockRotationReport::NO_ROTA, $r['items'][0]['estado'] );
        $this->assertNull( $r['items'][0]['dias_sin_venta'] );
        $this->assertSame( 100, $r['summary']['unidades_paradas'] );
    }

    public function test_con_venta_reciente_es_ok() {
        $r = $this->report->build(
            array( array( 'sku' => 'A', 'nombre' => 'X', 'stock' => 10 ) ),
            array( 'A' => '2026-06-10' ),
            $this->cfg
        );
        $this->assertSame( StockRotationReport::OK, $r['items'][0]['estado'] );
        $this->assertSame( 0, $r['summary']['unidades_paradas'] );
    }

    public function test_venta_vieja_es_no_rota() {
        $r = $this->report->build(
            array( array( 'sku' => 'A', 'nombre' => 'X', 'stock' => 50 ) ),
            array( 'A' => '2026-01-01' ),
            $this->cfg
        );
        $this->assertSame( StockRotationReport::NO_ROTA, $r['items'][0]['estado'] );
    }

    public function test_ignora_productos_sin_stock() {
        $r = $this->report->build(
            array(
                array( 'sku' => 'A', 'nombre' => 'X', 'stock' => 0 ),
                array( 'sku' => 'B', 'nombre' => 'Y', 'stock' => 5 ),
            ),
            array(),
            $this->cfg
        );
        $this->assertSame( 1, $r['summary']['total'] );
        $this->assertSame( 'B', $r['items'][0]['sku'] );
    }

    public function test_ordena_mas_unidades_paradas_primero() {
        $r = $this->report->build(
            array(
                array( 'sku' => 'POCO', 'nombre' => 'P', 'stock' => 5 ),
                array( 'sku' => 'MUCHO', 'nombre' => 'M', 'stock' => 500 ),
            ),
            array(), // ambos no rotan
            $this->cfg
        );
        $this->assertSame( 'MUCHO', $r['items'][0]['sku'] );
        $this->assertSame( 'POCO', $r['items'][1]['sku'] );
    }

    public function test_lento_entre_umbrales() {
        $r = $this->report->build(
            array( array( 'sku' => 'A', 'nombre' => 'X', 'stock' => 10 ) ),
            array( 'A' => '2026-04-20' ), // ~56 días al 2026-06-15
            $this->cfg
        );
        $this->assertSame( StockRotationReport::LENTO, $r['items'][0]['estado'] );
    }
}
