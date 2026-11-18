<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Console;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\DatabaseAuditLogger;
use StoreKeeper\Commerce\Core\Database\WpdbConnection;
use StoreKeeper\Commerce\Core\SystemClock;
use StoreKeeper\Platform\Applications\ApplicationService;
use StoreKeeper\Platform\Audit\PlatformAudit;
use StoreKeeper\Platform\Billing\BillingService;
use StoreKeeper\Platform\Plans\PlanRepository;
use StoreKeeper\Platform\Plans\PlanService;
use StoreKeeper\Platform\Provisioning\ProvisioningService;
use StoreKeeper\Platform\Tenants\TenantRepository;

/**
 * The Owner's network console (§17.5): tenants, applications, plans and
 * billing, plus audited impersonation.
 *
 * Every action is a nonce-checked admin-post request; values are read from the
 * services, never from SQL typed into the page.
 */
final class OwnerConsole
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly PlanService $planService,
        private readonly ApplicationService $applications,
        private readonly TenantRepository $tenants,
        private readonly ProvisioningService $provisioning,
        private readonly BillingService $billing,
        private readonly PlatformAudit $audit,
    ) {
    }

    public static function register(): void
    {
        add_action('network_admin_menu', [new self(
            \StoreKeeper\Platform\Platform::container()->get(PlanRepository::class),
            \StoreKeeper\Platform\Platform::container()->get(PlanService::class),
            \StoreKeeper\Platform\Platform::container()->get(ApplicationService::class),
            \StoreKeeper\Platform\Platform::container()->get(TenantRepository::class),
            \StoreKeeper\Platform\Platform::container()->get(ProvisioningService::class),
            \StoreKeeper\Platform\Platform::container()->get(BillingService::class),
            \StoreKeeper\Platform\Platform::container()->get(PlatformAudit::class),
        ), 'menu']);

        add_action('admin_post_storekeeper_platform_plan_save', [self::class, 'savePlan']);
        add_action('admin_post_storekeeper_platform_application_status', [self::class, 'applicationStatus']);
        add_action('admin_post_storekeeper_platform_provision', [self::class, 'provision']);
        add_action('admin_post_storekeeper_platform_tenant_save', [self::class, 'saveTenant']);
        add_action('admin_post_storekeeper_platform_invoice_create', [self::class, 'createInvoice']);
        add_action('admin_post_storekeeper_platform_invoice_paid', [self::class, 'invoicePaid']);
        add_action('admin_post_storekeeper_platform_impersonate', [self::class, 'impersonate']);
        add_action('admin_post_storekeeper_platform_stop_impersonation', [self::class, 'stopImpersonation']);
        add_action('admin_notices', [self::class, 'impersonationNotice']);
    }

    public function menu(): void
    {
        add_menu_page('Store Keeper', 'Store Keeper', 'manage_network', 'storekeeper-platform', [$this, 'tenantsPage'], 'dashicons-store', 3);
        add_submenu_page('storekeeper-platform', 'Tenants', 'Tenants', 'manage_network', 'storekeeper-platform', [$this, 'tenantsPage']);
        add_submenu_page('storekeeper-platform', 'Applications', 'Applications', 'manage_network', 'storekeeper-platform-applications', [$this, 'applicationsPage']);
        add_submenu_page('storekeeper-platform', 'Plans', 'Plans', 'manage_network', 'storekeeper-platform-plans', [$this, 'plansPage']);
        add_submenu_page('storekeeper-platform', 'Billing', 'Billing', 'manage_network', 'storekeeper-platform-billing', [$this, 'billingPage']);
    }

    public function tenantsPage(): void
    {
        $tenants = $this->tenants->all();
        $plans = $this->plans->all();
        ?>
        <div class="wrap">
            <h1>Tenants</h1>
            <?php $this->notice(); ?>
            <table class="widefat striped">
                <thead><tr><th>Business</th><th>Blog</th><th>Plan</th><th>Status</th><th>Trial / grace ends</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($tenants as $tenant): ?>
                    <tr>
                        <td><strong><?php echo esc_html($tenant['business_name']); ?></strong></td>
                        <td><?php echo (int) $tenant['blog_id']; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('storekeeper_platform_tenant_save'); ?>
                                <input type="hidden" name="action" value="storekeeper_platform_tenant_save">
                                <input type="hidden" name="tenant_id" value="<?php echo (int) $tenant['id']; ?>">
                                <select name="plan_id">
                                    <?php foreach ($plans as $plan): ?>
                                        <option value="<?php echo (int) $plan['id']; ?>" <?php selected((int) $tenant['plan_id'], (int) $plan['id']); ?>>
                                            <?php echo esc_html($plan['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td>
                                <select name="status">
                                    <?php foreach (['trial', 'active', 'past_due', 'read_only', 'suspended', 'cancelled'] as $status): ?>
                                        <option value="<?php echo esc_attr($status); ?>" <?php selected($tenant['status'], $status); ?>>
                                            <?php echo esc_html($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                        </td>
                        <td><?php echo esc_html((string) ($tenant['trial_ends_at'] ?: $tenant['grace_ends_at'] ?: '—')); ?></td>
                        <td>
                                <button class="button">Save</button>
                            </form>
                            <a class="button" href="<?php echo esc_url(get_admin_url((int) $tenant['blog_id'])); ?>">Open store</a>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('storekeeper_platform_impersonate'); ?>
                                <input type="hidden" name="action" value="storekeeper_platform_impersonate">
                                <input type="hidden" name="tenant_id" value="<?php echo (int) $tenant['id']; ?>">
                                <input name="reason" placeholder="Reason (required)" required style="width:140px">
                                <button class="button">Log in as merchant</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($tenants === []): ?>
                    <tr><td colspan="6">No tenants yet. Approve an application and provision it.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function applicationsPage(): void
    {
        $applications = $this->applications->all();
        $plans = $this->plans->all(true);
        ?>
        <div class="wrap">
            <h1>Applications</h1>
            <?php $this->notice(); ?>
            <table class="widefat striped">
                <thead><tr><th>Business</th><th>Contact</th><th>Sells to</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($applications as $application): ?>
                    <tr>
                        <td><strong><?php echo esc_html($application['business_name']); ?></strong><br>
                            <span class="description"><?php echo esc_html((string) $application['category']); ?></span></td>
                        <td><?php echo esc_html($application['contact_name']); ?><br>
                            <?php echo esc_html($application['phone_e164']); ?><br>
                            <?php echo esc_html((string) $application['email']); ?></td>
                        <td><?php echo esc_html($application['sells_to']); ?></td>
                        <td><?php echo esc_html($application['status']); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('storekeeper_platform_application_status'); ?>
                                <input type="hidden" name="action" value="storekeeper_platform_application_status">
                                <input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>">
                                <select name="status">
                                    <?php foreach (ApplicationService::STATUSES as $status): ?>
                                        <option value="<?php echo esc_attr($status); ?>" <?php selected($application['status'], $status); ?>>
                                            <?php echo esc_html($status); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input name="notes" placeholder="Owner notes" value="<?php echo esc_attr((string) $application['owner_notes']); ?>">
                                <button class="button">Update</button>
                            </form>
                            <?php if ($application['status'] === 'approved' && $plans !== []): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('storekeeper_platform_provision'); ?>
                                    <input type="hidden" name="action" value="storekeeper_platform_provision">
                                    <input type="hidden" name="application_id" value="<?php echo (int) $application['id']; ?>">
                                    <select name="plan_id">
                                        <?php foreach ($plans as $plan): ?>
                                            <option value="<?php echo (int) $plan['id']; ?>"><?php echo esc_html($plan['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input name="owner_user_id" type="number" placeholder="Owner user ID" required style="width:110px">
                                    <input name="slug" placeholder="site-slug" style="width:110px">
                                    <select name="store_mode">
                                        <option value="both">both</option>
                                        <option value="retail">retail</option>
                                        <option value="wholesale">wholesale</option>
                                    </select>
                                    <button class="button button-primary">Provision store</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($applications === []): ?>
                    <tr><td colspan="5">No applications yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function plansPage(): void
    {
        $plans = $this->plans->all();
        ?>
        <div class="wrap">
            <h1>Plans</h1>
            <?php $this->notice(); ?>
            <p class="description">Prices and limits are yours to set; nothing is pre-filled.</p>
            <table class="widefat striped">
                <thead><tr><th>Code</th><th>Name</th><th>Monthly</th><th>Setup</th><th>Limits</th><th>Public</th></tr></thead>
                <tbody>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><code><?php echo esc_html($plan['code']); ?></code></td>
                        <td><?php echo esc_html($plan['name']); ?></td>
                        <td><?php echo esc_html(number_format($plan['monthly_kobo'] / 100, 2)); ?></td>
                        <td><?php echo esc_html(number_format($plan['setup_fee_kobo'] / 100, 2)); ?></td>
                        <td><code><?php echo esc_html(wp_json_encode($plan['limits'])); ?></code></td>
                        <td><?php echo $plan['is_public'] ? 'Yes' : 'No'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>New plan</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('storekeeper_platform_plan_save'); ?>
                <input type="hidden" name="action" value="storekeeper_platform_plan_save">
                <table class="form-table">
                    <tr><th>Code</th><td><input name="code" required></td></tr>
                    <tr><th>Name</th><td><input name="name" required></td></tr>
                    <tr><th>Monthly (₦)</th><td><input name="monthly" type="number" min="0" step="1" value="0"></td></tr>
                    <tr><th>Setup fee (₦)</th><td><input name="setup" type="number" min="0" step="1" value="0"></td></tr>
                    <tr><th>Variants</th><td><input name="variants" type="number" min="0" value="0"> <span class="description">0 = uncapped</span></td></tr>
                    <tr><th>Staff seats</th><td><input name="staff_seats" type="number" min="0" value="0"></td></tr>
                    <tr><th>Locations</th><td><input name="locations" type="number" min="0" value="0"></td></tr>
                    <tr><th>POS devices</th><td><input name="pos_devices" type="number" min="0" value="0"></td></tr>
                    <tr><th>Features</th><td>
                        <label><input type="checkbox" name="pos_offline" value="1" checked> POS offline</label><br>
                        <label><input type="checkbox" name="multi_location" value="1"> Multi-location</label><br>
                        <label><input type="checkbox" name="reports_advanced" value="1"> Advanced reports</label><br>
                        <label><input type="checkbox" name="whatsapp_api" value="1"> WhatsApp API</label><br>
                        <label><input type="checkbox" name="is_public" value="1"> Show publicly</label>
                    </td></tr>
                </table>
                <button class="button button-primary">Create plan</button>
            </form>
        </div>
        <?php
    }

    public function billingPage(): void
    {
        $tenants = $this->tenants->all();
        ?>
        <div class="wrap">
            <h1>Billing</h1>
            <?php $this->notice(); ?>
            <p class="description">Invoices are recorded here. Paystack events update them through the
                signed webhook at <code><?php echo esc_html(rest_url('storekeeper-platform/v1/webhook')); ?></code>.</p>
            <?php foreach ($tenants as $tenant): ?>
                <h2><?php echo esc_html($tenant['business_name']); ?> <span class="description">(<?php echo esc_html($tenant['status']); ?>)</span></h2>
                <table class="widefat striped">
                    <thead><tr><th>Kind</th><th>Description</th><th>Amount</th><th>Status</th><th>Paid</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($this->billing->invoices((int) $tenant['id']) as $invoice): ?>
                        <tr>
                            <td><?php echo esc_html($invoice['kind']); ?></td>
                            <td><?php echo esc_html($invoice['description']); ?></td>
                            <td><?php echo esc_html(number_format($invoice['amount_kobo'] / 100, 2)); ?></td>
                            <td><?php echo esc_html($invoice['status']); ?></td>
                            <td><?php echo esc_html((string) $invoice['paid_at']); ?></td>
                            <td>
                                <?php if ($invoice['status'] !== 'paid'): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('storekeeper_platform_invoice_paid'); ?>
                                        <input type="hidden" name="action" value="storekeeper_platform_invoice_paid">
                                        <input type="hidden" name="invoice_id" value="<?php echo (int) $invoice['id']; ?>">
                                        <button class="button">Mark paid</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:8px 0 24px">
                    <?php wp_nonce_field('storekeeper_platform_invoice_create'); ?>
                    <input type="hidden" name="action" value="storekeeper_platform_invoice_create">
                    <input type="hidden" name="tenant_id" value="<?php echo (int) $tenant['id']; ?>">
                    <select name="kind">
                        <?php foreach (BillingService::KINDS as $kind): ?>
                            <option value="<?php echo esc_attr($kind); ?>"><?php echo esc_html($kind); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input name="description" placeholder="Description" required>
                    <input name="amount" type="number" min="0" step="1" placeholder="Amount (₦)" required style="width:130px">
                    <button class="button">Create invoice</button>
                </form>
            <?php endforeach; ?>
            <?php if ($tenants === []): ?><p>No tenants yet.</p><?php endif; ?>
        </div>
        <?php
    }

    public static function savePlan(): void
    {
        self::guard('storekeeper_platform_plan_save');

        $limits = [];
        foreach (['variants', 'staff_seats', 'locations', 'pos_devices'] as $key) {
            $limits[$key] = (int) ($_POST[$key] ?? 0);
        }
        $limits['custom_domain'] = 0;

        try {
            \StoreKeeper\Platform\Platform::container()->get(PlanService::class)->create([
                'code' => (string) ($_POST['code'] ?? ''),
                'name' => (string) ($_POST['name'] ?? ''),
                'monthly_kobo' => (int) round((float) ($_POST['monthly'] ?? 0) * 100),
                'setup_fee_kobo' => (int) round((float) ($_POST['setup'] ?? 0) * 100),
                'limits' => $limits,
                'features' => [
                    'pos_offline' => !empty($_POST['pos_offline']),
                    'multi_location' => !empty($_POST['multi_location']),
                    'reports_advanced' => !empty($_POST['reports_advanced']),
                    'whatsapp_api' => !empty($_POST['whatsapp_api']),
                ],
                'is_public' => !empty($_POST['is_public']),
            ], get_current_user_id());
            self::redirect('storekeeper-platform-plans', 'Plan created.');
        } catch (\Throwable $error) {
            self::redirect('storekeeper-platform-plans', $error->getMessage(), 'error');
        }
    }

    public static function applicationStatus(): void
    {
        self::guard('storekeeper_platform_application_status');

        try {
            \StoreKeeper\Platform\Platform::container()->get(ApplicationService::class)->updateStatus(
                (int) ($_POST['application_id'] ?? 0),
                (string) ($_POST['status'] ?? ''),
                isset($_POST['notes']) ? (string) $_POST['notes'] : null,
                get_current_user_id()
            );
            self::redirect('storekeeper-platform-applications', 'Application updated.');
        } catch (\Throwable $error) {
            self::redirect('storekeeper-platform-applications', $error->getMessage(), 'error');
        }
    }

    public static function provision(): void
    {
        self::guard('storekeeper_platform_provision');
        $container = \StoreKeeper\Platform\Platform::container();

        try {
            $application = $container->get(ApplicationService::class)->find((int) ($_POST['application_id'] ?? 0));
            if ($application === null) {
                throw new \RuntimeException('Application not found.');
            }

            $tenant = $container->get(ProvisioningService::class)->provision(
                $application,
                (int) ($_POST['plan_id'] ?? 0),
                [
                    'owner_user_id' => (int) ($_POST['owner_user_id'] ?? 0),
                    'store_mode' => (string) ($_POST['store_mode'] ?? 'both'),
                    'slug' => (string) ($_POST['slug'] ?? ''),
                ],
                get_current_user_id()
            );

            self::redirect('storekeeper-platform', 'Store provisioned: blog ' . $tenant['blog_id'] . '.');
        } catch (\Throwable $error) {
            self::redirect('storekeeper-platform-applications', $error->getMessage(), 'error');
        }
    }

    public static function saveTenant(): void
    {
        self::guard('storekeeper_platform_tenant_save');
        $container = \StoreKeeper\Platform\Platform::container();

        try {
            $container->get(TenantRepository::class)->update((int) ($_POST['tenant_id'] ?? 0), [
                'plan_id' => (int) ($_POST['plan_id'] ?? 0),
                'status' => (string) ($_POST['status'] ?? 'trial'),
            ]);
            $container->get(PlatformAudit::class)->log(get_current_user_id(), 'tenant.updated', (int) ($_POST['tenant_id'] ?? 0));
            self::redirect('storekeeper-platform', 'Tenant updated.');
        } catch (\Throwable $error) {
            self::redirect('storekeeper-platform', $error->getMessage(), 'error');
        }
    }

    public static function createInvoice(): void
    {
        self::guard('storekeeper_platform_invoice_create');
        $container = \StoreKeeper\Platform\Platform::container();

        try {
            $container->get(BillingService::class)->createInvoice(
                (int) ($_POST['tenant_id'] ?? 0),
                (string) ($_POST['kind'] ?? 'subscription'),
                (string) ($_POST['description'] ?? ''),
                (int) round((float) ($_POST['amount'] ?? 0) * 100),
                null,
                get_current_user_id()
            );
            self::redirect('storekeeper-platform-billing', 'Invoice created.');
        } catch (\Throwable $error) {
            self::redirect('storekeeper-platform-billing', $error->getMessage(), 'error');
        }
    }

    public static function invoicePaid(): void
    {
        self::guard('storekeeper_platform_invoice_paid');
        $container = \StoreKeeper\Platform\Platform::container();

        try {
            $container->get(BillingService::class)->markPaid((int) ($_POST['invoice_id'] ?? 0), get_current_user_id(), 'manual');
            self::redirect('storekeeper-platform-billing', 'Invoice marked paid.');
        } catch (\Throwable $error) {
            self::redirect('storekeeper-platform-billing', $error->getMessage(), 'error');
        }
    }

    public static function impersonate(): void
    {
        self::guard('storekeeper_platform_impersonate');

        if (!function_exists('wp_set_auth_cookie')) {
            wp_die('WordPress auth is unavailable.');
        }

        $container = \StoreKeeper\Platform\Platform::container();
        $tenant = $container->get(TenantRepository::class)->find((int) ($_POST['tenant_id'] ?? 0));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($tenant === null || $reason === '') {
            self::redirect('storekeeper-platform', 'A tenant and a reason are required.', 'error');
        }

        $ownerId = get_current_user_id();
        $targetId = (int) $tenant['owner_user_id'];
        $expires = time() + 3600;
        $payload = $ownerId . '|' . $targetId . '|' . $expires;
        $signature = hash_hmac('sha256', $payload, wp_salt('auth'));

        setcookie('sk_owner_return', base64_encode($payload . '|' . $signature), [
            'expires' => $expires,
            'path' => '/',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Both audit trails record the impersonation, as §17.5 requires.
        $container->get(PlatformAudit::class)->log($ownerId, 'tenant.impersonated', (int) $tenant['id'], null, null, ['reason' => $reason]);

        switch_to_blog((int) $tenant['blog_id']);
        (new DatabaseAuditLogger(new WpdbConnection($GLOBALS['wpdb']), new SystemClock()))->log(
            Actor::impersonation($ownerId),
            'owner.impersonated',
            'user',
            $targetId,
            null,
            ['reason' => $reason]
        );
        $adminUrl = get_admin_url((int) $tenant['blog_id']);
        restore_current_blog();

        wp_set_auth_cookie($targetId);
        wp_safe_redirect($adminUrl);
        exit;
    }

    public static function stopImpersonation(): void
    {
        self::guard('storekeeper_platform_stop_impersonation');

        $raw = isset($_COOKIE['sk_owner_return']) ? base64_decode((string) $_COOKIE['sk_owner_return'], true) : false;
        if (!is_string($raw) || substr_count($raw, '|') !== 3) {
            wp_die('No impersonation session to end.');
        }

        [$ownerId, $targetId, $expires, $signature] = explode('|', $raw);
        $payload = $ownerId . '|' . $targetId . '|' . $expires;

        if (!hash_equals(hash_hmac('sha256', $payload, wp_salt('auth')), $signature) || (int) $expires < time()) {
            wp_die('That impersonation session has expired.');
        }

        \StoreKeeper\Platform\Platform::container()->get(PlatformAudit::class)->log(
            (int) $ownerId,
            'tenant.impersonation_ended',
            null
        );

        setcookie('sk_owner_return', '', ['expires' => time() - 3600, 'path' => '/']);
        wp_set_auth_cookie((int) $ownerId);
        wp_safe_redirect(network_admin_url('admin.php?page=storekeeper-platform'));
        exit;
    }

    public static function impersonationNotice(): void
    {
        if (!isset($_COOKIE['sk_owner_return'])) {
            return;
        }
        ?>
        <div class="notice notice-warning">
            <p><strong>Owner impersonation session.</strong> You are signed in as this merchant.
                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=storekeeper_platform_stop_impersonation'), 'storekeeper_platform_stop_impersonation')); ?>">
                    Return to the owner account
                </a>
            </p>
        </div>
        <?php
    }

    private static function guard(string $nonceAction): void
    {
        if (!current_user_can('manage_network')) {
            wp_die('Only the network owner can do this.');
        }

        check_admin_referer($nonceAction);
    }

    private static function redirect(string $page, string $message, string $tone = 'success'): void
    {
        $url = network_admin_url('admin.php?page=' . $page . '&sk_notice=' . rawurlencode($message) . '&sk_tone=' . $tone);
        wp_safe_redirect($url);
        exit;
    }

    private function notice(): void
    {
        if (empty($_GET['sk_notice'])) {
            return;
        }

        $tone = ($_GET['sk_tone'] ?? 'success') === 'error' ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr($tone) . ' is-dismissible"><p>'
            . esc_html((string) wp_unslash($_GET['sk_notice']))
            . '</p></div>';
    }
}
