<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('responsive-embeds');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('editor-styles');
    add_theme_support('wp-block-styles');
});

add_action('wp_enqueue_scripts', static function (): void {
    $deps = wp_style_is('storekeeper-storefront', 'registered') ? ['storekeeper-storefront'] : [];

    wp_enqueue_style(
        'storekeeper-theme',
        get_stylesheet_uri(),
        $deps,
        wp_get_theme()->get('Version') ?: '0.1.0'
    );
});

/** The shop URL, or the home page when the commerce plugin is not active. */
function skf_shop_url(): string
{
    return home_url('/shop/');
}

/** The signed-in storefront customer, or null for a guest. */
function skf_customer(): ?array
{
    if (!class_exists(\StoreKeeper\Commerce\Storefront\Storefront::class)) {
        return null;
    }

    return \StoreKeeper\Commerce\Storefront\Storefront::customer();
}

/** Items in this visitor's cart; 0 when the commerce plugin is not active. */
function skf_cart_count(): int
{
    if (!class_exists(\StoreKeeper\Commerce\Storefront\CartSession::class)) {
        return 0;
    }

    return \StoreKeeper\Commerce\Storefront\CartSession::count();
}

/** @return list<array{name:string,url:string,slug:string,count:int}> */
function skf_categories(): array
{
    if (!class_exists(\StoreKeeper\Commerce\Storefront\Storefront::class)) {
        return [];
    }

    return \StoreKeeper\Commerce\Storefront\Storefront::view()->categories();
}

function skf_initials(): string
{
    $name = trim((string) get_bloginfo('name'));
    $words = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }

    return $initials === '' ? 'SK' : $initials;
}
