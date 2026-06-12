<?php
namespace MegaMundo\Logistica\Presentation\Front\Concerns;

if ( ! defined( 'ABSPATH' ) ) { exit; }

use MegaMundo\Logistica\Domain\Lote\LoteRepository;
use MegaMundo\Logistica\Domain\Lote\LoteItemRepository;
use MegaMundo\Logistica\Infrastructure\Security\PermissionGuard;
use MegaMundo\Logistica\Application\Lote\ChecklistService;
use MegaMundo\Logistica\Infrastructure\OpenAI\OpenAiVisionService;
use MegaMundo\Logistica\Application\Export\MekanoExportService;

trait LoteDetailTrait {
    private function render_checklist_block( $lote_id ) {
        $checklist = get_post_meta( $lote_id, '_mm_lote_checklist', true );
        $checklist = is_array( $checklist ) ? $checklist : array();
        $labels = ChecklistService::LABELS;
        ob_start(); ?>
        <div class="mm-detail-card">
            <div class="mm-detail-card-head"><h3>Checklist del lote</h3><span>Seguimiento interno</span></div>
            <ul class="mm-checklist-view">
                <?php foreach ( ChecklistService::ALL_ITEMS as $key ) : ?>
                    <li class="<?php echo ! empty( $checklist[ $key ] ) ? 'is-done' : 'is-pending'; ?>">
                        <span><?php echo ! empty( $checklist[ $key ] ) ? '✔' : '•'; ?></span>
                        <small><?php echo esc_html( $labels[ $key ] ?? $key ); ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php return ob_get_clean();
    }


    private function get_product_image_url( $product_id ) {
        $product_id = intval( $product_id );
        if ( $product_id <= 0 ) {
            return '';
        }

        $thumb_url = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
        if ( $thumb_url ) {
            return $thumb_url;
        }

        $attachments = get_children( array(
            'post_parent'    => $product_id,
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'numberposts'    => 1,
            'orderby'        => 'ID',
            'order'          => 'DESC',
        ) );

        if ( ! empty( $attachments ) ) {
            $attachment = reset( $attachments );
            $url = wp_get_attachment_image_url( $attachment->ID, 'thumbnail' );
            return $url ? $url : '';
        }

        return '';
    }

    private function render_lote_detail_app( $app, $lote_id ) {
        $lote = $this->lote_repo->find( $lote_id );
        if ( ! $lote ) {
            return $this->render_denied_app( 'El lote solicitado no existe.' );
        }
        $status = $this->lote_repo->get_status( $lote_id );
        $items  = $this->item_repo ? $this->item_repo->get_items( $lote_id ) : array();
        $totals = $this->item_repo ? $this->item_repo->get_financial_totals( $lote_id ) : array( 'costo_total' => 0, 'venta_total' => 0, 'margen_total' => 0, 'margen_pct' => 0 );
        $back_url = home_url( '/?mm_logistica_app=' . $app );
        $admin_url = admin_url( 'post.php?post=' . $lote_id . '&action=edit' );
        $can_edit_prices = $this->user_can_edit_prices_in_app( $lote_id );
        $can_send_to_approval = ( 'precios' === $app ) && $this->user_can_send_to_approval_in_app( $lote_id );
        $can_approve_load = (
            'jefatura' === $app
            && $this->permission_guard->is_admin()
            && in_array( $status, array( 'draft', 'publish', 'mm_p_precios', 'mm_p_aprobacion' ), true )
            && ! $this->lote_repo->is_synchronized( $lote_id )
        );
        $is_jefatura_price_review = ( 'jefatura' === $app && $can_edit_prices );
        $price_nonce = wp_create_nonce( 'mm_app_precios_' . $lote_id );
        ob_start(); ?>
        <main class="mm-platform-shell">
            <?php echo $this->render_sidebar( $app ); ?>
            <section class="mm-platform-main">
                <header class="mm-platform-header mm-detail-header">
                    <div>
                        <span class="mm-eyebrow"><?php echo esc_html( 'precios' === $app ? 'Panel de Precios' : 'Panel de Jefatura' ); ?></span>
                        <h1><?php echo esc_html( get_the_title( $lote_id ) ); ?></h1>
                        <p>Vista interna del lote dentro de la plataforma. Aquí puedes revisar el conteo, precios y estado sin salir del panel.</p>
                    </div>
                    <div class="mm-detail-actions">
                        <a class="mm-header-link" href="<?php echo esc_url( $back_url ); ?>">← Volver</a>
                        <a class="mm-secondary-action" href="<?php echo esc_url( $admin_url ); ?>">Abrir editor completo</a>
                    </div>
                </header>

                <div class="mm-role-summary-grid mm-role-summary-grid-4">
                    <div class="mm-role-summary-card"><small>Estado</small><strong><?php echo esc_html( $this->status_label( $status ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>SKUs</small><strong><?php echo esc_html( count( $items ) ); ?></strong></div>
                    <div class="mm-role-summary-card"><small>Unidades</small><strong><?php echo esc_html( $this->item_repo ? $this->item_repo->sum_total_units( $lote_id ) : 0 ); ?></strong></div>
                    <div class="mm-role-summary-card is-success"><small>Venta propuesta</small><strong>$<?php echo esc_html( number_format( (float) $totals['venta_total'], 0, ',', '.' ) ); ?></strong></div>
                </div>

                <section class="mm-platform-section">
                    <div class="mm-section-head">
                        <h2><?php echo $is_jefatura_price_review ? 'Revisión de precio final' : ( $can_edit_prices ? 'Liquidar costos y precios' : 'Productos del lote' ); ?></h2>
                        <span><?php echo $is_jefatura_price_review ? 'Si jefatura no está de acuerdo, puede ajustar el precio que quedará en WooCommerce.' : ( $can_edit_prices ? 'Edita aquí. No necesitas entrar a WooCommerce.' : 'Lote #' . esc_html( $lote_id ) ); ?></span>
                    </div>
                    <div class="mm-detail-card mm-table-card">
                        <?php if ( empty( $items ) ) : ?>
                            <div class="mm-empty-state">Este lote aún no tiene productos registrados.</div>
                        <?php else : ?>
                            <form id="mm-app-precios-form" data-lote-id="<?php echo esc_attr( $lote_id ); ?>" data-nonce="<?php echo esc_attr( $price_nonce ); ?>">
                                <div class="mm-table-scroll">
                                    <table class="mm-detail-table mm-price-table">
                                        <thead><tr><th>Foto</th><th>SKU</th><th>Producto</th><th>Cantidad</th><th>Costo unitario</th><th><?php echo $is_jefatura_price_review ? 'Precio final' : 'Precio propuesto'; ?></th><th>Margen</th><th>Sync</th></tr></thead>
                                        <tbody>
                                        <?php foreach ( $items as $item ) :
                                            $producto_id = (int) $item->producto_id;
                                            $nombre = $producto_id ? get_the_title( $producto_id ) : '';
                                            if ( ! $nombre ) { $nombre = 'Producto sin nombre'; }
                                            $thumb_url = $this->get_product_image_url( $producto_id );
                                            $costo = (float) $item->costo_ia;
                                            $precio = (float) $item->precio_propuesto;
                                            $margen = $precio - $costo;
                                        ?>
                                            <tr data-item-id="<?php echo esc_attr( (int) $item->id ); ?>">
                                                <td>
                                                    <?php if ( $thumb_url ) : ?>
                                                        <img class="mm-product-thumb" src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $nombre ); ?>" />
                                                    <?php else : ?>
                                                        <span class="mm-product-thumb mm-product-thumb-empty">Sin foto</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo esc_html( $item->sku ); ?></td>
                                                <td><?php echo $this->mm_render_bodega_img_nombre( $product, $nombre ); ?></td>
                                                <td><?php echo esc_html( (int) $item->cantidad_contada ); ?></td>
                                                <td>
                                                    <?php if ( $can_edit_prices && ! $is_jefatura_price_review ) : ?>
                                                        <input class="mm-price-input mm-costo-input" type="number" min="0" step="0.01" name="items[<?php echo esc_attr( (int) $item->id ); ?>][costo_ia]" value="<?php echo esc_attr( $costo ); ?>" />
                                                    <?php else : ?>
                                                        <span class="mm-readonly-money">$<?php echo esc_html( number_format( $costo, 0, ',', '.' ) ); ?></span>
                                                        <?php if ( $is_jefatura_price_review ) : ?>
                                                            <input type="hidden" name="items[<?php echo esc_attr( (int) $item->id ); ?>][costo_ia]" value="<?php echo esc_attr( $costo ); ?>" />
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ( $can_edit_prices ) : ?>
                                                        <input class="mm-price-input mm-precio-input" type="number" min="0" step="0.01" name="items[<?php echo esc_attr( (int) $item->id ); ?>][precio_propuesto]" value="<?php echo esc_attr( $precio ); ?>" />
                                                    <?php else : ?>
                                                        $<?php echo esc_html( number_format( $precio, 0, ',', '.' ) ); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="mm-margen-cell"><?php echo $precio > 0 ? esc_html( '$' . number_format( $margen, 0, ',', '.' ) ) : '<span class="mm-text-danger">Sin precio</span>'; ?></td>
                                                <td><?php echo ! empty( $item->synced_at ) ? '<span class="mm-badge-soft">OK</span>' : '<span class="mm-badge-soft mm-badge-warn">Pendiente</span>'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if ( $can_edit_prices ) : ?>
                                    <div class="mm-price-actions">
                                        <button type="submit" class="mm-primary-action"><?php echo $is_jefatura_price_review ? 'Guardar precio final' : 'Guardar precios'; ?></button>
                                        <?php if ( $can_send_to_approval ) : ?>
                                            <button type="button" id="mm-app-enviar-aprobacion" class="mm-secondary-action">Enviar a aprobación</button>
                                        <?php endif; ?>
                                        <?php if ( $can_approve_load ) : ?>
                                            <button type="button" id="mm-app-aprobar-cargar" class="mm-success-action">Autorizar precios y cargar al sistema</button>
                                        <?php endif; ?>
                                    </div>
                                    <div id="mm-app-precios-msg" class="mm-message" hidden></div>
                                <?php else : ?>
                                    <?php if ( $can_approve_load ) : ?>
                                        <div class="mm-price-actions">
                                            <button type="button" id="mm-app-aprobar-cargar" class="mm-success-action">Autorizar precios y cargar al sistema</button>
                                        </div>
                                        <div id="mm-app-precios-msg" class="mm-message" hidden></div>
                                    <?php else : ?>
                                        <div class="mm-ia-note">Este lote no está disponible para edición. Puede estar cargado, sincronizado o no corresponder a tu rol.</div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <div class="mm-detail-grid">
                    <?php echo $this->render_checklist_block( $lote_id ); ?>
                    <div class="mm-detail-card">
                        <div class="mm-detail-card-head"><h3>Resumen financiero</h3><span>Revisión rápida</span></div>
                        <ul class="mm-summary-list">
                            <li><span>Costo total estimado</span><strong>$<?php echo esc_html( number_format( (float) $totals['costo_total'], 0, ',', '.' ) ); ?></strong></li>
                            <li><span>Venta total propuesta</span><strong>$<?php echo esc_html( number_format( (float) $totals['venta_total'], 0, ',', '.' ) ); ?></strong></li>
                            <li><span>Margen estimado</span><strong>$<?php echo esc_html( number_format( (float) $totals['margen_total'], 0, ',', '.' ) ); ?></strong></li>
                            <li><span>% margen</span><strong><?php echo esc_html( number_format( (float) $totals['margen_pct'], 1, ',', '.' ) ); ?>%</strong></li>
                        </ul>
                    </div>
                </div>
            </section>
        </main>
        <?php return ob_get_clean();
    }
}
