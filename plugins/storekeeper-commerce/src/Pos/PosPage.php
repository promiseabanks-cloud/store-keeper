<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Pos;

use StoreKeeper\Commerce\Core\Brand;

/**
 * Serves the POS app at `/pos/` (§15.1).
 *
 * The app is served outside the theme so it keeps its own standalone layout and
 * can be installed from the browser. It is plain HTML/JS against the same REST
 * API; nothing here contains business rules.
 */
final class PosPage
{
    public const QUERY_VAR = 'sk_pos';

    public static function register(): void
    {
        add_action('init', [self::class, 'rules']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;

            return $vars;
        });
        add_action('template_redirect', [self::class, 'render']);
    }

    public static function rules(): void
    {
        add_rewrite_rule('^pos/?$', 'index.php?' . self::QUERY_VAR . '=app', 'top');
        add_rewrite_rule('^pos/manifest\.webmanifest$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top');
        add_rewrite_rule('^pos/sw\.js$', 'index.php?' . self::QUERY_VAR . '=sw', 'top');
        add_rewrite_rule('^pos/icon\.svg$', 'index.php?' . self::QUERY_VAR . '=icon', 'top');
    }

    public static function render(): void
    {
        $view = get_query_var(self::QUERY_VAR);

        if (!is_string($view) || $view === '') {
            return;
        }

        if ($view === 'manifest') {
            header('Content-Type: application/manifest+json; charset=utf-8');
            echo json_encode([
                'name' => get_bloginfo('name') . ' POS',
                'short_name' => 'POS',
                'start_url' => home_url('/pos/'),
                'scope' => home_url('/pos/'),
                'display' => 'standalone',
                'background_color' => '#0f172a',
                'theme_color' => '#0f172a',
                'icons' => [[
                    'src' => home_url('/pos/icon.svg'),
                    'sizes' => '192x192 512x512',
                    'type' => 'image/svg+xml',
                    'purpose' => 'any',
                ]],
            ], JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($view === 'sw') {
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: /pos/');
            echo "const CACHE = 'sk-pos-v1';\n"
                . "self.addEventListener('install', (e) => { e.waitUntil(caches.open(CACHE).then((c) => c.addAll(['/pos/']))); });\n"
                . "self.addEventListener('activate', (e) => { e.waitUntil(self.clients.claim()); });\n"
                . "self.addEventListener('fetch', (e) => {\n"
                . "  const url = new URL(e.request.url);\n"
                . "  if (e.request.method !== 'GET' || url.pathname.includes('/wp-json/')) { return; }\n"
                . "  e.respondWith(caches.match(e.request).then((hit) => hit || fetch(e.request).then((res) => {\n"
                . "    const copy = res.clone(); caches.open(CACHE).then((c) => c.put(e.request, copy)); return res;\n"
                . "  }).catch(() => caches.match('/pos/'))));\n"
                . "});\n";
            exit;
        }

        if ($view === 'icon') {
            header('Content-Type: image/svg+xml');
            echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
                . '<rect width="512" height="512" rx="96" fill="#0f172a"/>'
                . '<text x="256" y="310" font-family="system-ui,sans-serif" font-size="220" font-weight="700" fill="#f8fafc" text-anchor="middle">SK</text>'
                . '</svg>';
            exit;
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        $pluginUrl = plugin_dir_url(dirname(__DIR__, 2) . '/storekeeper-commerce.php');
        $config = [
            'restUrl' => rest_url(Brand::REST_NAMESPACE),
            'nonce' => wp_create_nonce('wp_rest'),
            'storeName' => get_bloginfo('name'),
            'currency' => Brand::CURRENCY,
            'loggedIn' => is_user_logged_in(),
            'adminUrl' => admin_url(),
        ];

        include dirname(__DIR__, 2) . '/templates/pos/app.php';
    }
}
