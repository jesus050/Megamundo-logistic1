<?php
namespace MegaMundo\Logistica\Application\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Importa reportes de VENTAS de Mekano (CSV) y los agrega por SKU y fecha.
 * Lógica pura y testeable: parsea, mapea columnas, normaliza valores y produce
 * una VISTA PREVIA con errores y aviso de fechas ya importadas, ANTES de tocar
 * la base. El usuario exporta su .xls como CSV ("Guardar como -> CSV").
 *
 * Columnas reales del reporte (mapeo sugerido automáticamente):
 *   REFERENCIA -> sku · NOMBRE REFERENCIA -> nombre · CANTIDAD -> unidades
 *   FECHA -> fecha · NETO -> valor
 */
class MekanoSalesImporter {

    const FIELD_SYNONYMS = array(
        'sku'      => array( 'referencia', 'sku', 'codigo', 'cod', 'ref' ),
        'nombre'   => array( 'nombre referencia', 'nombre', 'descripcion', 'producto' ),
        'unidades' => array( 'cantidad', 'unidades', 'cant', 'qty' ),
        'fecha'    => array( 'fecha', 'fecha venta', 'fecha factura' ),
        'valor'    => array( 'neto', 'valor', 'total', 'bruto' ),
    );

    const REQUIRED = array( 'sku', 'unidades', 'fecha' );

    public function detect_delimiter( $content ) {
        $first = (string) strtok( (string) $content, "\n\r" );
        $counts = array(
            ';'  => substr_count( $first, ';' ),
            ','  => substr_count( $first, ',' ),
            "\t" => substr_count( $first, "\t" ),
            '|'  => substr_count( $first, '|' ),
        );
        arsort( $counts );
        $best = key( $counts );
        return $counts[ $best ] > 0 ? $best : ',';
    }

    public function parse_csv( $content, $delimiter = null ) {
        $content = preg_replace( "/^\xEF\xBB\xBF/", '', (string) $content );
        if ( null === $delimiter ) {
            $delimiter = $this->detect_delimiter( $content );
        }
        $rows = array();
        foreach ( preg_split( "/\r\n|\n|\r/", $content ) as $line ) {
            if ( '' === trim( $line ) ) { continue; }
            $rows[] = array_map( 'trim', str_getcsv( $line, $delimiter ) );
        }
        return $rows;
    }

    public function suggest_mapping( array $headers ) {
        $norm = array();
        foreach ( $headers as $i => $h ) {
            $norm[ $i ] = $this->normalize_header( $h );
        }
        $mapping = array();
        foreach ( self::FIELD_SYNONYMS as $field => $syns ) {
            foreach ( $syns as $syn ) {
                $syn_n = $this->normalize_header( $syn );
                foreach ( $norm as $i => $h ) {
                    if ( $h === $syn_n ) { $mapping[ $field ] = $i; break 2; }
                }
            }
            // segundo intento: coincidencia parcial
            if ( ! isset( $mapping[ $field ] ) ) {
                foreach ( $syns as $syn ) {
                    $syn_n = $this->normalize_header( $syn );
                    foreach ( $norm as $i => $h ) {
                        if ( '' !== $h && false !== strpos( $h, $syn_n ) ) { $mapping[ $field ] = $i; break 2; }
                    }
                }
            }
        }
        return $mapping;
    }

    private function normalize_header( $h ) {
        $h = strtolower( trim( (string) $h ) );
        $h = strtr( $h, array( 'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n' ) );
        return preg_replace( '/[^a-z0-9 ]/', '', $h );
    }

    /**
     * Agrega las filas por (sku, fecha) y devuelve la vista previa.
     *
     * @param array $data_rows      Filas SIN encabezado.
     * @param array $mapping        campo => índice de columna.
     * @param array $fechas_existentes Fechas (Y-m-d) ya importadas, para avisar.
     */
    public function build_preview( array $data_rows, array $mapping, array $fechas_existentes = array() ) {
        foreach ( self::REQUIRED as $req ) {
            if ( ! isset( $mapping[ $req ] ) ) {
                return array( 'ok' => false, 'message' => 'Falta mapear el campo obligatorio: ' . $req, 'dias' => array(), 'summary' => array() );
            }
        }

        $existentes = array_fill_keys( array_map( 'strval', $fechas_existentes ), true );
        $agg = array();            // clave "sku|fecha" => fila agregada
        $errores = 0;
        $lineas = 0;
        $fechas_en_archivo = array();

        foreach ( $data_rows as $cells ) {
            if ( ! is_array( $cells ) ) { continue; }
            $lineas++;

            $sku   = isset( $cells[ $mapping['sku'] ] ) ? trim( (string) $cells[ $mapping['sku'] ] ) : '';
            $fecha = isset( $cells[ $mapping['fecha'] ] ) ? $this->to_date( $cells[ $mapping['fecha'] ] ) : null;
            $unid  = isset( $cells[ $mapping['unidades'] ] ) ? $this->to_int( $cells[ $mapping['unidades'] ] ) : 0;
            $nombre = isset( $mapping['nombre'], $cells[ $mapping['nombre'] ] ) ? trim( (string) $cells[ $mapping['nombre'] ] ) : '';
            $valor  = isset( $mapping['valor'], $cells[ $mapping['valor'] ] ) ? (float) $this->to_decimal( $cells[ $mapping['valor'] ] ) : 0.0;

            if ( '' === $sku || null === $fecha ) {
                $errores++;
                continue;
            }

            $fechas_en_archivo[ $fecha ] = true;
            $key = $sku . '|' . $fecha;
            if ( ! isset( $agg[ $key ] ) ) {
                $agg[ $key ] = array( 'sku' => $sku, 'nombre' => $nombre, 'fecha' => $fecha, 'unidades' => 0, 'valor_neto' => 0.0 );
            }
            $agg[ $key ]['unidades']  += $unid;
            $agg[ $key ]['valor_neto'] += $valor;
            if ( '' === $agg[ $key ]['nombre'] && '' !== $nombre ) {
                $agg[ $key ]['nombre'] = $nombre;
            }
        }

        $dias = array_values( $agg );
        $fechas = array_keys( $fechas_en_archivo );
        sort( $fechas );
        $ya_importadas = array_values( array_filter( $fechas, function( $f ) use ( $existentes ) {
            return isset( $existentes[ $f ] );
        } ) );

        $unidades_total = array_sum( array_column( $dias, 'unidades' ) );
        $valor_total    = array_sum( array_column( $dias, 'valor_neto' ) );

        return array(
            'ok'      => true,
            'message' => '',
            'dias'    => $dias,
            'summary' => array(
                'lineas'             => $lineas,
                'con_error'          => $errores,
                'skus_distintos'     => count( array_unique( array_column( $dias, 'sku' ) ) ),
                'registros'          => count( $dias ),
                'fechas'             => $fechas,
                'fechas_ya_importadas' => $ya_importadas,
                'unidades_total'     => (int) $unidades_total,
                'valor_total'        => round( (float) $valor_total, 2 ),
            ),
        );
    }

    public function to_int( $value ) {
        $value = preg_replace( '/[^0-9\-]/', '', (string) $value );
        return '' === $value || '-' === $value ? 0 : (int) $value;
    }

    public function to_decimal( $value ) {
        $s = trim( (string) $value );
        if ( '' === $s ) { return 0.0; }
        $s = preg_replace( '/[^0-9.,\-]/', '', $s );
        if ( '' === preg_replace( '/[^0-9]/', '', $s ) ) { return 0.0; }
        $ld = strrpos( $s, '.' ); $lc = strrpos( $s, ',' );
        if ( false !== $ld && false !== $lc ) {
            $dec = $ld > $lc ? '.' : ',';
        } elseif ( false !== $lc ) {
            $dec = ',';
        } else {
            $dec = '.';
        }
        $thou = ( '.' === $dec ) ? ',' : '.';
        $s = str_replace( $thou, '', $s );
        $s = str_replace( $dec, '.', $s );
        return is_numeric( $s ) ? (float) $s : 0.0;
    }

    /**
     * Normaliza fechas a Y-m-d. Acepta d/m/Y, d-m-Y, Y-m-d y el SERIAL de Excel
     * (p. ej. 46166 = 2026-05-24), por si el CSV conserva el número crudo.
     */
    public function to_date( $value ) {
        $s = trim( (string) $value );
        if ( '' === $s ) { return null; }

        // Serial de Excel (entero en rango razonable de fechas modernas).
        if ( preg_match( '/^\d{5}$/', $s ) ) {
            $serial = (int) $s;
            if ( $serial >= 20000 && $serial <= 80000 ) {
                return gmdate( 'Y-m-d', ( $serial - 25569 ) * 86400 );
            }
        }

        $s = str_replace( array( '.', ' ' ), array( '/', '' ), $s );
        if ( preg_match( '#^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$#', $s, $m ) ) {
            $y = $m[1]; $mo = $m[2]; $d = $m[3];
        } elseif ( preg_match( '#^(\d{1,2})[-/](\d{1,2})[-/](\d{4})$#', $s, $m ) ) {
            $d = $m[1]; $mo = $m[2]; $y = $m[3];
        } else {
            return null;
        }
        if ( ! checkdate( (int) $mo, (int) $d, (int) $y ) ) {
            return null;
        }
        return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
    }
}
