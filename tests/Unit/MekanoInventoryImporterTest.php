<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Application\Inventory\MekanoInventoryImporter;
use PHPUnit\Framework\TestCase;

class MekanoInventoryImporterTest extends TestCase {

    private $imp;

    protected function setUp(): void {
        $this->imp = new MekanoInventoryImporter();
    }

    public function test_mapea_columnas_de_existencias() {
        $headers = array( 'REFERENCIA', '', 'NOMBRE REFERENCIA', 'VIENE', 'ENTRADAS', 'SALIDAS', 'EXISTENCIA' );
        $sample = array(
            array( '', '007007', 'MOÑA CABELLO', '2', '0', '2', '0' ),
            array( '', '1120442', 'GANCHO 4000', '268', '0', '6', '262' ),
        );
        $m = $this->imp->suggest_mapping( $headers, $sample );
        // El SKU real está en la columna 1 (la de encabezado vacío), no en la 0.
        $this->assertSame( 1, $m['sku'] );
        $this->assertSame( 2, $m['nombre'] );
        $this->assertSame( 6, $m['stock'] );
    }

    public function test_preview_toma_existencia_y_cuenta_stock() {
        $headers = array( 'REFERENCIA', '', 'NOMBRE REFERENCIA', 'VIENE', 'ENTRADAS', 'SALIDAS', 'EXISTENCIA' );
        $rows = array(
            array( '', '007007', 'MOÑA CABELLO', '2', '0', '2', '0' ),
            array( '', '1120442', 'GANCHO 4000', '268', '0', '6', '262' ),
            array( '', '145801', 'METRO 2MT', '0', '36', '5', '31' ),
        );
        $m = $this->imp->suggest_mapping( $headers, $rows );
        $r = $this->imp->build_preview( $rows, $m );
        $this->assertTrue( $r['ok'] );
        $this->assertSame( 3, $r['summary']['skus'] );
        $this->assertSame( 2, $r['summary']['con_stock'] ); // 262 y 31 (007007 tiene 0)
        $gancho = array_values( array_filter( $r['rows'], fn( $x ) => '1120442' === $x['sku'] ) )[0];
        $this->assertSame( 262, $gancho['stock'] );
        $this->assertSame( 'GANCHO 4000', $gancho['nombre'] );
    }

    public function test_existencia_negativa_se_trata_como_cero() {
        $this->assertSame( 0, $this->imp->to_int( '-1' ) );
        $this->assertSame( 262, $this->imp->to_int( '262' ) );
        $this->assertSame( 1234, $this->imp->to_int( '1.234' ) );
    }

    public function test_detecta_separador() {
        $this->assertSame( ';', $this->imp->detect_delimiter( "A;B;C\n1;2;3" ) );
    }

    public function test_falta_campo_obligatorio() {
        $r = $this->imp->build_preview( array(), array( 'nombre' => 0 ) );
        $this->assertFalse( $r['ok'] );
    }
}
