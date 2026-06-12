<?php
namespace MegaMundo\Logistica\Domain\Lote;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LotePostType {

    public function register() {
        $labels = array(
            'name'          => 'Lotes de Ingreso',
            'singular_name' => 'Lote de Ingreso',
            'menu_name'     => 'Logística MegaMundo',
            'all_items'     => 'Todos los Lotes',
            'add_new'       => 'Nuevo Lote / Conteo',
        );

        $args = array(
            'labels'          => $labels,
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'capability_type' => 'post',
            'supports'        => array( 'title', 'author' ),
            'show_in_rest'    => true,
        );

        register_post_type( 'lotes_ingreso', $args );

        $this->registrar_estados_personalizados();
    }

    public function registrar_estados_personalizados() {
        register_post_status( LoteStatuses::PENDING_PRICES, array(
            'label'                     => 'Pendiente de Precios',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Pendiente Precios <span class="count">(%s)</span>', 'Pendiente Precios <span class="count">(%s)</span>' ),
        ) );

        register_post_status( LoteStatuses::PENDING_APPROVAL, array(
            'label'                     => 'Pendiente de Aprobación',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Pendiente Aprobación <span class="count">(%s)</span>', 'Pendiente Aprobación <span class="count">(%s)</span>' ),
        ) );

        register_post_status( LoteStatuses::LOADED, array(
            'label'                     => 'Cargado en Sistema',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Cargados <span class="count">(%s)</span>', 'Cargados <span class="count">(%s)</span>' ),
        ) );
    }
}
