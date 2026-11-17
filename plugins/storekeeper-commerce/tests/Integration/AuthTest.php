<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * Phone + one-time-code sign-in (§22.5): codes are single-use, hashed, short
 * lived and rate limited, and a session is what makes a wholesale customer's
 * own prices apply at checkout.
 */
final class AuthTest extends TestCase
{
    private const PHONE = '+2348031234567';

    private TestStore $store;

    private int $locationId;

    private int $variantId;

    private int $wholesaleListId;

    /** @var array<string,mixed> */
    private array $customer;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
        $this->locationId = (int) $this->store->seedLocation()['id'];
        $this->variantId = (int) $this->store->seedProduct($this->locationId, 500000, 20)['variant']['id'];
        $this->wholesaleListId = (int) $this->store->groupNamed('Wholesale')['price_list_id'];

        $this->store->setPrice($this->wholesaleListId, $this->variantId, 400000);
        $this->customer = $this->store->wholesaleCustomer();
    }

    public function testACodeIsHashedBeforeItIsStoredAndSentOnce(): void
    {
        $result = $this->store->auth->request('0803 123 4567', '203.0.113.7');

        self::assertSame(self::PHONE, $result['phone']);
        self::assertTrue($result['sent']);
        self::assertSame(1, $this->store->otpSender->count());

        $code = $this->store->otpSender->lastCode(self::PHONE);
        self::assertNotNull($code);
        self::assertMatchesRegularExpression('/^[0-9]{6}$/', $code);

        $row = $this->store->otpRepository->latest(self::PHONE);
        self::assertNotNull($row);
        self::assertNotSame($code, $row['code_hash'], 'The plain code must never be stored');
        self::assertTrue(password_verify($code, (string) $row['code_hash']));
        self::assertSame('203.0.113.7', $row['ip']);
        self::assertSame('2026-11-15 09:10:00.000', (string) $row['expires_at']);
        self::assertNull($row['consumed_at']);
    }

    public function testAnUnknownNumberIsAnsweredTheSameWayAndGetsNoCode(): void
    {
        $result = $this->store->auth->request('0803 999 0000');

        self::assertFalse($result['sent'], 'The answer must not reveal whether the number has an account');
        self::assertSame(0, $this->store->otpSender->count());
    }

    public function testACorrectCodeOpensASessionAndResolvesTheCustomer(): void
    {
        $token = $this->signIn(self::PHONE);
        $session = $this->store->sessionRepository->byTokenHash(hash('sha256', $token));

        self::assertNotNull($session);
        self::assertSame(hash('sha256', $token), $session['token_hash']);
        self::assertNotSame($token, $session['token_hash']);

        $customer = $this->store->auth->customerFor($token);
        self::assertNotNull($customer);
        self::assertSame(self::PHONE, $customer['phone_e164']);

        $this->store->auth->signOut($token);
        self::assertNull($this->store->auth->customerFor($token));
    }

    public function testACodeCannotBeUsedTwice(): void
    {
        $token = $this->signIn(self::PHONE);
        self::assertNotNull($this->store->auth->customerFor($token));

        $code = $this->store->otpSender->lastCode(self::PHONE) ?? '';

        try {
            $this->store->auth->verify(self::PHONE, $code);
            self::fail('A used code must not open a second session.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('already been used', $error->getMessage());
        }
    }

    public function testFiveWrongGuessesRetireTheCode(): void
    {
        $this->store->auth->request(self::PHONE);
        $code = $this->store->otpSender->lastCode(self::PHONE) ?? '';
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $this->store->auth->verify(self::PHONE, $wrong);
                self::fail('A wrong code must be refused.');
            } catch (ValidationFailed $error) {
                self::assertStringContainsString('not correct', $error->getMessage());
            }
        }

        self::assertSame(5, (int) $this->store->otpRepository->latest(self::PHONE)['attempts']);

        try {
            $this->store->auth->verify(self::PHONE, $code);
            self::fail('The correct code must be dead after five failures.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('Too many attempts', $error->getMessage());
        }
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $this->store->auth->request(self::PHONE);
        $code = $this->store->otpSender->lastCode(self::PHONE) ?? '';
        $this->store->clock->advance('11 minutes');

        try {
            $this->store->auth->verify(self::PHONE, $code);
            self::fail('A code must not outlive its ten minutes.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('expired', $error->getMessage());
        }
    }

    public function testAPhoneCanAskForFiveCodesAnHour(): void
    {
        for ($request = 1; $request <= 5; $request++) {
            $this->store->auth->request(self::PHONE);
        }

        try {
            $this->store->auth->request(self::PHONE);
            self::fail('A sixth code within the hour must be refused.');
        } catch (ValidationFailed $error) {
            self::assertStringContainsString('Too many codes', $error->getMessage());
        }
    }

    public function testSigningOutEverywhereKillsEverySession(): void
    {
        $first = $this->signIn(self::PHONE);
        $second = $this->signIn(self::PHONE);
        self::assertNotNull($this->store->auth->customerFor($first));
        self::assertNotNull($this->store->auth->customerFor($second));

        $this->store->auth->signOutEverywhere((int) $this->customer['id']);

        self::assertNull($this->store->auth->customerFor($first));
        self::assertNull($this->store->auth->customerFor($second));
    }

    public function testASessionSlidesForwardWhileItIsUsed(): void
    {
        $token = $this->signIn(self::PHONE);

        $this->store->clock->advance('80 days');

        self::assertNotNull($this->store->auth->customerFor($token), 'A session in use stays valid');

        $expires = (string) $this->store->sessionRepository->byTokenHash(hash('sha256', $token))['expires_at'];
        self::assertGreaterThanOrEqual(
            \StoreKeeper\Commerce\Core\Dates::addMinutes($this->store->clock->nowString(), 89 * 1440),
            $expires
        );
    }

    public function testSigningInMergesAnotherDeviceCartAndKeepsItsPrices(): void
    {
        $customer = $this->customer;
        $guest = $this->store->cart->open(hash('sha256', 'guest-a'));
        $this->store->cart->add($guest, ['variant_id' => $this->variantId, 'qty' => 2], null);

        // The same customer on another device, already signed in.
        $other = $this->store->cart->open(hash('sha256', 'phone-a'), (int) $customer['id']);
        $this->store->cart->add($other, ['variant_id' => $this->variantId, 'qty' => 1], $customer);

        $claimed = $this->store->cart->claim($guest, (int) $customer['id']);
        $summary = $this->store->cart->summary($claimed, $customer);

        self::assertSame((int) $customer['id'], (int) $claimed['customer_id']);
        self::assertSame(3, $summary['item_count'], 'Lines from the other device are folded in');
        self::assertSame(400000, $summary['lines'][0]['unit_price_kobo'], 'The wholesale price now applies');
        self::assertSame(1200000, $summary['subtotal_kobo']);

        $leftBehind = $this->store->cart->find(hash('sha256', 'phone-a'));
        self::assertSame('abandoned', $leftBehind['status']);
    }

    public function testASignedInWholesaleCustomerChecksOutAtWholesalePrices(): void
    {
        $customer = $this->customer;
        $cart = $this->store->cart->open(hash('sha256', 'wholesale-checkout'), (int) $customer['id']);
        $this->store->cart->add($cart, ['variant_id' => $this->variantId, 'qty' => 3], $customer);

        $result = $this->store->checkout->place([
            'name' => 'Ada Obi',
            'phone' => '08031234567',
            'shipping' => 'pickup',
            'payment_method' => 'bank_transfer',
        ], hash('sha256', 'wholesale-checkout'), $customer);

        $order = $result['order'];

        self::assertSame((int) $customer['id'], (int) $order['customer_id']);
        self::assertSame(1200000, (int) $order['total_kobo'], '3 × ₦4,000 wholesale, not ₦5,000 retail');
        self::assertSame(400000, (int) $result['items'][0]['unit_price_kobo']);
        self::assertSame('group', $result['items'][0]['price_source']);
    }

    /** Request a code, then verify it, returning the session token. */
    private function signIn(string $phone): string
    {
        $this->store->auth->request($phone);
        $code = $this->store->otpSender->lastCode(self::PHONE) ?? '';

        return (string) $this->store->auth->verify($phone, $code)['token'];
    }
}
