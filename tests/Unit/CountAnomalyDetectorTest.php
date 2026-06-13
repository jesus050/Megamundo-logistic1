<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Application\Quality\CountAnomalyDetector;
use PHPUnit\Framework\TestCase;

class CountAnomalyDetectorTest extends TestCase {

    private $detector;

    protected function setUp(): void {
        $this->detector = new CountAnomalyDetector();
    }

    public function test_conteo_exacto_no_genera_anomalias() {
        $r = $this->detector->analyze( array(
            array( 'codigo' => 'A-1', 'nombre' => 'Uno', 'esperado' => 10, 'recibido' => 10 ),
        ) );
        $this->assertSame( array(), $r['anomalies'] );
        $this->assertSame( 1, $r['summary'][ CountAnomalyDetector::OK ] );
        $this->assertFalse( $r['has_blocking'] );
    }

    public function test_detecta_faltante_como_bloqueante() {
        $r = $this->detector->analyze( array(
            array( 'codigo' => 'A-1', 'esperado' => 10, 'recibido' => 7 ),
        ) );
        $this->assertCount( 1, $r['anomalies'] );
        $this->assertSame( CountAnomalyDetector::FALTANTE, $r['anomalies'][0]['tipo'] );
        $this->assertSame( -3, $r['anomalies'][0]['diferencia'] );
        $this->assertTrue( $r['has_blocking'] );
    }

    public function test_detecta_exceso_no_bloqueante() {
        $r = $this->detector->analyze( array(
            array( 'codigo' => 'A-1', 'esperado' => 10, 'recibido' => 13 ),
        ) );
        $this->assertSame( CountAnomalyDetector::EXCESO, $r['anomalies'][0]['tipo'] );
        $this->assertSame( 3, $r['anomalies'][0]['diferencia'] );
        $this->assertFalse( $r['has_blocking'] );
    }

    public function test_producto_no_esperado_es_alta_severidad() {
        $r = $this->detector->analyze( array(
            array( 'codigo' => 'X-9', 'esperado' => 0, 'recibido' => 5 ),
        ) );
        $this->assertSame( CountAnomalyDetector::NO_ESPERADO, $r['anomalies'][0]['tipo'] );
        $this->assertSame( 'alta', $r['anomalies'][0]['severidad'] );
    }

    public function test_esperado_sin_recibir_es_bloqueante_y_alto() {
        $r = $this->detector->analyze( array(
            array( 'codigo' => 'A-1', 'esperado' => 8, 'recibido' => 0 ),
        ) );
        $this->assertSame( CountAnomalyDetector::SIN_RECIBIR, $r['anomalies'][0]['tipo'] );
        $this->assertSame( 'alta', $r['anomalies'][0]['severidad'] );
        $this->assertTrue( $r['has_blocking'] );
    }

    public function test_severidad_por_desviacion() {
        // 10% de desviación => media; 30% => alta.
        $r = $this->detector->analyze( array(
            array( 'codigo' => 'A-1', 'esperado' => 100, 'recibido' => 110 ),
            array( 'codigo' => 'A-2', 'esperado' => 100, 'recibido' => 130 ),
        ) );
        $this->assertSame( 'media', $r['anomalies'][0]['severidad'] );
        $this->assertSame( 10.0, $r['anomalies'][0]['desviacion'] );
        $this->assertSame( 'alta', $r['anomalies'][1]['severidad'] );
        $this->assertSame( 30.0, $r['anomalies'][1]['desviacion'] );
    }

    public function test_resumen_cuenta_cada_tipo() {
        $r = $this->detector->analyze( array(
            array( 'codigo' => 'OK', 'esperado' => 5, 'recibido' => 5 ),
            array( 'codigo' => 'FALT', 'esperado' => 5, 'recibido' => 2 ),
            array( 'codigo' => 'EXC', 'esperado' => 5, 'recibido' => 9 ),
            array( 'codigo' => 'NUEVO', 'esperado' => 0, 'recibido' => 3 ),
        ) );
        $this->assertSame( 1, $r['summary'][ CountAnomalyDetector::OK ] );
        $this->assertSame( 1, $r['summary'][ CountAnomalyDetector::FALTANTE ] );
        $this->assertSame( 1, $r['summary'][ CountAnomalyDetector::EXCESO ] );
        $this->assertSame( 1, $r['summary'][ CountAnomalyDetector::NO_ESPERADO ] );
        $this->assertSame( 4, $r['summary']['total'] );
    }

    public function test_ignora_entradas_no_array() {
        $r = $this->detector->analyze( array( 'basura', null, 42 ) );
        $this->assertSame( array(), $r['anomalies'] );
        $this->assertSame( 3, $r['summary']['total'] );
    }
}
