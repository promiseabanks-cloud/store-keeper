<?php

/**
 * Plugin Name: Store Keeper Loader
 * Description: Must-use plugin that enforces the environment and load order for Store Keeper.
 * Version: 0.1.0
 * Author: Store Keeper
 * License: Proprietary
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Environment guards (§6.2, §23). The engine's correctness depends on
 * multisite, a modern PHP and a real (non-shared) host with cron and Redis.
 */
$storekeeperProblems = [];

if (PHP_VERSION_ID < 80200) {
    $storekeeperProblems[] = sprintf('PHP 8.2 or newer is required (this host runs %s).', PHP_VERSION);
}

if (!is_multisite()) {
    $storekeeperProblems[] = 'WordPress multisite is required: each merchant is a subsite.';
}

if (!defined('WSP_ENCRYPTION_KEY') || strlen((string) constant('WSP_ENCRYPTION_KEY')) < 32) {
    $storekeeperProblems[] = 'The WSP_ENCRYPTION_KEY constant must be set in wp-config.php to encrypt gateway and messaging secrets at rest.';
}

if (!function_exists('as_enqueue_async_action')) {
    $storekeeperProblems[] = 'Action Scheduler is not loaded; background jobs (messages, imports, hold expiry) cannot run.';
}

if (!defined('WP_CLI') && !defined('DOING_CRON') && function_exists('wp_using_ext_object_cache')
    && !wp_using_ext_object_cache()) {
    $storekeeperProblems[] = 'A persistent object cache (Redis) is required in production.';
}

if ($storekeeperProblems !== []) {
    if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
        foreach ($storekeeperProblems as $problem) {
            \WP_CLI::warning($problem);
        }
    } else {
        add_action('admin_notices', static function () use ($storekeeperProblems): void {
            if (!current_user_can('manage_network_options')) {
                return;
            }

            echo '<div class="notice notice-error"><p><strong>Store Keeper is not ready:</strong></p><ul>';
            foreach ($storekeeperProblems as $problem) {
                echo '<li>' . esc_html($problem) . '</li>';
            }
            echo '</ul></div>';
        });
    }
}

unset($storekeeperProblems);
