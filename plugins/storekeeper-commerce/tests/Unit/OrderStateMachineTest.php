<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Orders\Exception\InvalidTransition;
use StoreKeeper\Commerce\Orders\FulfilmentStatus;
use StoreKeeper\Commerce\Orders\OrderStatus;
use StoreKeeper\Commerce\Orders\PaymentStatus;

final class OrderStateMachineTest extends TestCase
{
    public function testValidTransitions(): void
    {
        self::assertTrue(OrderStatus::canTransition(OrderStatus::DRAFT, OrderStatus::OPEN));
        self::assertTrue(OrderStatus::canTransition(OrderStatus::DRAFT, OrderStatus::CANCELLED));
        self::assertTrue(OrderStatus::canTransition(OrderStatus::OPEN, OrderStatus::COMPLETED));
        self::assertTrue(OrderStatus::canTransition(OrderStatus::OPEN, OrderStatus::CANCELLED));
    }

    public function testInvalidTransitions(): void
    {
        self::assertFalse(OrderStatus::canTransition(OrderStatus::COMPLETED, OrderStatus::OPEN));
        self::assertFalse(OrderStatus::canTransition(OrderStatus::CANCELLED, OrderStatus::OPEN));
        self::assertFalse(OrderStatus::canTransition(OrderStatus::DRAFT, OrderStatus::COMPLETED));
        self::assertFalse(OrderStatus::canTransition(OrderStatus::OPEN, OrderStatus::DRAFT));
    }

    public function testItThrowsOnInvalidTransition(): void
    {
        $this->expectException(InvalidTransition::class);

        OrderStatus::assertTransition(OrderStatus::COMPLETED, OrderStatus::OPEN);
    }

    public function testTerminalStatuses(): void
    {
        self::assertTrue(OrderStatus::isTerminal(OrderStatus::COMPLETED));
        self::assertTrue(OrderStatus::isTerminal(OrderStatus::CANCELLED));
        self::assertFalse(OrderStatus::isTerminal(OrderStatus::OPEN));
        self::assertFalse(OrderStatus::isTerminal(OrderStatus::DRAFT));
    }

    public function testPaymentStatusIsDerivedFromPayments(): void
    {
        self::assertSame(PaymentStatus::UNPAID, PaymentStatus::derive(100000, 0));
        self::assertSame(PaymentStatus::PARTIALLY_PAID, PaymentStatus::derive(100000, 40000));
        self::assertSame(PaymentStatus::PAID, PaymentStatus::derive(100000, 100000));
        self::assertSame(
            PaymentStatus::AWAITING_CONFIRMATION,
            PaymentStatus::derive(100000, 0, 0, [['status' => 'awaiting_confirmation', 'amount_kobo' => 100000]])
        );
        self::assertSame(PaymentStatus::PAY_ON_DELIVERY, PaymentStatus::derive(100000, 0, 0, [], true));
        self::assertSame(PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::derive(100000, 100000, 30000));
        self::assertSame(PaymentStatus::REFUNDED, PaymentStatus::derive(100000, 100000, 100000));
    }

    public function testFulfilmentStatusIsDerivedFromLines(): void
    {
        self::assertSame(
            FulfilmentStatus::UNFULFILLED,
            FulfilmentStatus::derive([['qty_pieces' => 10, 'qty_fulfilled_pieces' => 0]])
        );
        self::assertSame(
            FulfilmentStatus::PARTIALLY_FULFILLED,
            FulfilmentStatus::derive([['qty_pieces' => 10, 'qty_fulfilled_pieces' => 4]])
        );
        self::assertSame(
            FulfilmentStatus::FULFILLED,
            FulfilmentStatus::derive([
                ['qty_pieces' => 10, 'qty_fulfilled_pieces' => 10],
                ['qty_pieces' => 2, 'qty_fulfilled_pieces' => 2],
            ])
        );
        self::assertSame(
            FulfilmentStatus::RETURNED,
            FulfilmentStatus::derive([['qty_pieces' => 3, 'qty_fulfilled_pieces' => 3]], 3)
        );
    }
}
