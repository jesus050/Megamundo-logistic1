<?php
namespace MegaMundo\Logistica\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class PermissionGuard {

    public function get_user_roles( $user = null ) {
        if ( ! $user || ! ( $user instanceof \WP_User ) ) {
            $user = wp_get_current_user();
        }
        return ( $user instanceof \WP_User && isset( $user->roles ) ) ? (array) $user->roles : array();
    }

    public function is_admin( $user = null ) {
        return in_array( 'administrator', $this->get_user_roles( $user ), true );
    }

    public function is_ingresador( $user = null ) {
        return in_array( 'mm_ingresador', $this->get_user_roles( $user ), true );
    }

    public function is_contador( $user = null ) {
        return in_array( 'mm_contador', $this->get_user_roles( $user ), true );
    }

    public function can_scan( $user = null ) {
        // El contador y el administrador pueden escanear
        return $this->is_contador( $user ) || $this->is_admin( $user );
    }

    public function can_view_finance( $user = null ) {
        // Solo ingresadores y administradores pueden ver información financiera
        return $this->is_ingresador( $user ) || $this->is_admin( $user );
    }

    public function can_edit_finance( $user = null ) {
        return $this->is_ingresador( $user ) || $this->is_admin( $user );
    }

    public function can_approve( $user = null ) {
        return $this->is_admin( $user );
    }

    public function can_print( $user = null ) {
        return $this->is_ingresador( $user ) || $this->is_admin( $user );
    }

    public function can_export( $user = null ) {
        return $this->is_ingresador( $user ) || $this->is_admin( $user );
    }

    /**
     * Puede ver comentarios internos del lote.
     * Todos los roles del plugin pueden ver comentarios.
     */
    public function can_view_lote_comments( $user = null ) {
        return $this->is_admin( $user ) || $this->is_ingresador( $user ) || $this->is_contador( $user );
    }

    /**
     * Puede agregar comentarios internos al lote.
     * Todos los roles del plugin pueden escribir comentarios.
     */
    public function can_add_lote_comment( $user = null ) {
        return $this->is_admin( $user ) || $this->is_ingresador( $user ) || $this->is_contador( $user );
    }

    /**
     * Puede actualizar ítems del checklist del lote.
     * Todos los roles pueden, pero cada rol tiene sus ítems permitidos (gestionado en ChecklistService).
     */
    public function can_update_lote_checklist( $user = null ) {
        return $this->is_admin( $user ) || $this->is_ingresador( $user ) || $this->is_contador( $user );
    }


    public function can_access_bodega_panel( $user = null ) {
        return $this->is_contador( $user ) || $this->is_admin( $user );
    }

    public function can_access_precios_panel( $user = null ) {
        return $this->is_ingresador( $user ) || $this->is_admin( $user );
    }

    public function can_access_jefatura_panel( $user = null ) {
        return $this->is_admin( $user );
    }

    public function get_default_app_panel( $user = null ) {
        if ( $this->is_admin( $user ) ) {
            return 'jefatura';
        }
        if ( $this->is_ingresador( $user ) ) {
            return 'precios';
        }
        if ( $this->is_contador( $user ) ) {
            return 'bodega';
        }
        return 'login';
    }

}
