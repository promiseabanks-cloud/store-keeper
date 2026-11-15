<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Cli;

use StoreKeeper\Commerce\Core\Brand;
use StoreKeeper\Commerce\Core\Installer;
use StoreKeeper\Commerce\Core\Plugin;
use StoreKeeper\Commerce\Core\SchemaMigrator;

/**
 * `wp storekeeper migrate [--network]` (§7.3).
 *
 * --network runs the per-store migrations for every subsite, switching blog
 * context in a try/finally so a failure never leaves the wrong blog current (§6.4).
 */
final class MigrateCommand
{
    /**
     * Run pending Store Keeper migrations.
     *
     * ## OPTIONS
     *
     * [--network]
     * : Run for every site on the network, not just the current one.
     *
     * ## EXAMPLES
     *
     *     wp storekeeper migrate
     *     wp storekeeper migrate --network
     *
     * @param list<string> $args
     * @param array<string,mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        if (empty($assocArgs['network'])) {
            $applied = $this->migrateCurrentSite();
            \WP_CLI::success(sprintf(
                'Store schema up to date (%d store migration(s), %d network migration(s) applied).',
                count($applied['store']),
                count($applied['network'])
            ));

            return;
        }

        $sites = get_sites(['number' => 0]);
        $failures = 0;

        foreach ($sites as $site) {
            $blogId = (int) $site->blog_id;
            switch_to_blog($blogId);
            Plugin::reset();

            try {
                $applied = $this->migrateCurrentSite();
                // Roles and capabilities are per site in multisite (§6.4).
                Installer::registerRoles();
                \WP_CLI::log(sprintf('Site %d: %d store migration(s) applied.', $blogId, count($applied['store'])));
            } catch (\Throwable $error) {
                $failures++;
                \WP_CLI::warning(sprintf('Site %d failed: %s', $blogId, $error->getMessage()));
            } finally {
                restore_current_blog();
                Plugin::reset();
            }
        }

        if ($failures > 0) {
            \WP_CLI::error(sprintf('%d site(s) failed to migrate.', $failures));
        }

        \WP_CLI::success(sprintf('Migrated %d site(s).', count($sites)));
    }

    /** @return array{store:list<string>,network:list<string>} */
    private function migrateCurrentSite(): array
    {
        $container = Plugin::container();

        return (new SchemaMigrator($container->get(\StoreKeeper\Commerce\Core\Database\Connection::class), Plugin::migrationsRoot()))->migrateAll();
    }
}
