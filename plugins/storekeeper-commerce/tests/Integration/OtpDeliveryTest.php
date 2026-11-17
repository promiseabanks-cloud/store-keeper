<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Integration;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Storefront\Otp\EmailOtpSender;
use StoreKeeper\Commerce\Tests\Support\InMemoryChannel;
use StoreKeeper\Commerce\Tests\Support\InMemoryOtpSender;
use StoreKeeper\Commerce\Tests\Support\TestStore;

/**
 * OTP delivery (§7.4, §22.5): a code goes to the customer's email, and a
 * customer with no address or a refused message still leaves the operator
 * something readable instead of a dead end.
 */
final class OtpDeliveryTest extends TestCase
{
    private TestStore $store;

    protected function setUp(): void
    {
        $this->store = TestStore::create();
        $this->store->seedGroups();
    }

    public function testASignInCodeIsAddressedToTheAddressOnFile(): void
    {
        $this->store->wholesaleCustomer('08031234567');

        $result = $this->store->auth->request('08031234567');

        self::assertTrue($result['sent']);
        self::assertSame('ada@example.test', $result['email']);
        self::assertSame('email', $result['channel'], 'The page may only say "emailed" for this channel');

        self::assertCount(1, $this->store->otpSender->sent);
        self::assertSame('ada@example.test', $this->store->otpSender->sent[0]['email']);
        self::assertSame('login', $this->store->otpSender->sent[0]['purpose']);

        $row = $this->store->otpRepository->latestFor($result['phone'], 'login');
        self::assertNotNull($row);
        self::assertSame('email', $row['channel']);
    }

    public function testASignUpCodeIsAddressedToTheAddressTheShopperTyped(): void
    {
        $result = $this->store->auth->requestRegistration('08035550000', null, 'buyer@example.test');

        self::assertSame('buyer@example.test', $this->store->otpSender->lastEmail($result['phone']));
        self::assertSame('email', $result['channel']);
    }

    public function testACustomerWithNoAddressFallsBackToTheLogSender(): void
    {
        $channel = new InMemoryChannel();
        $fallback = new InMemoryOtpSender();

        $used = (new EmailOtpSender($channel, $fallback))->send('+2348031234567', '123456', 'login', null);

        self::assertSame('log', $used);
        self::assertSame([], $channel->sent, 'Nothing is emailed without an address');
        self::assertSame('123456', $fallback->lastCode('+2348031234567'));
    }

    public function testARefusedEmailStillLeavesTheCodeWithTheOperator(): void
    {
        $channel = new InMemoryChannel();
        $channel->failuresRemaining = 1;
        $fallback = new InMemoryOtpSender();

        $used = (new EmailOtpSender($channel, $fallback))
            ->send('+2348031234567', '654321', 'login', 'ada@example.test');

        self::assertSame('log', $used);
        self::assertSame([], $channel->sent);
        self::assertSame('654321', $fallback->lastCode('+2348031234567'));
        self::assertNull($fallback->lastEmail('+2348031234567'), 'The fallback reports itself as the log channel');
    }

    public function testTheEmailCarriesTheCodeAndThePurpose(): void
    {
        $channel = new InMemoryChannel();

        $used = (new EmailOtpSender($channel, new InMemoryOtpSender()))
            ->send('+2348031234567', '111222', 'register', 'new@example.test');

        self::assertSame('email', $used);
        self::assertCount(1, $channel->sent);
        self::assertSame('new@example.test', $channel->sent[0]['to']);
        self::assertStringContainsString('sign-up', $channel->sent[0]['subject']);
        self::assertStringContainsString('111222', $channel->sent[0]['body']);
        self::assertStringContainsString('10 minutes', $channel->sent[0]['body']);
    }
}
