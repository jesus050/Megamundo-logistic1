<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MM_Logistica_CPT_Lotes {

    public function __construct() {
        add_action( 'init', array( $this, 'registrar_cpt' ) );
        add_action( 'init', array( $this, 'registrar_estados_personalizados' ) );
    }

    public function registrar_cpt() {
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
            'show_in_rest'    => true, // Permite que los endpoints consuman este CPT
        );

        register_post_type( 'lotes_ingreso', $args );
    }

    public function registrar_estados_personalizados() {
        // Estado: Pendiente de Precios (El contador terminó, el ingresador debe actuar)
        register_post_status( 'mm_p_precios', array(
            'label'                     => 'Pendiente de Precios',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Pendiente Precios <span class="count">(%s)</span>', 'Pendiente Precios <span class="count">(%s)</span>' ),
        ) );

        // Estado: Pendiente de Aprobación (El ingresador propuso precios, el jefe debe validar)
        register_post_status( 'mm_p_aprobacion', array(
            'label'                     => 'Pendiente de Aprobación',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Pendiente Aprobación <span class="count">(%s)</span>', 'Pendiente Aprobación <span class="count">(%s)</span>' ),
        ) );

        // Estado: Cargado (Proceso finalizado, stock inyectado y data lista para Mekano)
        register_post_status( 'mm_cargado', array(
            'label'                     => 'Cargado en Sistema',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Cargados <span class="count">(%s)</span>', 'Cargados <span class="count">(%s)</span>' ),
        ) );
    }
}

new MM_Logistica_CPT_Lotes();
