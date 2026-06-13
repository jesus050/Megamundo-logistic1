<?php
/**
 * Bootstrap de tests unitarios: shims mínimos de WordPress para poder
 * ejecutar la lógica pura del plugin sin cargar WordPress completo.
 */

require __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
}

// Almacén de opciones controlable desde los tests.
$GLOBALS['mm_test_options'] = array();

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        return array_key_exists( $name, $GLOBALS['mm_test_options'] )
            ? $GLOBALS['mm_test_options'][ $name ]
            : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value ) {
        $GLOBALS['mm_test_options'][ $name ] = $value;
        return true;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        $str = (string) $str;
        $str = strip_tags( $str );
        $str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
        return trim( $str );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        $str = (string) $str;
        $str = strip_tags( $str );
        return trim( $str );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type ) {
        return 'mysql' === $type ? date( 'Y-m-d H:i:s' ) : time();
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) {
        return 'https://example.test' . $path;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ) {
        return json_encode( $data );
    }
}
