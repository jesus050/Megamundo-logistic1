<?php
namespace MegaMundo\Logistica\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Container {

    private $services = array();
    private $instances = array();

    public function set( $id, $resolver ) {
        $this->services[ $id ] = $resolver;
        unset( $this->instances[ $id ] );
    }

    public function get( $id ) {
        if ( isset( $this->instances[ $id ] ) ) {
            return $this->instances[ $id ];
        }

        if ( isset( $this->services[ $id ] ) ) {
            $resolver = $this->services[ $id ];
            $this->instances[ $id ] = $resolver( $this );
            return $this->instances[ $id ];
        }

        throw new \Exception( "Servicio no registrado: " . $id );
    }

    public function has( $id ) {
        return isset( $this->services[ $id ] ) || isset( $this->instances[ $id ] );
    }
}
