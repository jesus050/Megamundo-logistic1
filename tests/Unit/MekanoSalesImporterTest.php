<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Application\Inventory\MekanoSalesImporter;
use PHPUnit\Framework\TestCase;

class MekanoSalesImporterTest extends TestCase {

    private $imp;

    protected function setUp(): void {
        $this->imp = new MekanoSalesImporter();
    }

    public function test_detecta_separador_punto_y_coma() {
        $this->assertSame( ';', $this->imp->detect_delimiter( "A;B;C\n1;2;3" ) );
        $this->assertSame( ',', $this->imp->detect_delimiter( "A,B,C\n1,2,3" ) );
    }

    public function test_mapea_columnas_reales_de_mekano() {
        $headers = array( 'TIPO','PREFIJO','NUMERO','FECHA','CANTIDAD','BRUTO','DESCUENTO','DEVOLUCION','GRAVADO','NETO','REFERENCIA','USUARIO','NOMBRE VENDEDOR','NOMBRE REFERENCIA' );
        $m = $this->imp->suggest_mapping( $headers );
        $this->assertSame( 10, $m['sku'] );        // REFERENCIA
        $this->assertSame( 13, $m['nombre'] );     // NOMBRE REFERENCIA
        $this->assertSame( 4, $m['unidades'] );    // CANTIDAD
        $this->assertSame( 3, $m['fecha'] );       // FECHA
        $this->assertSame( 9, $m['valor'] );       // NETO
    }

    public function test_fecha_serial_de_excel() {
        $this->assertSame( '2026-05-24', $this->imp->to_date( '46166' ) );
    }

    public function test_fecha_formatos_comunes() {
        $this->assertSame( '2026-05-24', $this->imp->to_date( '24/05/2026' ) );
        $this->assertSame( '2026-05-24', $this->imp->to_date( '2026-05-24' ) );
        $this->assertNull( $this->imp->to_date( 'no es fecha' ) );
    }

    public function test_decimal_formato_colombiano() {
        $this->assertSame( 1234.56, $this->imp->to_decimal( '1.234,56' ) );
        $this->assertSame( 1234.56, $this->imp->to_decimal( '1,234.56' ) );
        $this->assertSame( 2500.0, $this->imp->to_decimal( '2500' ) );
    }

    public function test_agrega_por_sku_y_fecha() {
        // Mismo SKU vendido 2 veces el mismo día -> una sola fila, unidades sumadas.
        $mapping = array( 'sku' => 0, 'nombre' => 1, 'unidades' => 2, 'fecha' => 3, 'valor' => 4 );
        $rows = array(
            array( '12458', 'LIMA DESGASTE', '2', '2026-05-24', '5000' ),
            array( '12458', 'LIMA DESGASTE', '1', '2026-05-24', '2500' ),
            array( '25153H', 'PELOTA', '42', '2026-05-24', '84000' ),
        );
        $r = $this->imp->build_preview( $rows, $mapping );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 2, $r['summary']['skus_distintos'] );
        $this->assertSame( 2, $r['summary']['registros'] ); // 12458 colapsado en 1
        $lima = array_values( array_filter( $r['dias'], fn( $d ) => '12458' === $d['sku'] ) )[0];
        $this->assertSame( 3, $lima['unidades'] );           // 2 + 1
        $this->assertSame( 7500.0, $lima['valor_neto'] );    // 5000 + 2500
    }

    public function test_avisa_fechas_ya_importadas() {
        $mapping = array( 'sku' => 0, 'unidades' => 1, 'fecha' => 2 );
        $rows = array( array( 'A1', '3', '2026-05-24' ) );
        $r = $this->imp->build_preview( $rows, $mapping, array( '2026-05-24' ) );
        $this->assertSame( array( '2026-05-24' ), $r['summary']['fechas_ya_importadas'] );
    }

    public function test_cuenta_lineas_con_error() {
        $mapping = array( 'sku' => 0, 'unidades' => 1, 'fecha' => 2 );
        $rows = array(
            array( '', '3', '2026-05-24' ),          // sku vacío
            array( 'A1', '3', 'fecha mala' ),        // fecha inválida
            array( 'A2', '5', '2026-05-24' ),        // ok
        );
        $r = $this->imp->build_preview( $rows, $mapping );
        $this->assertSame( 2, $r['summary']['con_error'] );
        $this->assertSame( 1, $r['summary']['registros'] );
    }

    public function test_falta_campo_obligatorio() {
        $r = $this->imp->build_preview( array(), array( 'sku' => 0 ) ); // falta unidades y fecha
        $this->assertFalse( $r['ok'] );
        $this->assertStringContainsString( 'obligatorio', $r['message'] );
    }
}
