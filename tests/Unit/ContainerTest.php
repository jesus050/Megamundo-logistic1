<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Core\Container;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase {

    public function test_resuelve_servicio_registrado() {
        $container = new Container();
        $container->set( 'saludo', function() {
            return 'hola';
        } );

        $this->assertSame( 'hola', $container->get( 'saludo' ) );
    }

    public function test_cachea_instancias_como_singleton() {
        $container = new Container();
        $llamadas = 0;
        $container->set( 'contador', function() use ( &$llamadas ) {
            $llamadas++;
            return new \stdClass();
        } );

        $primera = $container->get( 'contador' );
        $segunda = $container->get( 'contador' );

        $this->assertSame( $primera, $segunda );
        $this->assertSame( 1, $llamadas );
    }

    public function test_resolver_recibe_el_container() {
        $container = new Container();
        $container->set( 'dep', function() {
            return 'dependencia';
        } );
        $container->set( 'svc', function( $c ) {
            return 'usa-' . $c->get( 'dep' );
        } );

        $this->assertSame( 'usa-dependencia', $container->get( 'svc' ) );
    }

    public function test_lanza_excepcion_para_servicio_no_registrado() {
        $container = new Container();

        $this->expectException( \Exception::class );
        $container->get( \stdClass::class );
    }

    public function test_has_refleja_solo_servicios_registrados() {
        $container = new Container();
        $container->set( 'registrado', function() {
            return true;
        } );

        $this->assertTrue( $container->has( 'registrado' ) );
        $this->assertFalse( $container->has( \stdClass::class ) );
    }
}
