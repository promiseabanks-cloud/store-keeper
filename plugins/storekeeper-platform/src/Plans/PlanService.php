<?php

declare(strict_types=1);

namespace StoreKeeper\Platform\Plans;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Platform\Audit\PlatformAudit;

/**
 * Create and edit plans (§17.3, §17.5). No plan prices are invented: the Owner
 * enters every amount and limit in the console.
 */
final class PlanService
{
    public const LIMIT_KEYS = [
        'variants',
        'staff_seats',
        'locations',
        'pos_devices',
        'wa_messages_per_month',
        'storage_mb',
        'custom_domain',
    ];

    public const FEATURE_KEYS = [
        'store_modes',
        'pos_offline',
        'virtual_accounts',
        'whatsapp_api',
        'bulk_import',
        'bumpa_import',
        'custom_roles',
        'multi_location',
        'reports_advanced',
        'marketing_messages',
        'self_serve_domain',
        'remove_platform_branding',
        'priority_support',
    ];

    public function __construct(
        private readonly PlanRepository $plans,
        private readonly PlatformAudit $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $input code, name, monthly_kobo, setup_fee_kobo, limits, features, is_public
     * @return array<string,mixed>
     */
    public function create(array $input, int $actorId): array
    {
        $data = $this->validate($input);

        if ($this->plans->byCode($data['code']) !== null) {
            throw new ValidationFailed('That plan code is already used.', ['code' => 'Already exists.']);
        }

        $data['created_at'] = $this->clock->nowString();
        $id = $this->plans->create($data);
        $this->audit->log($actorId, 'plan.created', null, $id, null, ['code' => $data['code']]);

        return $this->plans->find($id) ?? [];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $planId, array $input, int $actorId): array
    {
        $existing = $this->plans->find($planId);
        if ($existing === null) {
            throw new NotFound('plan', $planId);
        }

        $data = $this->validate([...$existing, ...$input], $existing);

        $this->plans->update($planId, $data);
        $this->audit->log($actorId, 'plan.updated', null, $planId, ['code' => $existing['code']], $data);

        return $this->plans->find($planId) ?? [];
    }

    /** @return array<string,mixed> */
    public function duplicate(int $planId, string $newCode, int $actorId): array
    {
        $plan = $this->plans->find($planId);
        if ($plan === null) {
            throw new NotFound('plan', $planId);
        }

        return $this->create([
            'code' => $newCode,
            'name' => $plan['name'] . ' copy',
            'monthly_kobo' => $plan['monthly_kobo'],
            'setup_fee_kobo' => $plan['setup_fee_kobo'],
            'limits' => $plan['limits'],
            'features' => $plan['features'],
            'is_public' => 0,
        ], $actorId);
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validate(array $input, ?array $existing = null): array
    {
        $code = strtolower(trim((string) ($input['code'] ?? '')));
        if ($code === '' || preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $code) !== 1) {
            throw new ValidationFailed('A plan needs a short code.', ['code' => '2–32 letters, numbers, - or _.']);
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ValidationFailed('A plan needs a name.', ['name' => 'Required.']);
        }

        $monthly = (int) ($input['monthly_kobo'] ?? 0);
        $setup = (int) ($input['setup_fee_kobo'] ?? 0);
        if ($monthly < 0 || $setup < 0) {
            throw new ValidationFailed('Plan amounts cannot be negative.', ['monthly_kobo' => 'Zero or more.']);
        }

        $limits = $this->normaliseLimits($input['limits'] ?? []);
        $features = $this->normaliseFeatures($input['features'] ?? []);

        return [
            'code' => $code,
            'name' => $name,
            'monthly_kobo' => $monthly,
            'setup_fee_kobo' => $setup,
            'annual_kobo' => isset($input['annual_kobo']) && (int) $input['annual_kobo'] > 0 ? (int) $input['annual_kobo'] : null,
            'limits' => json_encode($limits, JSON_THROW_ON_ERROR),
            'features' => json_encode($features, JSON_THROW_ON_ERROR),
            'is_public' => !empty($input['is_public']) ? 1 : 0,
            'is_active' => $existing === null || !array_key_exists('is_active', $input) ? 1 : (!empty($input['is_active']) ? 1 : 0),
        ];
    }

    /**
     * @param mixed $limits
     * @return array<string,int>
     */
    private function normaliseLimits(mixed $limits): array
    {
        if (!is_array($limits)) {
            throw new ValidationFailed('Limits must be a map of numbers.', ['limits' => 'Invalid.']);
        }

        $clean = [];
        foreach (self::LIMIT_KEYS as $key) {
            if (!array_key_exists($key, $limits) || $limits[$key] === null || $limits[$key] === '') {
                $clean[$key] = $key === 'custom_domain' ? 0 : 0;
                continue;
            }
            $clean[$key] = max(0, (int) $limits[$key]);
        }

        return $clean;
    }

    /**
     * @param mixed $features
     * @return array<string,mixed>
     */
    private function normaliseFeatures(mixed $features): array
    {
        if (!is_array($features)) {
            throw new ValidationFailed('Features must be a map of flags.', ['features' => 'Invalid.']);
        }

        $clean = [];
        foreach (self::FEATURE_KEYS as $key) {
            if ($key === 'store_modes') {
                $modes = $features[$key] ?? ['retail', 'wholesale', 'both'];
                $modes = is_array($modes) ? array_values(array_intersect($modes, ['retail', 'wholesale', 'both'])) : [];
                $clean[$key] = $modes === [] ? ['both'] : $modes;
                continue;
            }
            $clean[$key] = !empty($features[$key]);
        }

        return $clean;
    }
}
