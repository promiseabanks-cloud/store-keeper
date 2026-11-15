<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Orders;

/**
 * Payment status is computed from payment records, never set by hand (§12.2).
 */
final class PaymentStatus
{
    public const UNPAID = 'unpaid';
    public const AWAITING_CONFIRMATION = 'awaiting_confirmation';
    public const PARTIALLY_PAID = 'partially_paid';
    public const PAID = 'paid';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const REFUNDED = 'refunded';
    public const PAY_ON_DELIVERY = 'pay_on_delivery';

    /**
     * @param list<array{status:string,amount_kobo:int}> $payments
     */
    public static function derive(
        int $totalKobo,
        int $paidKobo,
        int $refundedKobo = 0,
        array $payments = [],
        bool $payOnDelivery = false,
    ): string {
        if ($refundedKobo > 0) {
            return $paidKobo > 0 && $refundedKobo >= $totalKobo
                ? self::REFUNDED
                : self::PARTIALLY_REFUNDED;
        }

        if ($totalKobo > 0 && $paidKobo >= $totalKobo) {
            return self::PAID;
        }

        if ($paidKobo > 0) {
            return self::PARTIALLY_PAID;
        }

        foreach ($payments as $payment) {
            if (($payment['status'] ?? '') === 'awaiting_confirmation') {
                return self::AWAITING_CONFIRMATION;
            }
        }

        return $payOnDelivery ? self::PAY_ON_DELIVERY : self::UNPAID;
    }
}
