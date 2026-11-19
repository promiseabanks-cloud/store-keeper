<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * Email + password sign-in (§7.4, §11.1): a shopper signs up with an email and
 * a password instead of a phone and a code, the password is hashed before it is
 * stored, and the session is what makes a wholesale customer's own prices apply.
 * The retail-versus-wholesale split still comes from the group's approval rule.
 */
final class EmailAuthTest extends TestCase
{
    private TestStore $store;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
    }

    /** @return array{name:string,group_id:int,business_name:?string,email:string} */
    private function retailForm(string $email = 'buyer@example.test'): array
    {
        return [
            'name' => 'Ada Buyer',
            'group_id' => (int) $this->store->groupNamed('Retail')['id'],
            'business_name' => null,
            'email' => $email,
        ];
    }

    public function testSignUpCreatesAnAccountAndOpensASession(): void
    {
        $result = $this->store->emailAuth->register($this->retailForm(), 'correct horse', null, '203.0.113.7', 'UA');

        self::assertArrayHasKey('token', $result);
        self::assertSame('buyer@example.test', $result['customer']['email']);
        self::assertNull($result['customer']['phone_e164'], 'A phone number is not required to sign up by email');

        $customer = $this->store->auth->customerFor($result['token']);
        self::assertNotNull($customer);
        self::assertSame((int) $result['customer']['id'], (int) $customer['id']);
        self::assertTrue($this->store->hooks->hasFired('customer/registered'));
    }

    public function testThePasswordIsHashedBeforeItIsStored(): void
    {
        $result = $this->store->emailAuth->register($this->retailForm(), 'super secret');
        $row = $this->store->customerRepository->customerByEmail('buyer@example.test');

        self::assertNotNull($row);
        self::assertNotSame('super secret', $row['password_hash'], 'The plain password must never be stored');
        self::assertTrue(password_verify('super secret', (string) $row['password_hash']));
    }

    public function testSignUpKeepsAPhoneNumberWhenGiven(): void
    {
        $result = $this->store->emailAuth->register($this->retailForm(), 'correct horse', '0803 123 4567');

        self::assertSame('+2348031234567', $result['customer']['phone_e164']);
    }

    public function testADuplicateEmailIsRejected(): void
    {
        $this->store->emailAuth->register($this->retailForm(), 'first password');

        $this->expectException(ValidationFailed::class);
        $this->store->emailAuth->register($this->retailForm(), 'another password');
    }

    public function testAShortPasswordIsRejected(): void
    {
        $this->expectException(ValidationFailed::class);
        $this->store->emailAuth->register($this->retailForm(), 'short');
    }

    public function testCorrectCredentialsOpenASessionThatResolvesTheCustomer(): void
    {
        $this->store->emailAuth->register($this->retailForm(), 'correct horse');

        $result = $this->store->emailAuth->login('BUYER@example.test', 'correct horse');
        $customer = $this->store->auth->customerFor($result['token']);

        self::assertNotNull($customer);
        self::assertSame('buyer@example.test', $customer['email']);

        $this->store->auth->signOut($result['token']);
        self::assertNull($this->store->auth->customerFor($result['token']));
    }

    public function testAWrongPasswordIsRefusedWithTheSameMessageAsAnUnknownEmail(): void
    {
        $this->store->emailAuth->register($this->retailForm(), 'correct horse');

        $wrong = null;
        try {
            $this->store->emailAuth->login('buyer@example.test', 'not the password');
        } catch (ValidationFailed $error) {
            $wrong = $error->getMessage();
        }

        $unknown = null;
        try {
            $this->store->emailAuth->login('nobody@example.test', 'whatever here');
        } catch (ValidationFailed $error) {
            $unknown = $error->getMessage();
        }

        self::assertNotNull($wrong);
        self::assertSame($wrong, $unknown, 'Sign-in must not reveal which emails have accounts');
    }

    public function testWholesaleSignUpThroughAManualGroupIsPendingAtRetailPrices(): void
    {
        $wholesale = $this->store->groupNamed('Wholesale');
        $retail = $this->store->groupNamed('Retail');
        $this->store->db->update(
            $this->store->db->prefix() . 'customer_groups',
            ['approval' => 'manual'],
            ['id' => (int) $wholesale['id']]
        );

        $result = $this->store->emailAuth->register([
            'name' => 'Chidi Trader',
            'group_id' => (int) $wholesale['id'],
            'business_name' => 'Chidi Stores',
            'email' => 'chidi@example.test',
        ], 'correct horse');

        self::assertSame('pending', $result['customer']['approval_status']);
        self::assertSame((int) $retail['id'], (int) $result['customer']['group_id']);
        self::assertSame((int) $wholesale['id'], (int) $result['customer']['requested_group_id']);
    }

    public function testARejectedCustomerCannotSignIn(): void
    {
        $result = $this->store->emailAuth->register($this->retailForm(), 'correct horse');
        $this->store->customers->reject((int) $result['customer']['id'], $this->store->actor());

        $this->expectException(ValidationFailed::class);
        $this->store->emailAuth->login('buyer@example.test', 'correct horse');
    }
}
