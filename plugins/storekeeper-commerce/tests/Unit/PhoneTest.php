<?php

declare(strict_types=1);

namespace StoreKeeper\Commerce\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StoreKeeper\Commerce\Core\Exception\InvalidPhone;
use StoreKeeper\Commerce\Core\Phone;

final class PhoneTest extends TestCase
{
    #[DataProvider('nigerianNumbers')]
    public function testItNormalisesNigerianNumbers(string $input, string $expected): void
    {
        self::assertSame($expected, Phone::normalise($input));
    }

    /** @return iterable<string,array{string,string}> */
    public static function nigerianNumbers(): iterable
    {
        yield 'local with spaces' => ['0803 123 4567', '+2348031234567'];
        yield 'local with dashes' => ['0803-123-4567', '+2348031234567'];
        yield 'country code no plus' => ['2348031234567', '+2348031234567'];
        yield 'international' => ['+234 803 123 4567', '+2348031234567'];
        yield 'international access prefix' => ['002348031234567', '+2348031234567'];
        yield 'bare national' => ['8031234567', '+2348031234567'];
        yield 'new 091 range' => ['0913 000 1111', '+2349130001111'];
    }

    public function testItRejectsInvalidNumbers(): void
    {
        $this->expectException(InvalidPhone::class);
        Phone::normalise('0803 12');
    }

    public function testItRejectsEmptyInput(): void
    {
        $this->expectException(InvalidPhone::class);
        Phone::normalise('   ');
    }

    public function testItRejectsNigerianNumbersWithWrongPrefix(): void
    {
        $this->expectException(InvalidPhone::class);
        Phone::normalise('+234 123 456 7890');
    }

    public function testDisplayAndLocalForms(): void
    {
        self::assertSame('+234 803 123 4567', Phone::display('+2348031234567'));
        self::assertSame('08031234567', Phone::local('+2348031234567'));
        self::assertTrue(Phone::isValid('08031234567'));
        self::assertFalse(Phone::isValid('12345'));
    }
}
