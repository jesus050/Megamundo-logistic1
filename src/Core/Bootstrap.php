<?php
namespace MegaMundo\Logistica\Core;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Database\DatabaseInstaller;
use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Domain\Lote\LoteAuditRepository;
use MegaMundo\Logistica\Domain\Lote\LotePostType;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Infrastructure\Security\RolesManager;
use MegaMundo\Logistica\Infrastructure\WooCommerce\WooCommerceGateway;
use MegaMundo\Logistica\Application\Export\CsvExportService;
use MegaMundo\Logistica\Application\Ticket\TicketService;
use MegaMundo\Logistica\Application\Scanner\ScannerService;
use MegaMundo\Logistica\Application\Sync\SyncProcessor;
use MegaMundo\Logistica\Application\Sync\SyncScheduler;
use MegaMundo\Logistica\Presentation\Admin\MetaboxController;
use MegaMundo\Logistica\Presentation\Admin\SettingsController;
use MegaMundo\Logistica\Presentation\Front\ScannerViewController;
use MegaMundo\Logistica\Presentation\Front\PwaController;
use MegaMundo\Logistica\Presentation\Printing\TicketPrintController;
use MegaMundo\Logistica\Presentation\Rest\ScannerController;
use MegaMundo\Logistica\Presentation\Rest\InvoiceVisionController;
use MegaMundo\Logistica\Presentation\Rest\IntelligenceController;
use MegaMundo\Logistica\Domain\Lote\LoteCommentRepository;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;
use MegaMundo\Logistica\Application\Pricing\PriceSuggestionService;
use MegaMundo\Logistica\Application\Quality\CountAnomalyDetector;
use MegaMundo\Logistica\Application\Integration\WebhookDispatcher;

class Bootstrap {

    private static $container;

    public static function init() {
        self::$container = new Container();

        // 1. Registrar dependencias básicas
        self::$container->set( PermissionGuard::class, function() {
            return new PermissionGuard();
        } );

        self::$container->set( RolesManager::class, function() {
            return new RolesManager();
        } );

        self::$container->set( LoteRepository::class, function() {
            return new LoteRepository();
        } );

        self::$container->set( LoteItemRepository::class, function() {
            return new LoteItemRepository();
        } );

        self::$container->set( LoteAuditRepository::class, function() {
            return new LoteAuditRepository();
        } );

        self::$container->set( LoteCommentRepository::class, function() {
            return new LoteCommentRepository();
        } );

        self::$container->set( ChecklistService::class, function( $c ) {
            return new ChecklistService(
                $c->get( LoteAuditRepository::class ),
                $c->get( PermissionGuard::class )
            );
        } );

        self::$container->set( WooCommerceGateway::class, function() {
            return new WooCommerceGateway();
        } );

        self::$container->set( CsvExportService::class, function( $c ) {
            return new CsvExportService(
                $c->get( LoteRepository::class ),
                $c->get( LoteItemRepository::class ),
                $c->get( PermissionGuard::class ),
                $c->get( LoteAuditRepository::class )
            );
        } );

        self::$container->set( TicketService::class, function() {
            return new TicketService();
        } );

        self::$container->set( ScannerService::class, function( $c ) {
            return new ScannerService(
                $c->get( LoteRepository::class ),
                $c->get( LoteItemRepository::class ),
                $c->get( WooCommerceGateway::class ),
                $c->get( LoteAuditRepository::class )
            );
        } );

        self::$container->set( DatabaseInstaller::class, function() {
            return new DatabaseInstaller();
        } );

        self::$container->set( OpenAiVisionService::class, function() {
            return new OpenAiVisionService();
        } );

        self::$container->set( MekanoExportService::class, function( $c ) {
            return new MekanoExportService(
                $c->get( LoteRepository::class ),
                $c->get( LoteItemRepository::class )
            );
        } );

        self::$container->set( PriceSuggestionService::class, function() {
            return new PriceSuggestionService();
        } );

        self::$container->set( CountAnomalyDetector::class, function() {
            return new CountAnomalyDetector();
        } );

        self::$container->set( WebhookDispatcher::class, function() {
            return new WebhookDispatcher();
        } );

        // 2. Registrar controladores / servicios de aplicación
        self::$container->set( LotePostType::class, function() {
            return new LotePostType();
        } );

        self::$container->set( SettingsController::class, function() {
            return new SettingsController();
        } );

        self::$container->set( MetaboxController::class, function( $c ) {
            return new MetaboxController(
                $c->get( LoteRepository::class ),
                $c->get( LoteItemRepository::class ),
                $c->get( PermissionGuard::class ),
                $c->get( CsvExportService::class ),
                $c->get( LoteAuditRepository::class ),
                $c->get( LoteCommentRepository::class ),
                $c->get( ChecklistService::class )
            );
        } );

        self::$container->set( ScannerViewController::class, function( $c ) {
            return new ScannerViewController(
                $c->get( LoteRepository::class ),
                $c->get( LoteItemRepository::class ),
                $c->get( PermissionGuard::class ),
                $c->get( OpenAiVisionService::class ),
                $c->get( MekanoExportService::class )
            );
        } );

        self::$container->set( PwaController::class, function() {
            return new PwaController();
        } );

        self::$container->set( TicketPrintController::class, function( $c ) {
            return new TicketPrintController(
                $c->get( LoteRepository::class ),
                $c->get( LoteItemRepository::class ),
                $c->get( PermissionGuard::class ),
                $c->get( TicketService::class ),
                $c->get( LoteAuditRepository::class )
            );
        } );

        self::$container->set( ScannerController::class, function( $c ) {
            return new ScannerController(
                $c->get( ScannerService::class ),
                $c->get( PermissionGuard::class )
            );
        } );

        self::$container->set( InvoiceVisionController::class, function( $c ) {
            return new InvoiceVisionController(
                $c->get( PermissionGuard::class )
            );
        } );

        self::$container->set( IntelligenceController::class, function( $c ) {
            return new IntelligenceController(
                $c->get( PriceSuggestionService::class ),
                $c->get( CountAnomalyDetector::class ),
                $c->get( PermissionGuard::class )
            );
        } );

        self::$container->set( SyncProcessor::class, function( $c ) {
            return new SyncProcessor(
                $c->get( LoteRepository::class ),
                $c->get( LoteItemRepository::class ),
                $c->get( WooCommerceGateway::class ),
                $c->get( LoteAuditRepository::class )
            );
        } );

        self::$container->set( SyncScheduler::class, function( $c ) {
            return new SyncScheduler(
                $c->get( LoteRepository::class ),
                $c->get( LoteItemRepository::class ),
                $c->get( LoteAuditRepository::class )
            );
        } );

        // Ejecutar los hooks de inicialización
        self::run();
    }

    private static function run() {
        // A) Inicializar Base de Datos (tal vez actualizar)
        if ( class_exists( 'WooCommerce' ) ) {
            self::$container->get( DatabaseInstaller::class )->maybe_update();
        }

        // B) Registrar Custom Post Type
        add_action( 'init', array( self::$container->get( LotePostType::class ), 'register' ) );

        // C) Registrar Ajustes del Plugin
        self::$container->get( SettingsController::class )->hook();

        // D) Registrar Metaboxes y Acciones de Administración
        self::$container->get( MetaboxController::class )->hook();

        // E) Registrar Vista de Escáner en el Frontend
        self::$container->get( ScannerViewController::class )->register();

        // E.2) PWA instalable (manifest + service worker)
        self::$container->get( PwaController::class )->register();

        // F) Registrar Impresión de Tickets / Etiquetas
        self::$container->get( TicketPrintController::class )->hook();

        // G) Registrar Rutas de la REST API
        add_action( 'rest_api_init', function() {
            self::$container->get( ScannerController::class )->register_routes();
            self::$container->get( InvoiceVisionController::class )->register_routes();
            self::$container->get( IntelligenceController::class )->register_routes();
        } );

        // H) Registrar Cola de Sincronización Asíncrona (Action Scheduler)
        self::$container->get( SyncProcessor::class )->hook();
        self::$container->get( SyncScheduler::class )->hook();

        // H.2) Webhooks salientes en transiciones de estado del lote
        self::$container->get( WebhookDispatcher::class )->hook();

        // I) Encolar estilos CSS de administración
        add_action( 'admin_enqueue_scripts', function() {
            $screen = get_current_screen();
            if ( $screen && $screen->post_type === 'lotes_ingreso' ) {
                wp_enqueue_style(
                    'mm-logistica-admin',
                    plugins_url( 'assets/css/admin.css', dirname( dirname( __DIR__ ) ) . '/megamundo-logistica.php' ),
                    array(),
                    MM_LOGISTICA_VERSION
                );
            }
        } );

        // K) AJAX handlers: comentarios y checklist del lote
        add_action( 'wp_ajax_mm_agregar_comentario', function() {
            self::$container->get( MetaboxController::class )->ajax_agregar_comentario();
        } );

        add_action( 'wp_ajax_mm_guardar_checklist', function() {
            self::$container->get( MetaboxController::class )->ajax_guardar_checklist();
        } );

        // J) Auditoría de cambio de estado
        add_action( 'transition_post_status', function( $new_status, $old_status, $post ) {
            if ( $post->post_type !== 'lotes_ingreso' ) {
                return;
            }

            // Evitar duplicaciones
            if ( $old_status === $new_status ) {
                return;
            }

            $audit_repo = self::$container->get( LoteAuditRepository::class );

            if ( $old_status === 'new' || $old_status === 'auto-draft' ) {
                $audit_repo->add_log( $post->ID, 'lote_creado', 'Lote creado con título: ' . $post->post_title );
                return;
            }

            switch ( $new_status ) {
                case 'mm_p_precios':
                    $audit_repo->add_log( $post->ID, 'conteo_cerrado', 'Conteo cerrado por el operario.' );
                    $audit_repo->add_log( $post->ID, 'enviado_a_precios', 'Lote enviado a departamento de precios.' );
                    break;
                case 'mm_p_aprobacion':
                    $audit_repo->add_log( $post->ID, 'enviado_a_aprobacion', 'Lote enviado a aprobación de jefatura.' );
                    break;
                case 'mm_cargado':
                    $audit_repo->add_log( $post->ID, 'lote_aprobado', 'Lote aprobado por jefatura.' );
                    break;
                case 'draft':
                    if ( $old_status !== 'new' && $old_status !== 'auto-draft' ) {
                        $audit_repo->add_log( $post->ID, 'ajuste_manual', 'Estado del lote revertido a Borrador/Conteo.' );
                    }
                    break;
            }
        }, 10, 3 );
    }

    public static function get_container() {
        return self::$container;
    }
}
