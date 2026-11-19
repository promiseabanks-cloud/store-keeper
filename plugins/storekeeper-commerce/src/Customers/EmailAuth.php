<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Customers;

use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;

/**
 * Email + password sign-in for storefront customers (§7.4, §11.1).
 *
 * This replaces phone + one-time code as the way a shopper signs in. Customers
 * are still not WordPress users: the password hash lives on the customer row,
 * and {@see StorefrontAuth} still owns the session cookie, so a signed-in
 * customer's own group prices apply everywhere exactly as before.
 *
 * Passwords are stored with PHP's password_hash (bcrypt), never as text, and a
 * sign-in that fails gives the same answer whether the email is unknown or the
 * password is wrong, so the form never reveals which addresses have accounts.
 */
final class EmailAuth
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerService $service,
        private readonly StorefrontAuth $sessions,
    ) {
    }

    /**
     * Create an account from a validated sign-up form and open a session.
     *
     * The group's approval rule still decides retail-versus-wholesale: a
     * manual-approval wholesale group leaves the shopper pending at retail
     * prices, exactly as the phone flow did (§11.1).
     *
     * @param array{name:string,group_id:int,business_name:?string,email:string} $clean
     * @return array{token:string, customer:array<string,mixed>}
     */
    public function register(array $clean, string $password, ?string $phone = null, ?string $ip = null, ?string $userAgent = null): array
    {
        $email = $this->email($clean['email']);
        $this->assertPassword($password);

        if ($this->customers->customerByEmail($email) !== null) {
            throw new ValidationFailed(
                'That email already has an account. Sign in instead.',
                ['email' => 'Already registered.']
            );
        }

        $customer = $this->service->create(
            [
                'name' => $clean['name'],
                'email' => $email,
                'group_id' => $clean['group_id'],
                'business_name' => $clean['business_name'],
                'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ],
            Actor::system(),
            'storefront'
        );

        return [
            'token' => $this->sessions->openSession((int) $customer['id'], $ip, $userAgent),
            'customer' => $customer,
        ];
    }

    /**
     * Check an email and password and open a session.
     *
     * @return array{token:string, customer:array<string,mixed>}
     */
    public function login(string $emailInput, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        $email = $this->email($emailInput);
        $customer = $this->customers->customerByEmail($email);

        $hash = is_array($customer) ? (string) ($customer['password_hash'] ?? '') : '';

        if ($customer === null || $hash === '' || !password_verify($password, $hash)) {
            throw new ValidationFailed('That email or password is not correct.');
        }

        if (($customer['approval_status'] ?? 'approved') === 'rejected') {
            throw new ValidationFailed('This account cannot sign in. Please contact the shop.');
        }

        if (password_needs_rehash($hash, PASSWORD_BCRYPT)) {
            $this->customers->setPassword((int) $customer['id'], password_hash($password, PASSWORD_BCRYPT));
        }

        return [
            'token' => $this->sessions->openSession((int) $customer['id'], $ip, $userAgent),
            'customer' => $customer,
        ];
    }

    /** Set or change the password on an account the caller already trusts. */
    public function setPassword(int $customerId, string $password): void
    {
        $this->assertPassword($password);
        $this->customers->setPassword($customerId, password_hash($password, PASSWORD_BCRYPT));
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationFailed(
                sprintf('Choose a password of at least %d characters.', self::MIN_PASSWORD_LENGTH),
                ['password' => 'Too short.']
            );
        }
    }

    private function email(string $input): string
    {
        $email = strtolower(trim($input));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationFailed('That email address does not look right.', ['email' => 'Use name@example.com.']);
        }

        return $email;
    }
}
