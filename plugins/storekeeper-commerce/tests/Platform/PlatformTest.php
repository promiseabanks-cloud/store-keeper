<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Platform;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Audit\DatabaseAuditLogger;
use StoreKeeper\Commerce\Core\FrozenClock;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Tests\Support\TestDatabase;
use StoreKeeper\Platform\Applications\ApplicationService;
use StoreKeeper\Platform\Audit\PlatformAudit;
use StoreKeeper\Platform\Billing\BillingService;
use StoreKeeper\Platform\Plans\PlanGate;
use StoreKeeper\Platform\Plans\PlanLimit;
use StoreKeeper\Platform\Plans\PlanRepository;
use StoreKeeper\Platform\Plans\PlanService;
use StoreKeeper\Platform\Plans\UsageRepository;
use StoreKeeper\Platform\Provisioning\ProvisioningService;
use StoreKeeper\Platform\Provisioning\SiteProvisioner;
use StoreKeeper\Platform\Tenants\TenantRepository;

/**
 * The platform engine (§17.2–§17.4): plans and their gate, applications,
 * provisioning, invoices, the dunning ladder and verified webhook events.
 */
final class PlatformTest extends TestCase
{
    private FrozenClock $clock;

    private PlanRepository $plans;

    private PlanService $planService;

    private PlanGate $gate;

    private UsageRepository $usage;

    private ApplicationService $applications;

    private TenantRepository $tenants;

    private BillingService $billing;

    private FakeProvisioner $provisioner;

    private ProvisioningService $provisioning;

    protected function setUp(): void
    {
        $db = TestDatabase::fresh();
        $this->clock = new FrozenClock();
        $audit = new PlatformAudit($db, $this->clock);

        $this->plans = new PlanRepository($db);
        $this->planService = new PlanService($this->plans, $audit, $this->clock);
        $this->gate = new PlanGate($this->plans);
        $this->usage = new UsageRepository($db, $this->clock);
        $this->applications = new ApplicationService($db, $audit, $this->clock);
        $this->tenants = new TenantRepository($db, $this->clock);
        $this->billing = new BillingService($db, $this->tenants, $audit, $this->clock, 7, 14);
        $this->provisioner = new FakeProvisioner();
        $this->provisioning = new ProvisioningService(
            $db,
            $this->tenants,
            $this->applications,
            $this->plans,
            $this->gate,
            $this->provisioner,
            $audit,
            $this->clock,
            14
        );
    }

    public function testAPlanCarriesLimitsAndFeaturesAndTheGateEnforcesThem(): void
    {
        $plan = $this->thisCreatePlan(['variants' => 100, 'pos_devices' => 1], ['pos_offline' => true]);
        $tenant = ['plan_id' => (int) $plan['id']];

        self::assertSame(100, $this->gate->limit($tenant, 'variants'));
        self::assertTrue($this->gate->feature($tenant, 'pos_offline'));
        self::assertFalse($this->gate->feature($tenant, 'multi_location'));

        $this->gate->assertCanAdd($tenant, 'variants', 99);

        $this->expectException(PlanLimit::class);

        $this->gate->assertCanAdd($tenant, 'variants', 100);
    }

    public function testAZeroLimitMeansUncapped(): void
    {
        $plan = $this->thisCreatePlan(['variants' => 0], []);
        $tenant = ['plan_id' => (int) $plan['id']];

        $this->gate->assertCanAdd($tenant, 'variants', 5000);
        self::assertSame(0, $this->gate->limit($tenant, 'variants'));
    }

    public function testUsageCountersAccumulatePerMonth(): void
    {
        $this->usage->increment(4, 'wa_messages', 3);
        $this->usage->increment(4, 'wa_messages', 2);

        self::assertSame(5, $this->usage->value(4, 'wa_messages'));
        self::assertSame(0, $this->usage->value(4, 'wa_messages', '2020-01'));
    }

    public function testAnApplicationIsProvisionedIntoATrialTenant(): void
    {
        $plan = $this->thisCreatePlan(['variants' => 1000, 'staff_seats' => 3], ['pos_offline' => true]);
        $application = $this->applications->submit([
            'business_name' => 'Ada Fabrics',
            'contact_name' => 'Ada Obi',
            'phone' => '08031234567',
            'email' => 'ada@example.test',
            'sells_to' => 'both',
        ]);

        self::assertSame('new', $application['status']);
        $this->applications->updateStatus((int) $application['id'], 'approved', 'Looks good', 1);

        $tenant = $this->provisioning->provision(
            $this->applications->find((int) $application['id']) ?? [],
            (int) $plan['id'],
            ['owner_user_id' => 12, 'store_mode' => 'both', 'slug' => 'ada-fabrics'],
            1
        );

        self::assertSame('trial', $tenant['status']);
        self::assertSame(77, (int) $tenant['blog_id']);
        self::assertSame('converted', $this->applications->find((int) $application['id'])['status']);
        self::assertSame('ada-fabrics', $this->provisioner->slug);
        self::assertSame([77], $this->provisioner->seeded);

        $jobs = $this->provisioning->jobs((int) $tenant['id']);
        self::assertSame(
            ['site', 'site', 'tenant', 'store', 'store', 'owner', 'welcome'],
            array_column($jobs, 'step'),
            'Both the start and finish of each step are recorded'
        );
        self::assertSame('done', $jobs[4]['status'], 'The store seed finished');
        self::assertSame('skipped', end($jobs)['status'], 'No messaging provider is faked');
    }

    public function testAFailedSeedLeavesTheTenantInProvisioning(): void
    {
        $plan = $this->thisCreatePlan(['variants' => 10], []);
        $application = $this->applications->submit([
            'business_name' => 'Broken Store',
            'contact_name' => 'Owner',
            'phone' => '08031234568',
            'sells_to' => 'retail',
        ]);
        $this->applications->updateStatus((int) $application['id'], 'approved', null, 1);

        $this->provisioner->fail = true;

        try {
            $this->provisioning->provision(
                $this->applications->find((int) $application['id']) ?? [],
                (int) $plan['id'],
                ['owner_user_id' => 3],
                1
            );
            self::fail('The seed should have failed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Seed failed', $error->getMessage());
        }

        $tenant = $this->tenants->all()[0];
        self::assertSame('provisioning', $tenant['status']);
        $jobs = $this->provisioning->jobs((int) $tenant['id']);
        self::assertSame('failed', end($jobs)['status']);
    }

    public function testAnInvoiceCanBePaidManuallyOrByAVerifiedEvent(): void
    {
        $tenant = $this->tenant();
        $invoice = $this->billing->createInvoice((int) $tenant['id'], 'setup', 'Setup fee', 5000000, null, 1);
        $payable = $this->billing->createInvoice((int) $tenant['id'], 'subscription', 'November', 1000000, null, 1, 'ref-123');

        $this->billing->markPaid((int) $invoice['id'], 1, 'manual');
        self::assertSame('paid', $this->billing->invoice((int) $invoice['id'])['status']);
        self::assertSame('active', $this->tenants->find((int) $tenant['id'])['status']);

        $result = $this->billing->applyPaystackEvent([
            'event' => 'charge.success',
            'data' => ['reference' => 'ref-123'],
        ]);

        self::assertTrue($result['handled']);
        self::assertSame('paid', $this->billing->invoice((int) $payable['id'])['status']);
    }

    public function testAFailedPaymentStartsDunningAndTheLadderEndsSuspended(): void
    {
        $tenant = $this->tenant();
        $this->billing->createInvoice((int) $tenant['id'], 'subscription', 'November', 1000000, null, 1, 'ref-9');

        $this->billing->applyPaystackEvent([
            'event' => 'invoice.payment_failed',
            'data' => ['reference' => 'ref-9'],
        ]);

        $pastDue = $this->tenants->find((int) $tenant['id']);
        self::assertSame('past_due', $pastDue['status']);
        self::assertNotNull($pastDue['grace_ends_at']);

        $this->clock->advance('8 days');
        self::assertSame([['tenant_id' => (int) $tenant['id'], 'from' => 'past_due', 'to' => 'read_only']], $this->billing->runDunning());
        self::assertSame('read_only', $this->tenants->find((int) $tenant['id'])['status']);

        $this->clock->advance('15 days');
        $this->billing->runDunning();
        self::assertSame('suspended', $this->tenants->find((int) $tenant['id'])['status']);
        self::assertNotNull($this->tenants->find((int) $tenant['id']), 'Suspension never deletes data');
    }

    public function testUnknownEventsAreIgnoredNotApplied(): void
    {
        $result = $this->billing->applyPaystackEvent(['event' => 'transfer.success', 'data' => []]);

        self::assertFalse($result['handled']);
        self::assertSame('ignored', $result['action']);
    }

    /**
     * @param array<string,int> $limits
     * @param array<string,mixed> $features
     * @return array<string,mixed>
     */
    private function thisCreatePlan(array $limits, array $features): array
    {
        return $this->planService->create([
            'code' => 'plan-' . substr(Uuid::v4(), 0, 8),
            'name' => 'Test plan',
            'monthly_kobo' => 2500000,
            'setup_fee_kobo' => 0,
            'limits' => $limits,
            'features' => $features,
        ], 1);
    }

    /** @return array<string,mixed> */
    private function tenant(): array
    {
        $id = $this->tenants->create([
            'uuid' => Uuid::v4(),
            'blog_id' => random_int(1000, 9999),
            'application_id' => null,
            'business_name' => 'Tenant ' . substr(Uuid::v4(), 0, 4),
            'owner_user_id' => 5,
            'plan_id' => (int) $this->thisCreatePlan(['variants' => 10], [])['id'],
            'status' => 'trial',
            'store_mode' => 'both',
            'primary_domain' => null,
            'trial_ends_at' => null,
            'grace_ends_at' => null,
            'created_at' => $this->clock->nowString(),
            'updated_at' => $this->clock->nowString(),
        ]);

        return $this->tenants->find($id) ?? [];
    }
}

/**
 * Records what provisioning asked the hosting layer to do.
 */
final class FakeProvisioner implements SiteProvisioner
{
    public bool $fail = false;

    public string $slug = '';

    /** @var list<int> */
    public array $seeded = [];

    public function createSite(string $slug, string $title, int $ownerUserId): int
    {
        $this->slug = $slug;

        return 77;
    }

    public function seedStore(int $blogId, array $plan, string $storeMode): void
    {
        if ($this->fail) {
            throw new \RuntimeException('Seed failed');
        }

        $this->seeded[] = $blogId;
    }

    public function finish(int $blogId, int $ownerUserId, string $businessName): void
    {
    }
}
