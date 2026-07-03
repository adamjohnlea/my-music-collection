<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Config;
use PHPUnit\Framework\TestCase;

final class ConfigValuationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['VALUATION_STALE_DAYS'], $_ENV['VALUATION_WANTLIST_GRADE']);
    }

    public function testDefaults(): void
    {
        $c = new Config();
        $this->assertSame(7, $c->getValuationStaleDays());
        $this->assertSame('Near Mint (NM or M-)', $c->getValuationWantlistGrade());
    }

    public function testOverrides(): void
    {
        $_ENV['VALUATION_STALE_DAYS'] = '30';
        $_ENV['VALUATION_WANTLIST_GRADE'] = 'Very Good Plus (VG+)';
        $c = new Config();
        $this->assertSame(30, $c->getValuationStaleDays());
        $this->assertSame('Very Good Plus (VG+)', $c->getValuationWantlistGrade());
    }
}
