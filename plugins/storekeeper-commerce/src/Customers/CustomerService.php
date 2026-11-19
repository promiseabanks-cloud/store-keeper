<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Customers;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Audit\AuditLogger;
use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Database\Connection;
use StoreKeeper\Commerce\Core\Exception\NotFound;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Hooks\Hooks;
use StoreKeeper\Commerce\Core\Money;
use StoreKeeper\Commerce\Core\Phone;
use StoreKeeper\Commerce\Core\Uuid;
use StoreKeeper\Commerce\Pricing\PricingRepository;

/**
 * Customers and groups (R1, R5, §11.1): phone-normalised, never WordPress users
 * (§7.4), auto-approved by default for resellers, manual approval on request.
 */
final class CustomerService
{
    public function __construct(
        private readonly Connection $db,
        private readonly CustomerRepository $customers,
        private readonly PricingRepository $prices,
        private readonly Hooks $hooks,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Create a customer.
     *
     * Storefront sign-up respects group approval rules; staff-created customers
     * are approved immediately, as the brief requires (§11.1).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(array $input, Actor $actor, string $source = 'staff'): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new ValidationFailed('A customer name is required.', ['name' => 'Required.']);
        }

        if (!in_array($source, ['storefront', 'staff', 'pos', 'import'], true)) {
            throw new ValidationFailed('Unknown customer source.', ['source' => 'storefront, staff, pos or import.']);
        }

        // Email + password (or Google) is how storefront shoppers sign in now, so
        // a self-registered customer need not give a phone number until checkout.
        // Staff, POS and import still identify a customer by phone (§11.2).
        $phoneInput = trim((string) ($input['phone'] ?? ''));
        $phone = null;

        if ($phoneInput !== '') {
            $phone = Phone::normalise($phoneInput);
            if ($this->customers->customerByPhone($phone) !== null) {
                throw new ValidationFailed('A customer with that phone number already exists.', ['phone' => 'Already registered.']);
            }
        } elseif ($source !== 'storefront') {
            // Keeps the old guarantee: a staff/POS/import customer has a phone.
            $phone = Phone::normalise($phoneInput);
        }

        $requestedGroup = isset($input['group_id']) ? $this->customers->group((int) $input['group_id']) : null;
        if ($requestedGroup === null) {
            $requestedGroup = $this->customers->defaultGroup();
        }

        if ($requestedGroup === null) {
            throw new ValidationFailed('This store has no customer groups yet.');
        }

        $approvalStatus = 'approved';
        $groupId = (int) $requestedGroup['id'];
        $requestedGroupId = null;

        if ($source === 'storefront'
            && ($requestedGroup['approval'] ?? 'auto') === 'manual'
            && (int) ($requestedGroup['is_default'] ?? 0) !== 1
        ) {
            $default = $this->customers->defaultGroup();
            $groupId = $default === null ? $groupId : (int) $default['id'];
            $requestedGroupId = (int) $requestedGroup['id'];
            $approvalStatus = 'pending';
        }

        $now = $this->clock->nowString();
        $customerId = $this->customers->create([
            'uuid' => Uuid::v4(),
            'phone_e164' => $phone,
            'name' => $name,
            'business_name' => $input['business_name'] ?? null,
            'email' => isset($input['email']) && $input['email'] !== '' ? strtolower(trim((string) $input['email'])) : null,
            'password_hash' => isset($input['password_hash']) && $input['password_hash'] !== '' ? (string) $input['password_hash'] : null,
            'google_sub' => isset($input['google_sub']) && $input['google_sub'] !== '' ? (string) $input['google_sub'] : null,
            'group_id' => $groupId,
            'requested_group_id' => $requestedGroupId,
            'approval_status' => $approvalStatus,
            'source' => $source,
            'whatsapp_opt_in' => isset($input['whatsapp_opt_in']) && !$input['whatsapp_opt_in'] ? 0 : 1,
            'marketing_opt_in' => !empty($input['marketing_opt_in']) ? 1 : 0,
            'notes' => $input['notes'] ?? null,
            'total_spent_kobo' => 0,
            'order_count' => 0,
            'last_order_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $customer = $this->customers->customer($customerId) ?? [];

        $this->audit->log($actor, 'customer.created', 'customer', $customerId, null, [
            'phone_e164' => $phone,
            'group_id' => $groupId,
            'approval_status' => $approvalStatus,
        ]);
        $this->hooks->do('customer/registered', ['customer' => $customer]);

        return $customer;
    }

    /**
     * Staff quick order: find by phone or create with just phone + name (§11.2).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function findOrCreate(array $input, Actor $actor, string $source = 'staff'): array
    {
        $phone = Phone::normalise((string) ($input['phone'] ?? ''));
        $existing = $this->customers->customerByPhone($phone);
        if ($existing !== null) {
            return $existing;
        }

        return $this->create(['phone' => $phone, ...$input], $actor, $source);
    }

    /** @param array<string,mixed> $data */
    public function update(int $customerId, array $data, Actor $actor): array
    {
        $customer = $this->customers->customer($customerId);
        if ($customer === null) {
            throw new NotFound('customer', $customerId);
        }

        $changes = [];
        foreach (['name', 'business_name', 'email', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        foreach (['whatsapp_opt_in', 'marketing_opt_in'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field] ? 1 : 0;
            }
        }

        if (array_key_exists('phone', $data)) {
            $phone = Phone::normalise((string) $data['phone']);
            $other = $this->customers->customerByPhone($phone);
            if ($other !== null && (int) $other['id'] !== $customerId) {
                throw new ValidationFailed('Another customer already uses that phone number.', ['phone' => 'Already registered.']);
            }
            $changes['phone_e164'] = $phone;
        }

        if (array_key_exists('group_id', $data)) {
            $this->changeGroup($customerId, (int) $data['group_id'], $actor);
        }

        $this->customers->update($customerId, $changes);
        $this->audit->log($actor, 'customer.updated', 'customer', $customerId, $customer, $changes);

        return $this->customers->customer($customerId) ?? [];
    }

    /**
     * Approve a pending wholesale application (§11.1).
     */
    public function approve(int $customerId, Actor $actor): array
    {
        $customer = $this->customers->customer($customerId);
        if ($customer === null) {
            throw new NotFound('customer', $customerId);
        }

        if ($customer['approval_status'] === 'approved') {
            return $customer;
        }

        $groupId = $customer['requested_group_id'] !== null
            ? (int) $customer['requested_group_id']
            : (int) $customer['group_id'];

        $this->customers->update($customerId, [
            'approval_status' => 'approved',
            'group_id' => $groupId,
            'requested_group_id' => null,
        ]);

        $updated = $this->customers->customer($customerId) ?? [];
        $this->audit->log($actor, 'customer.approved', 'customer', $customerId, [
            'group_id' => (int) $customer['group_id'],
            'approval_status' => $customer['approval_status'],
        ], ['group_id' => $groupId, 'approval_status' => 'approved']);
        $this->hooks->do('customer/approved', ['customer' => $updated]);

        return $updated;
    }

    public function reject(int $customerId, Actor $actor, ?string $reason = null): array
    {
        $customer = $this->customers->customer($customerId);
        if ($customer === null) {
            throw new NotFound('customer', $customerId);
        }

        $this->customers->update($customerId, [
            'approval_status' => 'rejected',
            'requested_group_id' => null,
            'notes' => $reason ?? $customer['notes'],
        ]);

        $this->audit->log($actor, 'customer.rejected', 'customer', $customerId, [
            'approval_status' => $customer['approval_status'],
        ], ['approval_status' => 'rejected', 'reason' => $reason]);

        return $this->customers->customer($customerId) ?? [];
    }

    public function changeGroup(int $customerId, int $groupId, Actor $actor): array
    {
        $customer = $this->customers->customer($customerId);
        if ($customer === null) {
            throw new NotFound('customer', $customerId);
        }

        $group = $this->customers->group($groupId);
        if ($group === null) {
            throw new NotFound('customer group', $groupId);
        }

        $this->customers->update($customerId, [
            'group_id' => $groupId,
            'approval_status' => 'approved',
            'requested_group_id' => null,
        ]);

        $this->audit->log($actor, 'customer.group_changed', 'customer', $customerId, [
            'group_id' => (int) $customer['group_id'],
        ], ['group_id' => $groupId]);

        return $this->customers->customer($customerId) ?? [];
    }

    /**
     * A price agreed with one customer (§8.2 customer_prices).
     *
     * @return array<string,mixed>
     */
    public function setSpecialPrice(
        int $customerId,
        int $variantId,
        ?int $packUnitId,
        Money $amount,
        Actor $actor,
        ?string $validFrom = null,
        ?string $validTo = null,
    ): array {
        if ($this->customers->customer($customerId) === null) {
            throw new NotFound('customer', $customerId);
        }

        $priceId = $this->customers->upsertSpecialPrice($customerId, $variantId, $packUnitId, [
            'amount_kobo' => $amount->minor(),
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
        ]);

        $this->audit->log($actor, 'customer.price_set', 'customer_price', $priceId, null, [
            'customer_id' => $customerId,
            'variant_id' => $variantId,
            'pack_unit_id' => $packUnitId,
            'amount_kobo' => $amount->minor(),
        ]);

        return ['id' => $priceId, 'amount_kobo' => $amount->minor()];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function addAddress(int $customerId, array $input, Actor $actor): array
    {
        if ($this->customers->customer($customerId) === null) {
            throw new NotFound('customer', $customerId);
        }

        foreach (['recipient_name', 'line1', 'lga', 'state'] as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                throw new ValidationFailed('Address fields are incomplete.', [$field => 'Required.']);
            }
        }

        $isDefault = !empty($input['is_default']);
        if ($isDefault) {
            $this->customers->clearDefaultAddress($customerId);
        }

        $addressId = $this->customers->createAddress([
            'customer_id' => $customerId,
            'label' => $input['label'] ?? null,
            'recipient_name' => $input['recipient_name'],
            'phone_e164' => Phone::normalise((string) ($input['phone'] ?? '')),
            'line1' => $input['line1'],
            'line2' => $input['line2'] ?? null,
            'landmark' => $input['landmark'] ?? null,
            'city' => $input['city'] ?? null,
            'lga' => $input['lga'],
            'state' => $input['state'],
            'is_default' => $isDefault ? 1 : 0,
        ]);

        return $this->customers->address($addressId) ?? [];
    }

    /** @return list<array<string,mixed>> */
    public function addresses(int $customerId): array
    {
        return $this->customers->addresses($customerId);
    }

    /** @return array<string,mixed>|null */
    public function find(int $customerId): ?array
    {
        return $this->customers->customer($customerId);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->customers->customerByUuid($uuid);
    }

    /** @return array<string,mixed>|null */
    public function findByPhone(string $phone): ?array
    {
        return $this->customers->customerByPhone(Phone::normalise($phone));
    }

    /** @return list<array<string,mixed>> */
    public function search(string $query, int $limit = 20): array
    {
        return $this->customers->search(trim($query), $limit);
    }

    /** @return list<array<string,mixed>> */
    public function groups(): array
    {
        return $this->customers->groups();
    }

    /** @return list<array<string,mixed>> */
    public function specialPrices(int $customerId): array
    {
        return $this->customers->specialPrices($customerId);
    }

    /**
     * Groups a store needs at provisioning (§17.2 step 4): Retail (default)
     * plus a self-registerable Wholesale group with automatic approval.
     *
     * @return array<string,mixed>
     */
    public function seedDefaultGroups(): array
    {
        $existing = $this->customers->groups();
        if ($existing !== []) {
            return $existing;
        }

        $base = $this->prices->basePriceList();
        $baseListId = $base !== null
            ? (int) $base['id']
            : $this->prices->createList('Retail', \StoreKeeper\Commerce\Core\Brand::CURRENCY, true);
        $wholesaleListId = $this->prices->createList('Wholesale', \StoreKeeper\Commerce\Core\Brand::CURRENCY, false);
        $now = $this->clock->nowString();

        $this->db->insert($this->db->prefix() . 'customer_groups', [
            'uuid' => Uuid::v4(),
            'name' => 'Retail',
            'type' => 'retail',
            'price_list_id' => $baseListId,
            'approval' => 'auto',
            'is_default' => 1,
            'is_self_registerable' => 1,
            'min_order_value_kobo' => null,
            'ignore_item_minimums' => 1,
            'can_pay_on_delivery' => 0,
            'credit_limit_kobo' => null,
            'created_at' => $now,
        ]);
        $this->db->insert($this->db->prefix() . 'customer_groups', [
            'uuid' => Uuid::v4(),
            'name' => 'Wholesale',
            'type' => 'wholesale',
            'price_list_id' => $wholesaleListId,
            'approval' => 'auto',
            'is_default' => 0,
            'is_self_registerable' => 1,
            'min_order_value_kobo' => null,
            'ignore_item_minimums' => 1,
            'can_pay_on_delivery' => 0,
            'credit_limit_kobo' => null,
            'created_at' => $now,
        ]);

        return $this->customers->groups();
    }
}
