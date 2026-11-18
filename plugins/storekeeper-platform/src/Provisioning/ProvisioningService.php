<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Provisioning;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Dates;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Platform\Applications\ApplicationService;
use StoreKeeper\Platform\Audit\PlatformAudit;
use StoreKeeper\Platform\Plans\PlanGate;
use StoreKeeper\Platform\Plans\PlanRepository;
use StoreKeeper\Platform\Tenants\TenantRepository;

/**
 * Turn an approved application into a working store (§17.2).
 *
 * Every step is logged in `platform_jobs`; if the store seed fails the tenant
 * stays `provisioning`, which is what the Owner sees in the console, and no
 * half-created store is presented as live.
 */
final class ProvisioningService
{
    public function __construct(
        private readonly Connection $db,
        private readonly TenantRepository $tenants,
        private readonly ApplicationService $applications,
        private readonly PlanRepository $plans,
        private readonly PlanGate $gate,
        private readonly SiteProvisioner $sites,
        private readonly PlatformAudit $audit,
        private readonly Clock $clock,
        private readonly int $trialDays = 14,
    ) {
    }

    /**
     * @param array<string,mixed> $application
     * @param array<string,mixed> $options slug, owner_user_id, store_mode
     * @return array<string,mixed> the tenant row
     */
    public function provision(array $application, int $planId, array $options, int $actorId): array
    {
        $status = (string) ($application['status'] ?? '');
        if (!in_array($status, ['approved', 'contacted', 'new'], true)) {
            throw new ValidationFailed('Approve the application before provisioning it.', [
                'status' => 'Application is ' . $status . '.',
            ]);
        }

        $plan = $this->plans->find($planId);
        if ($plan === null || (int) $plan['is_active'] !== 1) {
            throw new ValidationFailed('Choose an active plan.', ['plan_id' => 'Unknown or inactive plan.']);
        }

        $ownerUserId = (int) ($options['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            throw new ValidationFailed('Choose the WordPress user who owns this store.', ['owner_user_id' => 'Required.']);
        }

        $mode = (string) ($options['store_mode'] ?? 'both');
        if (!in_array($mode, ['retail', 'wholesale', 'both'], true)) {
            throw new ValidationFailed('Unknown store mode.', ['store_mode' => 'retail, wholesale or both.']);
        }

        $placeholderTenant = ['plan_id' => $planId];
        if (!$this->gate->allowsStoreMode($placeholderTenant, $mode)) {
            throw new ValidationFailed('This plan does not include that store mode.', ['store_mode' => 'Not on this plan.']);
        }

        $businessName = (string) $application['business_name'];
        $slug = $this->slug((string) ($options['slug'] ?? $businessName));

        $this->job(null, (int) $application['id'], 'site', 'started', $slug);
        $blogId = $this->sites->createSite($slug, $businessName, $ownerUserId);
        $this->job(null, (int) $application['id'], 'site', 'done', 'blog ' . $blogId);

        $now = $this->clock->nowString();
        $tenantId = $this->tenants->create([
            'uuid' => Uuid::v4(),
            'blog_id' => $blogId,
            'application_id' => (int) $application['id'],
            'business_name' => $businessName,
            'owner_user_id' => $ownerUserId,
            'plan_id' => $planId,
            'status' => 'trial',
            'store_mode' => $mode,
            'primary_domain' => null,
            'trial_ends_at' => Dates::addMinutes($now, $this->trialDays * 1440),
            'grace_ends_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->job($tenantId, (int) $application['id'], 'tenant', 'done', 'tenant row created');

        try {
            $this->job($tenantId, (int) $application['id'], 'store', 'started', 'migrations and seed');
            $this->sites->seedStore($blogId, $plan, $mode);
            $this->job($tenantId, (int) $application['id'], 'store', 'done', 'store seeded');
        } catch (\Throwable $error) {
            $this->job($tenantId, (int) $application['id'], 'store', 'failed', mb_substr($error->getMessage(), 0, 250));
            $this->tenants->update($tenantId, ['status' => 'provisioning']);
            throw $error;
        }

        $this->sites->finish($blogId, $ownerUserId, $businessName);
        $this->job($tenantId, (int) $application['id'], 'owner', 'done', 'owner attached');

        // No WhatsApp/SMS adapter exists yet, so the welcome message is the one
        // step that is honestly skipped rather than faked (Q-005, Q-006).
        $this->job($tenantId, (int) $application['id'], 'welcome', 'skipped', 'No messaging provider configured');

        $this->applications->updateStatus((int) $application['id'], 'converted', (string) ($application['owner_notes'] ?? ''), $actorId);
        $this->audit->log($actorId, 'tenant.provisioned', $tenantId, (int) $application['id'], null, [
            'blog_id' => $blogId,
            'plan_id' => $planId,
        ]);

        return $this->tenants->find($tenantId) ?? [];
    }

    /**
     * The whole trace for a tenant, including the site step logged before the
     * tenant row existed (it is linked by application).
     *
     * @return list<array<string,mixed>>
     */
    public function jobs(int $tenantId): array
    {
        $tenant = $this->tenants->find($tenantId);
        $applicationId = (int) ($tenant['application_id'] ?? 0);

        return $this->db->select(
            sprintf(
                'SELECT * FROM %splatform_jobs WHERE tenant_id = ? OR application_id = ? ORDER BY id ASC',
                $this->db->networkPrefix()
            ),
            [$tenantId, $applicationId]
        );
    }

    private function job(?int $tenantId, int $applicationId, string $step, string $status, ?string $message): void
    {
        $this->db->insert($this->db->networkPrefix() . 'platform_jobs', [
            'tenant_id' => $tenantId,
            'application_id' => $applicationId,
            'step' => $step,
            'status' => $status,
            'message' => $message,
            'created_at' => $this->clock->nowString(),
        ]);
    }

    private function slug(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? '', '-'));
        $slug = substr($slug, 0, 40);

        return $slug === '' ? 'store-' . substr(bin2hex(random_bytes(3)), 0, 6) : $slug;
    }
}
