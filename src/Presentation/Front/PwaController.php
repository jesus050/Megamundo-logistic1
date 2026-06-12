<?php
namespace MegaMundo\Logistica\Presentation\Front;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Convierte la app standalone (?mm_logistica_app=...) en una PWA instalable.
 *
 * El manifest y el service worker se sirven desde la raíz del sitio vía
 * query string (/?mm_pwa=manifest, /?mm_pwa=sw) para que el scope del
 * service worker cubra las rutas de la app, que viven en la raíz.
 *
 * El service worker es deliberadamente conservador: solo cachea los
 * assets estáticos del plugin (cache-first, busteado por ?ver=). Todo lo
 * demás (AJAX, REST, HTML) pasa directo a la red.
 */
class PwaController {

    public function register() {
        add_action( 'template_redirect', array( $this, 'serve_pwa_endpoints' ), 0 );
        add_action( 'wp_head', array( $this, 'print_pwa_meta' ) );
        add_action( 'wp_footer', array( $this, 'print_sw_registration' ) );
    }

    private function is_app_request() {
        return ! empty( $_GET['mm_logistica_app'] );
    }

    public function serve_pwa_endpoints() {
        if ( ! isset( $_GET['mm_pwa'] ) ) {
            return;
        }

        $endpoint = sanitize_key( wp_unslash( $_GET['mm_pwa'] ) );

        if ( 'manifest' === $endpoint ) {
            $this->serve_manifest();
        }

        if ( 'sw' === $endpoint ) {
            $this->serve_service_worker();
        }
    }

    private function serve_manifest() {
        status_header( 200 );
        header( 'Content-Type: application/manifest+json; charset=utf-8' );

        echo wp_json_encode( array(
            'name'             => 'MegaMundo Logística',
            'short_name'       => 'MegaMundo',
            'description'      => 'Control logístico e inventario de bodega.',
            'start_url'        => home_url( '/?mm_logistica_app=dashboard' ),
            'scope'            => home_url( '/' ),
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#0f172a',
            'theme_color'      => '#1d4ed8',
            'icons'            => array(
                array(
                    'src'   => $this->asset_url( 'assets/img/pwa-icon-192.png' ),
                    'sizes' => '192x192',
                    'type'  => 'image/png',
                ),
                array(
                    'src'     => $this->asset_url( 'assets/img/pwa-icon-512.png' ),
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'any maskable',
                ),
            ),
        ) );
        exit;
    }

    private function serve_service_worker() {
        status_header( 200 );
        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Cache-Control: no-cache' );

        $cache_name   = 'mm-logistica-' . MM_LOGISTICA_VERSION;
        $asset_prefix = $this->asset_url( 'assets/' );
        ?>
const CACHE = <?php echo wp_json_encode( $cache_name ); ?>;
const ASSET_PREFIX = <?php echo wp_json_encode( $asset_prefix ); ?>;

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (k) { return k.indexOf('mm-logistica-') === 0 && k !== CACHE; })
                    .map(function (k) { return caches.delete(k); })
            );
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    if (event.request.method !== 'GET' || event.request.url.indexOf(ASSET_PREFIX) !== 0) {
        return; // red normal para AJAX, REST y HTML
    }
    event.respondWith(
        caches.open(CACHE).then(async function (cache) {
            const hit = await cache.match(event.request);
            if (hit) { return hit; }
            const res = await fetch(event.request);
            if (res && res.ok) { cache.put(event.request, res.clone()); }
            return res;
        })
    );
});
        <?php
        exit;
    }

    public function print_pwa_meta() {
        if ( ! $this->is_app_request() ) {
            return;
        }
        ?>
        <link rel="manifest" href="<?php echo esc_url( home_url( '/?mm_pwa=manifest' ) ); ?>">
        <meta name="theme-color" content="#1d4ed8">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="MegaMundo">
        <link rel="apple-touch-icon" href="<?php echo esc_url( $this->asset_url( 'assets/img/pwa-icon-192.png' ) ); ?>">
        <?php
    }

    public function print_sw_registration() {
        if ( ! $this->is_app_request() ) {
            return;
        }
        ?>
        <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register(<?php echo wp_json_encode( home_url( '/?mm_pwa=sw' ) ); ?>).catch(function () {});
        }
        </script>
        <?php
    }

    private function asset_url( $relative ) {
        $plugin_file = dirname( dirname( dirname( __DIR__ ) ) ) . '/megamundo-logistica.php';
        return plugins_url( $relative, $plugin_file );
    }
}
