<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\CurrencyFormat;
use PHPUnit\Framework\TestCase;

final class CurrencyFormatTest extends TestCase
{
    public function testCommonSymbols(): void
    {
        $this->assertSame('$', CurrencyFormat::symbol('USD'));
        $this->assertSame('£', CurrencyFormat::symbol('GBP'));
        $this->assertSame('€', CurrencyFormat::symbol('EUR'));
        $this->assertSame('¥', CurrencyFormat::symbol('JPY'));
    }

    public function testDollarFamilyIsDisambiguated(): void
    {
        $this->assertSame('A$', CurrencyFormat::symbol('AUD'));
        $this->assertSame('CA$', CurrencyFormat::symbol('CAD'));
    }

    public function testCaseInsensitive(): void
    {
        $this->assertSame('$', CurrencyFormat::symbol('usd'));
    }

    public function testUnknownFallsBackToUppercasedCode(): void
    {
        $this->assertSame('SEK', CurrencyFormat::symbol('sek'));
        $this->assertSame('XYZ', CurrencyFormat::symbol('XYZ'));
    }

    public function testNullOrEmptyReturnsEmptyString(): void
    {
        $this->assertSame('', CurrencyFormat::symbol(null));
        $this->assertSame('', CurrencyFormat::symbol(''));
    }
}
