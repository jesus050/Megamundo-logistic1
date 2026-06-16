<?php
namespace MegaMundo\Logistica\Application\Inventory;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Importa el reporte de EXISTENCIAS de Mekano (CSV) para conocer el stock
 * actual por referencia. Lógica pura y testeable.
 *
 * Columnas reales del reporte de Mekano:
 *   REFERENCIA  → sku
 *   NOMBRE REFERENCIA → nombre
 *   VIENE       → viene (saldo inicial del periodo)
 *   ENTRADAS    → entradas (productos ingresados al bodegaje)
 *   SALIDAS     → salidas (productos vendidos / despachados)
 *   EXISTENCIA  → stock (inventario actual = viene + entradas − salidas)
 *
 * Los números en Mekano usan separador de miles con punto y decimal con coma
 * (formato colombiano): p. ej. "1.256,00" = 1256 unidades. El método to_int()
 * lo interpreta correctamente; NO elimina los decimales sin leerlos primero.
 */
class MekanoInventoryImporter {

    const FIELD_SYNONYMS = array(
        'sku'      => array( 'referencia', 'sku', 'codigo', 'cod', 'ref', 'codigo producto' ),
        'nombre'   => array( 'nombre referencia', 'nombre', 'descripcion', 'producto', 'descripcion producto' ),
        'viene'    => array( 'viene', 'saldo anterior', 'anterior', 'saldo inicial', 'saldo ini' ),
        'entradas' => array( 'entradas', 'entrada', 'compras', 'ingresos', 'ingreso' ),
        'salidas'  => array( 'salidas', 'salida', 'ventas', 'despachos', 'despacho', 'egresos' ),
        'stock'    => array( 'existencia', 'existencias', 'saldo', 'saldo final', 'stock', 'disponible', 'actual' ),
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
     * Sugiere el mapeo campo => índice de columna.
     * Incluye corrección automática del SKU cuando Mekano salta el valor a la
     * columna contigua (encabezado vacío).
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
        // busca la mejor columna por contenido (alfanumérico corto).
        if ( ! empty( $sample_rows ) ) {
            $sku_idx = isset( $mapping['sku'] ) ? $mapping['sku'] : null;
            if ( null === $sku_idx || ! $this->column_has_data( $sample_rows, $sku_idx ) ) {
                $excluir = array(
                    isset( $mapping['nombre'] ) ? $mapping['nombre'] : -1,
                    isset( $mapping['stock'] )  ? $mapping['stock']  : -1,
                );
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
     * Construye la vista previa: agrega por SKU e incluye TODAS las columnas
     * del reporte (viene, entradas, salidas, stock/existencia).
     *
     * @param array $data_rows   Filas de datos (sin cabecera).
     * @param array $mapping     Mapeo campo => índice de columna.
     * @param array $existing_skus  SKUs ya guardados (para reportar nuevos/actualizar).
     */
    public function build_preview( array $data_rows, array $mapping, array $existing_skus = array() ) {
        foreach ( self::REQUIRED as $req ) {
            if ( ! isset( $mapping[ $req ] ) ) {
                return array(
                    'ok'      => false,
                    'message' => 'No se encontró la columna obligatoria: ' . strtoupper( $req )
                               . '. Asegúrate de exportar el reporte de existencias de Mekano con columnas: '
                               . 'REFERENCIA, NOMBRE REFERENCIA, VIENE, ENTRADAS, SALIDAS, EXISTENCIA.',
                    'rows'    => array(),
                    'summary' => array(),
                );
            }
        }

        $existing = array_fill_keys( array_map( 'strval', $existing_skus ), true );
        $por_sku  = array();
        $errores  = 0;
        $lineas   = 0;

        foreach ( $data_rows as $cells ) {
            if ( ! is_array( $cells ) ) { continue; }
            $lineas++;

            $sku = isset( $cells[ $mapping['sku'] ] ) ? trim( (string) $cells[ $mapping['sku'] ] ) : '';
            if ( '' === $sku ) { $errores++; continue; }

            $nombre   = isset( $mapping['nombre'],   $cells[ $mapping['nombre'] ] )   ? trim( (string) $cells[ $mapping['nombre'] ] )   : '';
            $viene    = isset( $mapping['viene'],    $cells[ $mapping['viene'] ] )    ? $this->to_int( $cells[ $mapping['viene'] ] )    : 0;
            $entradas = isset( $mapping['entradas'], $cells[ $mapping['entradas'] ] ) ? $this->to_int( $cells[ $mapping['entradas'] ] ) : 0;
            $salidas  = isset( $mapping['salidas'],  $cells[ $mapping['salidas'] ] )  ? $this->to_int( $cells[ $mapping['salidas'] ] )  : 0;
            $stock    = $this->to_int( $cells[ $mapping['stock'] ] );

            $por_sku[ $sku ] = array(
                'sku'      => $sku,
                'nombre'   => $nombre,
                'viene'    => $viene,
                'entradas' => $entradas,
                'salidas'  => $salidas,
                'stock'    => $stock,
            );
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
                'lineas'     => $lineas,
                'con_error'  => $errores,
                'skus'       => count( $rows ),
                'con_stock'  => $con_stock,
                'nuevos'     => $nuevos,
                'actualizar' => $actualizar,
            ),
        );
    }

    /**
     * Convierte un valor numérico de Mekano (que puede venir con separadores
     * de miles y decimales al estilo colombiano) a entero.
     *
     * Formatos soportados:
     *   "256,00"      → 256   (coma = separador decimal)
     *   "1.256,00"    → 1256  (punto = miles, coma = decimal)
     *   "1,256.00"    → 1256  (coma = miles, punto = decimal)
     *   "256"         → 256   (sin separadores)
     *   "$256,00"     → 256   (símbolo de moneda ignorado)
     *   "-10,00"      → 0     (negativos → 0, dato sucio)
     */
    public function to_int( $value ) {
        $s = trim( (string) $value );

        // Quitar símbolos de moneda, espacios y caracteres no numéricos excepto , . -
        $s = preg_replace( '/[^0-9,.\-]/', '', $s );
        if ( '' === $s ) { return 0; }

        $neg = ( 0 === strpos( $s, '-' ) );

        $pos_coma  = strrpos( $s, ',' );
        $pos_punto = strrpos( $s, '.' );

        if ( false !== $pos_coma && false !== $pos_punto ) {
            // Ambos presentes: el último es el separador decimal.
            if ( $pos_coma > $pos_punto ) {
                // Estilo Colombia: 1.256,00 → punto = miles, coma = decimal
                $s = str_replace( '.', '', $s );        // quitar separador de miles
                $s = str_replace( ',', '.', $s );       // normalizar decimal
            } else {
                // Estilo anglosajón: 1,256.00 → coma = miles, punto = decimal
                $s = str_replace( ',', '', $s );        // quitar separador de miles
            }
        } elseif ( false !== $pos_coma ) {
            // Solo coma: decidir si es decimal o miles según dígitos que le siguen.
            $decimales = strlen( $s ) - $pos_coma - 1;
            if ( $decimales <= 2 ) {
                // Parece decimal (256,00 → 256)
                $s = str_replace( ',', '.', $s );
            } else {
                // Parece separador de miles (1,256 → 1256)
                $s = str_replace( ',', '', $s );
            }
        } elseif ( false !== $pos_punto ) {
            // Solo punto: ídem.
            $decimales = strlen( $s ) - $pos_punto - 1;
            if ( $decimales > 2 ) {
                // Separador de miles: 1.256 → 1256
                $s = str_replace( '.', '', $s );
            }
            // Si son ≤ 2 decimales, floatval + intval lo recorta: 256.00 → 256
        }

        $n = (int) floatval( $s );
        return ( $neg || $n < 0 ) ? 0 : $n;
    }
}
