<?php
namespace MegaMundo\Logistica\Application\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Importa el reporte de EXISTENCIAS de Mekano (CSV) para conocer el stock
 * actual por referencia. Lógica pura y testeable.
 *
 * Columnas reales del reporte: REFERENCIA (a veces el valor cae en la columna
 * contigua, con encabezado vacío), NOMBRE REFERENCIA, VIENE, ENTRADAS,
 * SALIDAS, EXISTENCIA. Solo nos interesan sku, nombre y existencia (stock).
 */
class MekanoInventoryImporter {

    const FIELD_SYNONYMS = array(
        'sku'    => array( 'referencia', 'sku', 'codigo', 'cod', 'ref' ),
        'nombre' => array( 'nombre referencia', 'nombre', 'descripcion', 'producto' ),
        'stock'  => array( 'existencia', 'existencias', 'saldo', 'stock', 'disponible' ),
    );

    const REQUIRED = array( 'sku', 'stock' );

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

    private function normalize_header( $h ) {
        $h = strtolower( trim( (string) $h ) );
        $h = strtr( $h, array( 'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n' ) );
        return preg_replace( '/[^a-z0-9 ]/', '', $h );
    }

    /**
     * Sugiere el mapeo campo => índice. Recibe filas de muestra para corregir
     * el caso de la columna REFERENCIA cuyo valor cae en la columna contigua
     * (encabezado vacío): si la columna mapeada para sku viene vacía en los
     * datos, busca la mejor columna por contenido.
     */
    public function suggest_mapping( array $headers, array $sample_rows = array() ) {
        $norm = array();
        foreach ( $headers as $i => $h ) {
            $norm[ $i ] = $this->normalize_header( $h );
        }
        $mapping = array();
        foreach ( self::FIELD_SYNONYMS as $field => $syns ) {
            foreach ( $syns as $syn ) {
                $syn_n = $this->normalize_header( $syn );
                foreach ( $norm as $i => $h ) {
                    if ( $h === $syn_n || ( '' !== $h && false !== strpos( $h, $syn_n ) ) ) {
                        $mapping[ $field ] = $i;
                        break 2;
                    }
                }
            }
        }

        // Corrección del SKU: si la columna mapeada está vacía en la muestra,
        // elige la columna (distinta de nombre/stock) con más códigos no vacíos.
        if ( ! empty( $sample_rows ) ) {
            $sku_idx = $mapping['sku'] ?? null;
            if ( null === $sku_idx || ! $this->column_has_data( $sample_rows, $sku_idx ) ) {
                $excluir = array( $mapping['nombre'] ?? -1, $mapping['stock'] ?? -1 );
                $mejor = $this->best_code_column( $sample_rows, $excluir );
                if ( null !== $mejor ) {
                    $mapping['sku'] = $mejor;
                }
            }
        }

        return $mapping;
    }

    private function column_has_data( array $rows, $idx ) {
        if ( null === $idx ) { return false; }
        $con = 0;
        foreach ( $rows as $r ) {
            if ( isset( $r[ $idx ] ) && '' !== trim( (string) $r[ $idx ] ) ) { $con++; }
        }
        return $con >= max( 1, (int) ( count( $rows ) / 2 ) );
    }

    /** Columna con más valores tipo código (cortos, alfanuméricos, no vacíos). */
    private function best_code_column( array $rows, array $excluir ) {
        $ncols = 0;
        foreach ( $rows as $r ) { $ncols = max( $ncols, count( $r ) ); }
        $best = null; $best_score = 0;
        for ( $c = 0; $c < $ncols; $c++ ) {
            if ( in_array( $c, $excluir, true ) ) { continue; }
            $score = 0;
            foreach ( $rows as $r ) {
                $v = isset( $r[ $c ] ) ? trim( (string) $r[ $c ] ) : '';
                if ( '' !== $v && strlen( $v ) <= 40 && preg_match( '/[A-Za-z0-9]/', $v ) ) { $score++; }
            }
            if ( $score > $best_score ) { $best_score = $score; $best = $c; }
        }
        return $best;
    }

    /**
     * Vista previa: agrega por SKU (una fila por referencia; si se repite, gana
     * la última existencia) y reporta errores y conteos.
     */
    public function build_preview( array $data_rows, array $mapping, array $existing_skus = array() ) {
        foreach ( self::REQUIRED as $req ) {
            if ( ! isset( $mapping[ $req ] ) ) {
                return array( 'ok' => false, 'message' => 'Falta mapear el campo obligatorio: ' . $req, 'rows' => array(), 'summary' => array() );
            }
        }

        $existing = array_fill_keys( array_map( 'strval', $existing_skus ), true );
        $por_sku = array();
        $errores = 0;
        $lineas = 0;

        foreach ( $data_rows as $cells ) {
            if ( ! is_array( $cells ) ) { continue; }
            $lineas++;
            $sku   = isset( $cells[ $mapping['sku'] ] ) ? trim( (string) $cells[ $mapping['sku'] ] ) : '';
            $stock = isset( $cells[ $mapping['stock'] ] ) ? $this->to_int( $cells[ $mapping['stock'] ] ) : 0;
            $nombre = isset( $mapping['nombre'], $cells[ $mapping['nombre'] ] ) ? trim( (string) $cells[ $mapping['nombre'] ] ) : '';
            if ( '' === $sku ) { $errores++; continue; }
            $por_sku[ $sku ] = array( 'sku' => $sku, 'nombre' => $nombre, 'stock' => $stock );
        }

        $rows = array_values( $por_sku );
        $con_stock = 0; $nuevos = 0; $actualizar = 0;
        foreach ( $rows as $r ) {
            if ( $r['stock'] > 0 ) { $con_stock++; }
            if ( isset( $existing[ $r['sku'] ] ) ) { $actualizar++; } else { $nuevos++; }
        }

        return array(
            'ok'      => true,
            'message' => '',
            'rows'    => $rows,
            'summary' => array(
                'lineas'         => $lineas,
                'con_error'      => $errores,
                'skus'           => count( $rows ),
                'con_stock'      => $con_stock,
                'nuevos'         => $nuevos,
                'actualizar'     => $actualizar,
            ),
        );
    }

    /** Entero tolerante a separadores de miles; negativos -> 0 (dato sucio). */
    public function to_int( $value ) {
        $s = trim( (string) $value );
        $neg = ( 0 === strpos( $s, '-' ) );
        $s = preg_replace( '/[^0-9]/', '', $s );
        if ( '' === $s ) { return 0; }
        $n = (int) $s;
        return $neg ? 0 : $n;
    }
}
