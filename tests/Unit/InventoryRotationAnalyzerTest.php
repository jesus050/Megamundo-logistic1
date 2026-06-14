<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Application\Inventory\InventoryRotationAnalyzer;
use PHPUnit\Framework\TestCase;

class InventoryRotationAnalyzerTest extends TestCase {

    private $analyzer;
    private $cfg;

    protected function setUp(): void {
        $this->analyzer = new InventoryRotationAnalyzer();
        // Fecha fija para que los días sin venta sean deterministas.
        $this->cfg = array( 'hoy' => '2026-06-14', 'dias_sin_venta_umbral' => 90, 'meses_inventario_umbral' => 6 );
    }

    private function one( $item ) {
        $r = $this->analyzer->analyze( array( $item ), $this->cfg );
        return $r['items'][0];
    }

    public function test_muerto_cumple_ambas_condiciones() {
        // Última venta hace ~1 año y 12 meses de inventario (60 stock, 5/mes).
        $r = $this->one( array(
            'sku' => 'A-1', 'stock' => 60, 'costo' => 100,
            'ultima_venta' => '2025-06-14', 'vendido_periodo' => 5, 'periodo_dias' => 30,
        ) );
        $this->assertSame( InventoryRotationAnalyzer::MUERTO, $r['estado'] );
        $this->assertSame( 6000.0, $r['valor'] );
        $this->assertSame( 12.0, $r['meses_inventario'] );
    }

    public function test_lento_solo_una_condicion() {
        // Sin venderse hace mucho (tiempo sí) pero rota rápido el stock (stock no).
        $r = $this->one( array(
            'sku' => 'B-1', 'stock' => 2, 'costo' => 50,
            'ultima_venta' => '2025-01-01', 'vendido_periodo' => 30, 'periodo_dias' => 30,
        ) );
        $this->assertSame( InventoryRotationAnalyzer::LENTO, $r['estado'] );
    }

    public function test_ok_rota_bien() {
        $r = $this->one( array(
            'sku' => 'C-1', 'stock' => 10, 'costo' => 20,
            'ultima_venta' => '2026-06-10', 'vendido_periodo' => 40, 'periodo_dias' => 30,
        ) );
        $this->assertSame( InventoryRotationAnalyzer::OK, $r['estado'] );
    }

    public function test_stock_sin_ventas_se_marca_estancado() {
        // Cero ventas en el periodo pero hay stock => meses altos => sobrestock.
        $r = $this->one( array(
            'sku' => 'D-1', 'stock' => 25, 'costo' => 10,
            'ultima_venta' => null, 'vendido_periodo' => 0, 'periodo_dias' => 30,
        ) );
        $this->assertSame( InventoryRotationAnalyzer::LENTO, $r['estado'] );
        $this->assertSame( 999.0, $r['meses_inventario'] );
    }

    public function test_sin_datos_cuando_no_hay_fecha_ni_ventas() {
        $r = $this->one( array(
            'sku' => 'E-1', 'stock' => 5, 'costo' => 10,
            'ultima_venta' => null, 'vendido_periodo' => null, 'periodo_dias' => null,
        ) );
        $this->assertSame( InventoryRotationAnalyzer::SIN_DATOS, $r['estado'] );
        $this->assertNull( $r['dias_sin_venta'] );
        $this->assertNull( $r['meses_inventario'] );
    }

    public function test_dias_sin_venta_se_calcula_bien() {
        $r = $this->one( array( 'sku' => 'F-1', 'stock' => 1, 'costo' => 1, 'ultima_venta' => '2026-05-15' ) );
        $this->assertSame( 30, $r['dias_sin_venta'] ); // 15 may -> 14 jun = 30 días
    }

    public function test_resumen_suma_valor_inmovilizado() {
        $r = $this->analyzer->analyze( array(
            array( 'sku' => 'M', 'stock' => 10, 'costo' => 100, 'ultima_venta' => '2025-01-01', 'vendido_periodo' => 0, 'periodo_dias' => 30 ), // muerto, valor 1000
            array( 'sku' => 'OK', 'stock' => 5, 'costo' => 100, 'ultima_venta' => '2026-06-12', 'vendido_periodo' => 50, 'periodo_dias' => 30 ), // ok, valor 500
        ), $this->cfg );
        $this->assertSame( 1, $r['summary'][ InventoryRotationAnalyzer::MUERTO ] );
        $this->assertSame( 1, $r['summary'][ InventoryRotationAnalyzer::OK ] );
        $this->assertSame( 1000.0, $r['summary']['valor_inmovilizado'] ); // solo el muerto
        $this->assertSame( 1500.0, $r['summary']['valor_total'] );
    }

    public function test_ordena_peor_rotacion_y_mayor_valor_primero() {
        $r = $this->analyzer->analyze( array(
            array( 'sku' => 'OK',  'stock' => 1,  'costo' => 1,    'ultima_venta' => '2026-06-12', 'vendido_periodo' => 50, 'periodo_dias' => 30 ),
            array( 'sku' => 'MEN', 'stock' => 10, 'costo' => 100,  'ultima_venta' => '2024-01-01', 'vendido_periodo' => 0,  'periodo_dias' => 30 ),
            array( 'sku' => 'MAY', 'stock' => 50, 'costo' => 100,  'ultima_venta' => '2024-01-01', 'vendido_periodo' => 0,  'periodo_dias' => 30 ),
        ), $this->cfg );
        // Primero los muertos, y entre ellos el de mayor valor inmovilizado.
        $this->assertSame( 'MAY', $r['items'][0]['sku'] );
        $this->assertSame( 'MEN', $r['items'][1]['sku'] );
        $this->assertSame( 'OK',  $r['items'][2]['sku'] );
    }
}
