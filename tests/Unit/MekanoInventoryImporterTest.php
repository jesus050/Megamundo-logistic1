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
        $sample  = array(
            array( '', '007007',  'MOÑA CABELLO', '2',   '0', '2', '0'   ),
            array( '', '1120442', 'GANCHO 4000',  '268', '0', '6', '262' ),
        );
        $m = $this->imp->suggest_mapping( $headers, $sample );
        // El SKU real está en la columna 1 (encabezado vacío), no en la 0.
        $this->assertSame( 1, $m['sku'] );
        $this->assertSame( 2, $m['nombre'] );
        $this->assertSame( 3, $m['viene'] );
        $this->assertSame( 4, $m['entradas'] );
        $this->assertSame( 5, $m['salidas'] );
        $this->assertSame( 6, $m['stock'] );
    }

    public function test_preview_captura_todas_las_columnas() {
        $headers = array( 'REFERENCIA', '', 'NOMBRE REFERENCIA', 'VIENE', 'ENTRADAS', 'SALIDAS', 'EXISTENCIA' );
        $rows    = array(
            array( '', '007007',  'MOÑA CABELLO', '2',   '0', '2', '0'   ),
            array( '', '1120442', 'GANCHO 4000',  '268', '0', '6', '262' ),
            array( '', '145801',  'METRO 2MT',    '0',   '36','5', '31'  ),
        );
        $m = $this->imp->suggest_mapping( $headers, $rows );
        $r = $this->imp->build_preview( $rows, $m );

        $this->assertTrue( $r['ok'] );
        $this->assertSame( 3, $r['summary']['skus'] );
        $this->assertSame( 2, $r['summary']['con_stock'] ); // 262 y 31; 007007 tiene existencia 0

        $gancho = array_values( array_filter( $r['rows'], function( $x ) { return '1120442' === $x['sku']; } ) )[0];
        $this->assertSame( 262, $gancho['stock'],    'Existencia (stock) debe ser 262' );
        $this->assertSame( 268, $gancho['viene'],    'Viene debe ser 268' );
        $this->assertSame( 0,   $gancho['entradas'], 'Entradas debe ser 0' );
        $this->assertSame( 6,   $gancho['salidas'],  'Salidas debe ser 6' );
        $this->assertSame( 'GANCHO 4000', $gancho['nombre'] );
    }

    /**
     * Casos de to_int con el formato colombiano de Mekano.
     * Los números vienen como "256,00" (coma = decimal) y
     * "1.256,00" (punto = miles, coma = decimal). NUNCA deben
     * multiplicarse × 100 (25600 sería incorrecto para 256 unidades).
     */
    public function test_to_int_formato_mekano() {
        // Formato colombiano: coma decimal
        $this->assertSame( 256,  $this->imp->to_int( '256,00'   ), '256,00 debe ser 256' );
        $this->assertSame( 256,  $this->imp->to_int( '$256,00'  ), '$256,00 debe ser 256' );
        $this->assertSame( 1256, $this->imp->to_int( '1.256,00' ), '1.256,00 debe ser 1256' );
        $this->assertSame( 1256, $this->imp->to_int( '1.256'    ), '1.256 (miles) debe ser 1256' );

        // Formato anglosajón
        $this->assertSame( 256,  $this->imp->to_int( '256.00'   ), '256.00 debe ser 256' );
        $this->assertSame( 1256, $this->imp->to_int( '1,256.00' ), '1,256.00 debe ser 1256' );

        // Sin separadores
        $this->assertSame( 262,  $this->imp->to_int( '262' ) );
        $this->assertSame( 0,    $this->imp->to_int( ''    ) );
        $this->assertSame( 0,    $this->imp->to_int( '-5'  ), 'Negativos → 0' );
    }

    public function test_detecta_separador() {
        $this->assertSame( ';', $this->imp->detect_delimiter( "A;B;C\n1;2;3" ) );
    }

    public function test_falta_campo_obligatorio() {
        $r = $this->imp->build_preview( array(), array( 'nombre' => 0 ) );
        $this->assertFalse( $r['ok'] );
    }
}
