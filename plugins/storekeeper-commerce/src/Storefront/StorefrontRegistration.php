<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Storefront;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Customers\CustomerRepository;
use StoreKeeper\Commerce\Customers\CustomerService;

/**
 * Storefront sign-up (§11.1, R1, R5).
 *
 * The shopper chooses how they are buying from the store's self-registerable
 * groups. Approval is the group's rule, not this class's: `CustomerService`
 * either approves at once or parks the request in the retail group as pending.
 */
final class StorefrontRegistration
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerService $service,
    ) {
    }

    /**
     * The "How are you buying?" choices.
     *
     * @return list<array{id:int,name:string,type:string,requires_business:bool,manual:bool}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->customers->selfRegisterableGroups() as $group) {
            $options[] = [
                'id' => (int) $group['id'],
                'name' => (string) $group['name'],
                'type' => (string) $group['type'],
                'requires_business' => (string) $group['type'] === 'wholesale',
                'manual' => (string) $group['approval'] === 'manual',
            ];
        }

        return $options;
    }

    /**
     * Validate a sign-up form before a code is sent.
     *
     * @param array<string,mixed> $input
     * @return array{name:string,group_id:int,business_name:?string,email:?string}
     */
    public function validate(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new ValidationFailed('Tell us your name.', ['name' => 'Required.']);
        }

        $groupId = (int) ($input['group_id'] ?? 0);
        $group = null;

        foreach ($this->customers->selfRegisterableGroups() as $candidate) {
            if ((int) $candidate['id'] === $groupId) {
                $group = $candidate;
                break;
            }
        }

        if ($group === null) {
            throw new ValidationFailed('Choose how you are buying.', ['group_id' => 'Required.']);
        }

        $business = trim((string) ($input['business_name'] ?? ''));

        if ((string) $group['type'] === 'wholesale' && $business === '') {
            throw new ValidationFailed(
                'A business name is required to buy wholesale.',
                ['business_name' => 'Required.']
            );
        }

        $email = trim((string) ($input['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationFailed('That email address does not look right.', ['email' => 'Use name@example.com.']);
        }

        return [
            'name' => $name,
            'group_id' => $groupId,
            'business_name' => $business === '' ? null : $business,
            'email' => $email === '' ? null : strtolower($email),
        ];
    }

    /**
     * Create the customer once the phone number is confirmed.
     *
     * @param array{name:string,group_id:int,business_name:?string,email:?string} $clean
     * @return array<string,mixed>
     */
    public function register(array $clean, string $phone): array
    {
        return $this->service->create(
            [
                'phone' => $phone,
                'name' => $clean['name'],
                'group_id' => $clean['group_id'],
                'business_name' => $clean['business_name'],
                'email' => $clean['email'],
            ],
            Actor::system(),
            'storefront'
        );
    }
}
