<?php
namespace MegaMundo\Logistica\Domain\Lote;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LoteStatuses {
    const DRAFT = 'draft';
    const PENDING_PRICES = 'mm_p_precios';
    const PENDING_APPROVAL = 'mm_p_aprobacion';
    const LOADED = 'mm_cargado';

    public static function get_all() {
        return array(
            self::DRAFT,
            self::PENDING_PRICES,
            self::PENDING_APPROVAL,
            self::LOADED
        );
    }
}
