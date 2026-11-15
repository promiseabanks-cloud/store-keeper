<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Money;

final class MoneyTest extends TestCase
{
    public function testItFormatsNairaAmounts(): void
    {
        self::assertSame('₦12,500.00', (new Money(1250000))->format());
        self::assertSame('₦0.05', (new Money(5))->format());
        self::assertSame('-₦1.00', (new Money(-100))->format());
    }

    public function testItParsesHumanAmounts(): void
    {
        self::assertSame(1250000, Money::fromMajor('12,500')->minor());
        self::assertSame(1250050, Money::fromMajor('₦12,500.50')->minor());
        self::assertSame(120050, Money::fromMajor('1 200.5')->minor());
        self::assertSame(125, Money::fromMajor('1.245')->minor());
        self::assertSame(-9900, Money::fromMajor('-99')->minor());
    }

    public function testItAddsSubtractsAndMultipliesInMinorUnits(): void
    {
        $price = new Money(450000);

        self::assertSame(900000, $price->multiply(2)->minor());
        self::assertSame(450100, $price->add(new Money(100))->minor());
        self::assertSame(449900, $price->subtract(new Money(100))->minor());
        self::assertTrue($price->greaterThan(new Money(449999)));
        self::assertTrue($price->lessThan(new Money(450001)));
        self::assertSame(0, $price->compare(new Money(450000)));
    }

    public function testPercentageRoundsHalfUpToTheKobo(): void
    {
        // 1.5% of ₦2,500.00 = ₦37.50 exactly
        self::assertSame(3750, (new Money(250000))->percentage(150)->minor());
        // 0.5 kobo rounds up
        self::assertSame(1, (new Money(1))->percentage(5000)->minor());
        // 1.5% of ₦3,333.00 = ₦49.995 -> ₦50.00
        self::assertSame(5000, (new Money(333300))->percentage(150)->minor());
    }

    public function testItRejectsMixingCurrencies(): void
    {
        $this->expectExceptionMessage('Cannot combine NGN with USD.');

        (new Money(100, 'NGN'))->add(new Money(100, 'USD'));
    }
}
