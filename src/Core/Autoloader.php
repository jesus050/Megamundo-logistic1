<?php
namespace MegaMundo\Logistica\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Autoloader {

    public static function register() {
        spl_autoload_register( function ( $class ) {
            $prefix = 'MegaMundo\\Logistica\\';
            $base_dir = plugin_dir_path( dirname( __DIR__ ) ) . 'src/';

            $len = strlen( $prefix );
            if ( strncmp( $prefix, $class, $len ) !== 0 ) {
                return;
            }

            $relative_class = substr( $class, $len );
            $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

            if ( file_exists( $file ) ) {
                require $file;
            }
        } );
    }
}
