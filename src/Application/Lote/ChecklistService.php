<?php
namespace MegaMundo\Logistica\Application\Lote;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteAuditRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;

/**
 * ChecklistService
 *
 * Gestiona el checklist operativo del lote.
 * Los datos se guardan como post_meta para simplicidad y sin riesgo de pérdida de datos.
 *
 * Clave de meta: _mm_lote_checklist
 * Valor: array serializado de items marcados.
 */
class ChecklistService {

    /** Todos los items disponibles en el checklist */
    const ALL_ITEMS = array(
        'conteo_fisico_terminado',
        'productos_nuevos_con_foto',
        'costos_revisados',
        'precios_completos',
        'margenes_revisados',
        'errores_sync_revisados',
        'etiquetas_impresas',
        'csv_exportado',
        'listo_para_aprobacion',
    );

    /** Etiquetas legibles de cada item */
    const LABELS = array(
        'conteo_fisico_terminado'  => '📦 Conteo físico terminado',
        'productos_nuevos_con_foto'=> '📷 Productos nuevos con foto',
        'costos_revisados'         => '💰 Costos revisados',
        'precios_completos'        => '💵 Precios completos',
        'margenes_revisados'       => '📊 Márgenes revisados',
        'errores_sync_revisados'   => '⚠️ Errores de sync revisados',
        'etiquetas_impresas'       => '🏷️ Etiquetas impresas',
        'csv_exportado'            => '📄 CSV exportado',
        'listo_para_aprobacion'    => '✅ Listo para aprobación',
    );

    /** Items que puede marcar mm_contador */
    const CONTADOR_ITEMS = array(
        'conteo_fisico_terminado',
        'productos_nuevos_con_foto',
    );

    /** Items que puede marcar mm_ingresador */
    const INGRESADOR_ITEMS = array(
        'costos_revisados',
        'precios_completos',
        'margenes_revisados',
        'listo_para_aprobacion',
    );

    /** Items requeridos para aprobar (mm_cargado) */
    const REQUIRED_FOR_APPROVAL = array(
        'precios_completos',
        'margenes_revisados',
        'listo_para_aprobacion',
    );

    private $audit_repo;
    private $permission_guard;

    public function __construct( LoteAuditRepository $audit_repo, PermissionGuard $permission_guard ) {
        $this->audit_repo       = $audit_repo;
        $this->permission_guard = $permission_guard;
    }

    /**
     * Obtiene el checklist actual del lote.
     *
     * @param  int   $lote_id
     * @return array Array de keys marcados como true/false.
     */
    public function get_checklist( $lote_id ) {
        $raw = get_post_meta( intval( $lote_id ), '_mm_lote_checklist', true );
        $checked = is_array( $raw ) ? $raw : array();

        $result = array();
        foreach ( self::ALL_ITEMS as $key ) {
            $result[ $key ] = ! empty( $checked[ $key ] );
        }

        return $result;
    }

    /**
     * Actualiza el checklist del lote, respetando permisos por rol.
     *
     * @param  int   $lote_id
     * @param  array $new_values  Array de key => bool enviado desde el formulario.
     * @param  int   $user_id
     * @return array              Array con 'success', 'changed' y 'error'.
     */
    public function update_checklist( $lote_id, $new_values, $user_id = null ) {
        if ( $user_id === null ) {
            $user_id = get_current_user_id();
        }

        $lote_id = intval( $lote_id );

        // Obtener checklist actual antes del cambio
        $current_checklist = $this->get_checklist( $lote_id );

        // Determinar qué items puede modificar el usuario
        $allowed_items = $this->get_allowed_items_for_current_user();

        if ( empty( $allowed_items ) ) {
            return array(
                'success' => false,
                'error'   => 'No tienes permisos para modificar el checklist.',
            );
        }

        $updated_checklist = $current_checklist;
        $changes           = array();

        foreach ( $allowed_items as $key ) {
            if ( ! in_array( $key, self::ALL_ITEMS, true ) ) {
                continue;
            }
            $new_val = ! empty( $new_values[ $key ] );
            $old_val = ! empty( $current_checklist[ $key ] );

            if ( $new_val !== $old_val ) {
                $changes[ $key ] = array(
                    'old' => $old_val ? '1' : '0',
                    'new' => $new_val ? '1' : '0',
                );
                $updated_checklist[ $key ] = $new_val;
            }
        }

        if ( empty( $changes ) ) {
            return array( 'success' => true, 'changed' => false );
        }

        // Guardar en post_meta
        update_post_meta( $lote_id, '_mm_lote_checklist', $updated_checklist );

        // Registrar en bitácora un evento por cada item cambiado
        foreach ( $changes as $key => $vals ) {
            $label = isset( self::LABELS[ $key ] ) ? self::LABELS[ $key ] : $key;
            $this->audit_repo->add_log(
                $lote_id,
                'checklist_actualizado',
                sprintf( 'Checklist actualizado: %s → %s', $label, $vals['new'] === '1' ? 'Marcado ✔' : 'Desmarcado' ),
                null,
                null,
                null,
                $vals['old'],
                $vals['new'],
                $user_id
            );
        }

        return array( 'success' => true, 'changed' => true );
    }

    /**
     * Devuelve los items del checklist que puede modificar el usuario actual.
     *
     * @return array
     */
    public function get_allowed_items_for_current_user() {
        if ( $this->permission_guard->is_admin() ) {
            return self::ALL_ITEMS;
        }
        if ( $this->permission_guard->is_ingresador() ) {
            return self::INGRESADOR_ITEMS;
        }
        if ( $this->permission_guard->is_contador() ) {
            return self::CONTADOR_ITEMS;
        }
        return array();
    }

    /**
     * Valida si el lote cumple los requisitos mínimos del checklist para pasar a mm_cargado.
     *
     * @param  int $lote_id
     * @return true|string  true si pasa, string con mensaje de error si no.
     */
    public function validate_for_approval( $lote_id ) {
        $checklist = $this->get_checklist( $lote_id );

        $missing = array();
        foreach ( self::REQUIRED_FOR_APPROVAL as $key ) {
            if ( empty( $checklist[ $key ] ) ) {
                $missing[] = isset( self::LABELS[ $key ] ) ? self::LABELS[ $key ] : $key;
            }
        }

        if ( ! empty( $missing ) ) {
            return 'No puedes aprobar este lote porque falta completar el checklist de aprobación: ' . implode( ', ', $missing ) . '.';
        }

        return true;
    }
}
