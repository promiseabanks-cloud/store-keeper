<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Customers;

use StoreKeeper\Commerce\Core\Clock;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Core\Phone;
use StoreKeeper\Commerce\Storefront\Otp\OtpSender;

/**
 * Phone + one-time-code sign-in for storefront customers (§7.4, §22.5).
 *
 * Customers are not WordPress users: this service issues its own session token,
 * and the storefront keeps it in an HTTP-only cookie. Only a hash of the token
 * is stored, and a session slides forward while it is in use.
 *
 * The limits are the ones the handover asks for: a code lasts 10 minutes, five
 * wrong guesses retire it, a phone can ask for five codes an hour and one IP
 * address for twenty. Codes are stored as bcrypt hashes, never as text.
 */
final class StorefrontAuth
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const REQUESTS_PER_PHONE_HOUR = 5;

    public const REQUESTS_PER_IP_HOUR = 20;

    public const SESSION_DAYS = 90;

    /** A session is extended once it has less than this left. */
    public const SESSION_REFRESH_AFTER_DAYS = 60;

    public function __construct(
        private readonly OtpRepository $otp,
        private readonly CustomerSessionRepository $sessions,
        private readonly CustomerRepository $customers,
        private readonly OtpSender $sender,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Issue a code.
     *
     * An unknown phone number gets the same answer as a known one: asking the
     * shop to reveal which numbers have accounts is not part of sign-in.
     *
     * @return array{phone:string, sent:bool}
     */
    public function request(string $phoneInput, ?string $ip = null): array
    {
        $phone = $this->phone($phoneInput);

        $this->assertWithinLimits($phone, $ip);

        $customer = $this->customers->customerByPhone($phone);

        if ($customer === null || ($customer['approval_status'] ?? 'approved') === 'rejected') {
            return ['phone' => $phone, 'sent' => false];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = $this->clock->nowString();

        $this->otp->create([
            'phone_e164' => $phone,
            'code_hash' => password_hash($code, PASSWORD_BCRYPT),
            'channel' => 'sms',
            'purpose' => 'login',
            'attempts' => 0,
            'expires_at' => $this->nowPlusMinutes(self::CODE_TTL_MINUTES),
            'consumed_at' => null,
            'created_at' => $now,
            'ip' => $ip,
        ]);

        $this->sender->send($phone, $code, 'login');

        return ['phone' => $phone, 'sent' => true];
    }

    /**
     * Check a code and open a session.
     *
     * @return array{token:string, customer:array<string,mixed>}
     */
    public function verify(string $phoneInput, string $code, ?string $ip = null, ?string $userAgent = null): array
    {
        $phone = $this->phone($phoneInput);
        $code = preg_replace('/\D+/', '', $code) ?? '';

        $row = $this->otp->latest($phone);

        if ($row === null) {
            throw new ValidationFailed('Ask for a sign-in code first.');
        }

        if (($row['consumed_at'] ?? null) !== null) {
            throw new ValidationFailed('That code has already been used. Ask for a new one.');
        }

        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            throw new ValidationFailed('Too many attempts with that code. Ask for a new one.');
        }

        if ((string) $row['expires_at'] < $this->clock->nowString()) {
            throw new ValidationFailed('That code has expired. Ask for a new one.');
        }

        if ($code === '' || !password_verify($code, (string) $row['code_hash'])) {
            $this->otp->incrementAttempts((int) $row['id']);

            throw new ValidationFailed('That code is not correct.');
        }

        $this->otp->consume((int) $row['id'], $this->clock->nowString());

        $customer = $this->customers->customerByPhone($phone);

        if ($customer === null || ($customer['approval_status'] ?? 'approved') === 'rejected') {
            throw new ValidationFailed('This number cannot sign in. Please contact the shop.');
        }

        $token = bin2hex(random_bytes(32));
        $now = $this->clock->nowString();

        $this->sessions->create([
            'customer_id' => (int) $customer['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => $this->nowPlusDays(self::SESSION_DAYS),
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'created_at' => $now,
        ]);

        return ['token' => $token, 'customer' => $customer];
    }

    /**
     * Resolve a session token, extending a session that is running out.
     *
     * @return array<string,mixed>|null
     */
    public function customerFor(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $session = $this->sessions->byTokenHash(hash('sha256', $token));

        if ($session === null) {
            return null;
        }

        $now = $this->clock->nowString();

        if ((string) $session['expires_at'] < $now) {
            $this->sessions->delete((int) $session['id']);

            return null;
        }

        $customer = $this->customers->customer((int) $session['customer_id']);

        if ($customer === null || ($customer['approval_status'] ?? 'approved') === 'rejected') {
            return null;
        }

        if ((string) $session['expires_at'] < $this->nowPlusDays(self::SESSION_REFRESH_AFTER_DAYS)) {
            $this->sessions->refresh((int) $session['id'], $this->nowPlusDays(self::SESSION_DAYS));
        }

        return $customer;
    }

    /** Sign out this browser only. */
    public function signOut(string $token): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return;
        }

        $session = $this->sessions->byTokenHash(hash('sha256', $token));

        if ($session !== null) {
            $this->sessions->delete((int) $session['id']);
        }
    }

    /** "Sign out of all devices". */
    public function signOutEverywhere(int $customerId): void
    {
        $this->sessions->deleteForCustomer($customerId);
    }

    private function phone(string $input): string
    {
        try {
            return Phone::normalise($input);
        } catch (\Throwable) {
            throw new ValidationFailed('That phone number does not look right.', ['phone' => 'Use 0803 123 4567.']);
        }
    }

    private function assertWithinLimits(string $phone, ?string $ip): void
    {
        $since = $this->nowPlusMinutes(-60);

        if ($this->otp->countForPhoneSince($phone, $since) >= self::REQUESTS_PER_PHONE_HOUR) {
            throw new ValidationFailed('Too many codes requested. Try again in an hour.');
        }

        if ($ip !== null && $ip !== '' && $this->otp->countForIpSince($ip, $since) >= self::REQUESTS_PER_IP_HOUR) {
            throw new ValidationFailed('Too many codes requested. Try again in an hour.');
        }
    }

    private function nowPlusMinutes(int $minutes): string
    {
        return \StoreKeeper\Commerce\Core\Dates::addMinutes($this->clock->nowString(), $minutes);
    }

    private function nowPlusDays(int $days): string
    {
        return \StoreKeeper\Commerce\Core\Dates::addMinutes($this->clock->nowString(), $days * 1440);
    }
}
