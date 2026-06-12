<?php
namespace MegaMundo\Logistica\Tests\Unit;

use MegaMundo\Logistica\Domain\Lote\LoteStatuses;
use PHPUnit\Framework\TestCase;

class LoteStatusesTest extends TestCase {

    public function test_flujo_completo_de_estados() {
        $this->assertSame(
            array( 'draft', 'mm_p_precios', 'mm_p_aprobacion', 'mm_cargado' ),
            LoteStatuses::get_all()
        );
    }

    public function test_constantes_estables_para_integraciones() {
        // Estos valores viven en la base de datos (post_status); cambiarlos rompe lotes existentes.
        $this->assertSame( 'mm_p_precios', LoteStatuses::PENDING_PRICES );
        $this->assertSame( 'mm_p_aprobacion', LoteStatuses::PENDING_APPROVAL );
        $this->assertSame( 'mm_cargado', LoteStatuses::LOADED );
    }
}
