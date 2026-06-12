<?php
namespace MegaMundo\Logistica\Application\Export;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;

class MekanoExportService {

    private $lote_repo;
    private $item_repo;

    public function __construct( LoteRepository $lote_repo, LoteItemRepository $item_repo ) {
        $this->lote_repo = $lote_repo;
        $this->item_repo = $item_repo;
    }

    public function mekano_template_path() {
        return trailingslashit( MM_LOGISTICA_PATH ) . 'assets/templates/plantilla-referencias-mekano.xlsx';
    }

    public function mekano_xml_escape( $value ) {
        $value = (string) $value;
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
        return htmlspecialchars( $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
    }

    public function mekano_cell_xml( $col, $row, $value, $style = '', $is_numeric = false, $formula = '' ) {
        $ref = $col . $row;
        $style_attr = $style !== '' ? ' s="' . esc_attr( $style ) . '"' : '';

        if ( $formula !== '' ) {
            return '<c r="' . esc_attr( $ref ) . '"' . $style_attr . ' t="str"><f>' . $this->mekano_xml_escape( $formula ) . '</f></c>';
        }

        if ( $is_numeric && $value !== '' && is_numeric( $value ) ) {
            return '<c r="' . esc_attr( $ref ) . '"' . $style_attr . '><v>' . $this->mekano_xml_escape( $value ) . '</v></c>';
        }

        if ( $value === '' || $value === null ) {
            return '<c r="' . esc_attr( $ref ) . '"' . $style_attr . '/>';
        }

        return '<c r="' . esc_attr( $ref ) . '" t="inlineStr"' . $style_attr . '><is><t>' . $this->mekano_xml_escape( $value ) . '</t></is></c>';
    }

    public function mekano_get_template_row_styles( $sheet_xml, $row_number = 8 ) {
        $styles = array();

        if ( preg_match_all( '/<c\s+([^>]*r="([A-Z]+)' . intval( $row_number ) . '"[^>]*)>/i', $sheet_xml, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $col = $match[2];
                if ( preg_match( '/\ss="([^"]+)"/', $match[1], $s ) ) {
                    $styles[ $col ] = $s[1];
                }
            }
        }

        return $styles;
    }

    public function mekano_get_template_x_formula( $sheet_xml, $row_number = 8 ) {
        if ( preg_match( '/<c\s+[^>]*r="X' . intval( $row_number ) . '"[^>]*>.*?<f[^>]*>(.*?)<\/f>.*?<\/c>/s', $sheet_xml, $match ) ) {
            return html_entity_decode( $match[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' );
        }

        if ( preg_match( '/<c\s+[^>]*r="X7"[^>]*>.*?<f[^>]*>(.*?)<\/f>.*?<\/c>/s', $sheet_xml, $match ) ) {
            return preg_replace( '/([A-W])7\b/', '$1' . intval( $row_number ), html_entity_decode( $match[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) );
        }

        return '';
    }

    public function mekano_formula_for_row( $formula, $row_number ) {
        if ( empty( $formula ) ) {
            return '';
        }

        return preg_replace( '/([A-W])(?:7|8|9|10)\b/', '$1' . intval( $row_number ), $formula );
    }

    public function build_mekano_xlsx_from_template( $lote_id ) {
        if ( ! class_exists( '\\ZipArchive' ) ) {
            return new \WP_Error( 'zip_missing', 'El servidor no tiene activa la extensión PHP ZIP/ZipArchive. Actívala en Hostinger para generar XLSX.' );
        }

        $template = $this->mekano_template_path();
        if ( ! file_exists( $template ) ) {
            return new \WP_Error( 'template_missing', 'No se encontró la plantilla XLSX oficial dentro del plugin.' );
        }

        if ( ! is_readable( $template ) ) {
            return new \WP_Error( 'template_not_readable', 'La plantilla XLSX existe, pero no se puede leer.' );
        }

        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) {
            return new \WP_Error( 'upload_dir_error', 'WordPress no pudo acceder a la carpeta uploads: ' . $upload_dir['error'] );
        }

        $dir = trailingslashit( $upload_dir['basedir'] ) . 'megamundo-mekano/';
        if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return new \WP_Error( 'mkdir_failed', 'No se pudo crear la carpeta de exportación: ' . $dir );
        }

        if ( ! is_writable( $dir ) ) {
            return new \WP_Error( 'dir_not_writable', 'La carpeta de exportación no tiene permisos de escritura: ' . $dir );
        }

        $file = $dir . 'mekano-lote-' . intval( $lote_id ) . '-' . date( 'Ymd-His' ) . '.xlsx';
        if ( ! copy( $template, $file ) ) {
            return new \WP_Error( 'copy_failed', 'No se pudo copiar la plantilla base de Mekano.' );
        }

        $zip = new \ZipArchive();
        $open_result = $zip->open( $file );
        if ( true !== $open_result ) {
            return new \WP_Error( 'zip_open_failed', 'No se pudo abrir el XLSX generado. Código ZipArchive: ' . $open_result );
        }

        $sheet_path = 'xl/worksheets/sheet1.xml';
        $sheet_xml = $zip->getFromName( $sheet_path );
        if ( false === $sheet_xml ) {
            $zip->close();
            return new \WP_Error( 'sheet_missing', 'No se encontró xl/worksheets/sheet1.xml dentro de la plantilla.' );
        }

        $styles = $this->mekano_get_template_row_styles( $sheet_xml, 8 );
        $items = $this->item_repo ? $this->item_repo->get_items( intval( $lote_id ) ) : array();

        $row_number = 8;
        $max_template_row = 255;
        $rows_to_replace = array();

        foreach ( $items as $item ) {
            if ( $row_number > $max_template_row ) {
                break;
            }

            $v = $this->get_item_mekano_values( $lote_id, $item );

            $row_cells = array(
                'A' => array( $v['codigo'], false ),
                'B' => array( $v['nombre'], false ),
                'C' => array( $v['linea'], false ),
                'D' => array( $v['unidad'], false ),
                'E' => array( $v['costo'], true ),
                'F' => array( $v['precio'], true ),
                'G' => array( $v['esquema_contable'], false ),
                'H' => array( $v['esquema_impuestos'], false ),
                'I' => array( $v['esquema_retenciones'], false ),
                'J' => array( $v['codigo_alterno'], false ),
                'K' => array( $v['nombre_corto'], false ),
                'L' => array( $v['ubicacion'], false ),
                'M' => array( $v['tiempo'], true ),
                'N' => array( $v['peso'], true ),
                'O' => array( $v['alto'], true ),
                'P' => array( $v['ancho'], true ),
                'Q' => array( $v['fondo'], true ),
                'R' => array( $v['medida_rastreo'], false ),
                'S' => array( $v['medida_alterna'], false ),
                'T' => array( $v['ensamble'], false ),
                'U' => array( $v['lote'], false ),
                'V' => array( $v['serial'], false ),
                'W' => array( $v['categoria'], false ),
            );

            $row_xml = '<row r="' . intval( $row_number ) . '" spans="1:26">';
            foreach ( $row_cells as $col => $cell ) {
                $row_xml .= $this->mekano_cell_xml( $col, $row_number, $cell[0], $styles[ $col ] ?? '', $cell[1] );
            }

            $row_xml .= '<c r="X' . intval( $row_number ) . '" s="' . esc_attr( $styles['X'] ?? '52' ) . '"/>';
            $row_xml .= '</row>';

            $rows_to_replace[ $row_number ] = $row_xml;
            $row_number++;
        }

        // Limpia el resto de filas para que no queden datos de ejemplo.
        for ( $r = $row_number; $r <= $max_template_row; $r++ ) {
            $row_xml = '<row r="' . intval( $r ) . '" spans="1:26">';
            foreach ( range( 'A', 'W' ) as $col ) {
                $row_xml .= '<c r="' . esc_attr( $col . $r ) . '" s="' . esc_attr( $styles[ $col ] ?? '50' ) . '"/>';
            }
            $row_xml .= '<c r="X' . intval( $r ) . '" s="' . esc_attr( $styles['X'] ?? '52' ) . '"/>';
            $row_xml .= '</row>';
            $rows_to_replace[ $r ] = $row_xml;
        }

        foreach ( $rows_to_replace as $row => $row_xml ) {
            $pattern = '/<row[^>]*\sr="' . intval( $row ) . '"[^>]*>.*?<\/row>/s';
            if ( preg_match( $pattern, $sheet_xml ) ) {
                $sheet_xml = preg_replace( $pattern, $row_xml, $sheet_xml, 1 );
            } else {
                $sheet_xml = str_replace( '</sheetData>', $row_xml . '</sheetData>', $sheet_xml );
            }
        }

        if ( ! $zip->addFromString( $sheet_path, $sheet_xml ) ) {
            $zip->close();
            return new \WP_Error( 'sheet_write_failed', 'No se pudo escribir la hoja PLANTILLA dentro del XLSX.' );
        }

        if ( false !== $zip->locateName( 'xl/calcChain.xml' ) ) {
            $zip->deleteName( 'xl/calcChain.xml' );
        }

        $content_types_xml = $zip->getFromName( '[Content_Types].xml' );
        if ( false !== $content_types_xml ) {
            $content_types_xml = preg_replace( '#<Override[^>]+PartName="/xl/calcChain.xml"[^>]*/>#', '', $content_types_xml );
            $zip->addFromString( '[Content_Types].xml', $content_types_xml );
        }

        $workbook_rels_xml = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
        if ( false !== $workbook_rels_xml ) {
            $workbook_rels_xml = preg_replace( '#<Relationship[^>]+Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/calcChain"[^>]*/>#', '', $workbook_rels_xml );
            $zip->addFromString( 'xl/_rels/workbook.xml.rels', $workbook_rels_xml );
        }

        $zip->close();

        if ( ! file_exists( $file ) || filesize( $file ) <= 0 ) {
            return new \WP_Error( 'empty_file', 'El XLSX generado quedó vacío.' );
        }

        return $file;
    }

    public function get_mekano_lotes_disponibles() {
        $statuses = array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' );
        $lotes = array();
        foreach ( $statuses as $status ) {
            foreach ( $this->lote_repo->find_by_status( $status ) as $lote ) {
                if ( isset( $lote->ID ) ) { $lotes[ intval( $lote->ID ) ] = $lote; }
            }
        }
        krsort( $lotes );
        return array_values( $lotes );
    }

    public function get_mekano_lote_actual() {
        $lote_id = isset( $_GET['lote_id'] ) ? absint( $_GET['lote_id'] ) : 0;
        if ( $lote_id > 0 && $this->lote_repo->find( $lote_id ) ) { return $lote_id; }
        $lotes = $this->get_mekano_lotes_disponibles();
        return ! empty( $lotes ) ? intval( $lotes[0]->ID ) : 0;
    }

    public function mekano_clean_value( $value ) {
        $value = wp_strip_all_tags( (string) $value );
        $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = preg_replace( '/\s+/', ' ', $value );
        return trim( $value );
    }

    public function mekano_short_name( $name ) {
        $name = $this->mekano_clean_value( $name );
        return mb_substr( $name, 0, 40 );
    }

    public function mekano_escape_csv( $value ) {
        $value = (string) $value;
        $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = str_replace( '"', '""', $value );
        $value = str_replace( array( "\r", "\n" ), ' ', $value );
        return '"' . $value . '"';
    }

    public function get_item_mekano_values( $lote_id, $item ) {
        $sku = isset( $item->sku ) ? $this->mekano_clean_value( $item->sku ) : '';
        $product_id = isset( $item->producto_id ) ? intval( $item->producto_id ) : 0;
        $name = $product_id ? get_the_title( $product_id ) : '';
        $name = $name ? $this->mekano_clean_value( $name ) : 'Producto ' . $sku;

        $category_name = '';
        if ( $product_id ) {
            $terms = get_the_terms( $product_id, 'product_cat' );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $category_name = $terms[0]->name;
            }
        }

        return array(
            'codigo'            => $sku,
            'nombre'            => $name,
            'linea'             => 'MM',
            'unidad'            => $this->mekano_clean_value( get_post_meta( $product_id, '_mm_unidad_mekano', true ) ?: 'UND' ),
            'costo'             => isset( $item->costo_ia ) ? floatval( $item->costo_ia ) : 0.0,
            'precio'            => isset( $item->precio_propuesto ) ? floatval( $item->precio_propuesto ) : 0.0,
            'esquema_contable'  => $this->mekano_clean_value( get_post_meta( $product_id, '_mm_esquema_contable', true ) ?: 'ESQ' ),
            'esquema_impuestos' => $this->mekano_clean_value( get_post_meta( $product_id, '_mm_esquema_impuestos', true ) ?: 'EI' ),
            'esquema_retenciones' => $this->mekano_clean_value( get_post_meta( $product_id, '_mm_esquema_retenciones', true ) ?: 'ECOM1' ),
            'codigo_alterno'    => $this->mekano_clean_value( get_post_meta( $product_id, '_mm_codigo_alterno', true ) ?: get_post_meta( $lote_id, '_mm_factura_origen_numero', true ) ),
            'nombre_corto'      => $this->mekano_short_name( get_post_meta( $product_id, '_mm_nombre_corto', true ) ?: $name ),
            'ubicacion'         => $this->mekano_clean_value( get_post_meta( $product_id, '_mm_ubicacion', true ) ),
            'tiempo'            => '0',
            'peso'              => $this->mekano_clean_value( get_post_meta( $product_id, '_weight', true ) ),
            'alto'              => $this->mekano_clean_value( get_post_meta( $product_id, '_height', true ) ),
            'ancho'             => $this->mekano_clean_value( get_post_meta( $product_id, '_width', true ) ),
            'fondo'             => $this->mekano_clean_value( get_post_meta( $product_id, '_length', true ) ),
            'medida_rastreo'    => 'Ninguna',
            'medida_alterna'    => '',
            'ensamble'          => 'No',
            'lote'              => 'No',
            'serial'            => 'No',
            'categoria'         => $this->mekano_clean_value( $category_name ),
        );
    }

    public function build_mekano_csv_content( $lote_id ) {
        $headers = array( 'CODIGO','NOMBRE','LINEA','UNIDAD DE MEDIDA','COSTO','PRECIO','ESQUEMA CONTABLE','ESQUEMA IMPUESTOS','ESQUEMA RETENCIONES','CODIGO ALTERNO','NOMBRE CORTO','UBICACION','TIEMPO','PESO','ALTO','ANCHO','FONDO','MEDIDA DE RASTREO','MEDIDA ALTERNA','ENSAMBLE','LOTE','SERIAL','CATEGORIA','NO TOCAR ESTA COLUMNA' );
        $rows = array( $headers );
        $items = $this->item_repo ? $this->item_repo->get_items( intval( $lote_id ) ) : array();
        foreach ( $items as $item ) {
            $v = $this->get_item_mekano_values( $lote_id, $item );
            $rows[] = array( $v['codigo'],$v['nombre'],$v['linea'],$v['unidad'],$v['costo'],$v['precio'],$v['esquema_contable'],$v['esquema_impuestos'],$v['esquema_retenciones'],$v['codigo_alterno'],$v['nombre_corto'],$v['ubicacion'],$v['tiempo'],$v['peso'],$v['alto'],$v['ancho'],$v['fondo'],$v['medida_rastreo'],$v['medida_alterna'],$v['ensamble'],$v['lote'],$v['serial'],$v['categoria'],'' );
        }
        $lines = array();
        foreach ( $rows as $row ) {
            $escaped = array();
            foreach ( $row as $cell ) { $escaped[] = $this->mekano_escape_csv( $cell ); }
            $lines[] = implode( ';', $escaped );
        }
        return "\xEF\xBB\xBF" . implode( "\r\n", $lines );
    }
}
