<?php
/**
 * The POS app shell (§15.1). Everything is rendered by assets/pos/pos.js; this
 * file only hands it the REST root, a staff nonce and the store name.
 *
 * @var array<string,mixed> $config
 * @var string $pluginUrl
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <title><?php echo esc_html((string) $config['storeName']); ?> POS</title>
    <link rel="manifest" href="<?php echo esc_url(home_url('/pos/manifest.webmanifest')); ?>">
    <link rel="icon" href="<?php echo esc_url(home_url('/pos/icon.svg')); ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?php echo esc_url($pluginUrl . 'assets/pos/pos.css'); ?>">
</head>
<body>
<div id="app" class="pos-app">
    <header class="pos-header">
        <div>
            <strong class="pos-store"><?php echo esc_html((string) $config['storeName']); ?></strong>
            <span id="pos-device" class="pos-muted"></span>
        </div>
        <div class="pos-header-right">
            <span id="pos-sync" class="pos-badge">Starting…</span>
            <button type="button" id="pos-menu" class="pos-ghost">Menu</button>
        </div>
    </header>
    <main id="screen" class="pos-main">
        <p class="pos-muted">Loading…</p>
    </main>
    <nav id="tabs" class="pos-tabs" hidden>
        <button type="button" data-tab="sell" class="is-active">Sell</button>
        <button type="button" data-tab="held">Held</button>
        <button type="button" data-tab="cashup">Cash-up</button>
        <button type="button" data-tab="sales">Sales</button>
    </nav>
</div>
<script>
window.SK_POS = <?php echo wp_json_encode($config, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="<?php echo esc_url($pluginUrl . 'assets/pos/pos.js'); ?>" defer></script>
</body>
</html>
