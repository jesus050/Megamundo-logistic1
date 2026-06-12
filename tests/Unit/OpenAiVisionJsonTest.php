<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use PHPUnit\Framework\TestCase;

class OpenAiVisionJsonTest extends TestCase {

    private $service;

    protected function setUp(): void {
        $this->service = new OpenAiVisionService();
    }

    public function test_extrae_json_plano() {
        $result = $this->service->mm_extract_json_from_openai_direct( '{"proveedor":"ACME"}' );
        $this->assertSame( array( 'proveedor' => 'ACME' ), $result );
    }

    public function test_extrae_json_con_fences_markdown() {
        $raw = "```json\n{\"proveedor\":\"ACME\",\"total\":150}\n```";
        $result = $this->service->mm_extract_json_from_openai_direct( $raw );
        $this->assertSame( 'ACME', $result['proveedor'] );
        $this->assertSame( 150, $result['total'] );
    }

    public function test_extrae_json_rodeado_de_prosa() {
        $raw = 'Aquí está el resultado: {"numero_factura":"FV-2034"} espero que sirva.';
        $result = $this->service->mm_extract_json_from_openai_direct( $raw );
        $this->assertSame( 'FV-2034', $result['numero_factura'] );
    }

    public function test_devuelve_null_para_texto_sin_json() {
        $this->assertNull( $this->service->mm_extract_json_from_openai_direct( 'no hay json aquí' ) );
        $this->assertNull( $this->service->mm_extract_json_from_openai_direct( '' ) );
    }

    public function test_array_pasa_directo() {
        $input = array( 'ya' => 'decodificado' );
        $this->assertSame( $input, $this->service->mm_extract_json_from_openai_direct( $input ) );
    }

    public function test_normaliza_factura_con_claves_alternativas() {
        $decoded = array(
            'factura' => 'FV-99',
            'fecha'   => '2026-06-01',
            'total'   => '2500.50',
            'items'   => array(
                array( 'sku' => 'ABC-1', 'descripcion' => 'Producto Uno', 'cantidad' => 3, 'costo_unitario' => 10.5 ),
            ),
        );

        $result = $this->service->mm_normalize_openai_invoice_json( $decoded );

        $this->assertSame( 'FV-99', $result['numero_factura'] );
        $this->assertSame( '2026-06-01', $result['fecha_factura'] );
        $this->assertSame( 2500.50, $result['total_factura'] );
        $this->assertSame( 'COP', $result['moneda'] );
        $this->assertCount( 1, $result['productos_detectados'] );
        $this->assertSame( 'ABC-1', $result['productos_detectados'][0]['codigo'] );
        $this->assertSame( 'Producto Uno', $result['productos_detectados'][0]['nombre'] );
        $this->assertSame( 3, $result['productos_detectados'][0]['cantidad'] );
        $this->assertSame( 10.5, $result['productos_detectados'][0]['costo'] );
    }

    public function test_normaliza_factura_agrupa_duplicados_por_codigo() {
        $decoded = array(
            'productos_detectados' => array(
                array( 'codigo' => 'X-1', 'nombre' => 'Repetido', 'cantidad' => 2, 'costo' => 5 ),
                array( 'codigo' => 'X-1', 'nombre' => 'Repetido', 'cantidad' => 4 ),
            ),
        );

        $result = $this->service->mm_normalize_openai_invoice_json( $decoded );

        $this->assertCount( 1, $result['productos_detectados'] );
        $this->assertSame( 6, $result['productos_detectados'][0]['cantidad'] );
        $this->assertSame( 5.0, $result['productos_detectados'][0]['costo'] );
        $this->assertSame( 1, $result['total_productos'] );
    }

    public function test_normaliza_factura_descarta_items_sin_codigo_ni_nombre() {
        $decoded = array(
            'productos_detectados' => array(
                array( 'cantidad' => 99 ),
                array( 'codigo' => 'OK-1', 'nombre' => 'Válido', 'cantidad' => 1 ),
            ),
        );

        $result = $this->service->mm_normalize_openai_invoice_json( $decoded );

        $this->assertCount( 1, $result['productos_detectados'] );
        $this->assertSame( 'OK-1', $result['productos_detectados'][0]['codigo'] );
    }

    public function test_modelo_de_facturas_rechaza_valores_no_permitidos() {
        $GLOBALS['mm_test_options']['mm_openai_invoice_model'] = 'modelo-inventado';
        $this->assertSame( 'gpt-4o-mini', $this->service->mm_get_ai_model_for_invoices_direct() );

        $GLOBALS['mm_test_options']['mm_openai_invoice_model'] = 'gpt-4o';
        $this->assertSame( 'gpt-4o', $this->service->mm_get_ai_model_for_invoices_direct() );

        unset( $GLOBALS['mm_test_options']['mm_openai_invoice_model'] );
    }
}
