<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Provisioning;

/**
 * The hosting half of provisioning (§17.2), isolated behind an interface so the
 * tenant workflow can be tested without WordPress.
 */
interface SiteProvisioner
{
    /** Create the subsite and return its blog id. */
    public function createSite(string $slug, string $title, int $ownerUserId): int;

    /** Run the store migrations, seed defaults and activate the theme. */
    public function seedStore(int $blogId, array $plan, string $storeMode): void;

    /** Record the site owner and finish the first-run checklist. */
    public function finish(int $blogId, int $ownerUserId, string $businessName): void;
}
