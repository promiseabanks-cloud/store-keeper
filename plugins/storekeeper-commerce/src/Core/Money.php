<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Core;

use InvalidArgumentException;
use StoreKeeper\Commerce\Core\Exception\ValidationFailed;

/**
 * Immutable money value object: an integer count of the smallest currency unit
 * (kobo for NGN) plus a currency code. Never uses floats in arithmetic (§19.2).
 */
final class Money
{
    /** Currencies whose minor unit is 1/100, with the display symbol. */
    private const SYMBOLS = [
        'NGN' => '₦',
        'USD' => '$',
        'GBP' => '£',
        'EUR' => '€',
    ];

    private readonly int $minor;

    private readonly string $currency;

    public function __construct(int $minor, string $currency = Brand::CURRENCY)
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid currency code "%s".', $currency));
        }

        $this->minor = $minor;
        $this->currency = $currency;
    }

    public static function zero(string $currency = Brand::CURRENCY): self
    {
        return new self(0, $currency);
    }

    public static function fromMinor(int $minor, string $currency = Brand::CURRENCY): self
    {
        return new self($minor, $currency);
    }

    /**
     * Parse a human amount ("12,500", "12,500.50", "₦1 200.5") into kobo.
     * More than two decimal places round half up.
     */
    public static function fromMajor(string|int $major, string $currency = Brand::CURRENCY): self
    {
        $raw = is_int($major) ? (string) $major : trim($major);
        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');
        $raw = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], '', $raw);
        $raw = preg_replace('/[^0-9.]/', '', $raw) ?? '';

        if ($raw === '' || substr_count($raw, '.') > 1) {
            throw new InvalidArgumentException(sprintf('Cannot parse money amount "%s".', (string) $major));
        }

        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 3, '0');
        $minor = (int) $whole * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $minor++;
        }

        return new self($negative ? -$minor : $minor, $currency);
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Multiply by a whole quantity (line totals, packs). */
    public function multiply(int $quantity): self
    {
        return new self($this->minor * $quantity, $this->currency);
    }

    /**
     * Percentage of this amount, in basis points (100 = 1%), rounded half up
     * to the kobo (§19.2, D-008). Negative amounts round symmetrically.
     */
    public function percentage(int $basisPoints): self
    {
        $product = $this->minor * $basisPoints;
        $sign = $product < 0 ? -1 : 1;
        $rounded = intdiv(abs($product) + 5000, 10000) * $sign;

        return new self($rounded, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor > $other->minor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minor < $other->minor;
    }

    /** Sort comparator: -1, 0 or 1. */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    /** e.g. "₦12,500.00"; pass false for a bare "12500.00". */
    public function format(bool $withSymbol = true): string
    {
        $absolute = abs($this->minor);
        $body = number_format(intdiv($absolute, 100), 0, '.', ',')
            . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        $prefix = $this->minor < 0 ? '-' : '';
        $symbol = self::SYMBOLS[$this->currency] ?? ($this->currency . ' ');

        return $withSymbol ? $prefix . $symbol . $body : $prefix . $body;
    }

    /** @return array{amount_kobo:int,currency:string} */
    public function toArray(): array
    {
        return ['amount_kobo' => $this->minor, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new ValidationFailed(sprintf(
                'Cannot combine %s with %s.',
                $this->currency,
                $other->currency
            ));
        }
    }
}
