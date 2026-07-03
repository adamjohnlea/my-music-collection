<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\InsuranceManifest;
use PHPUnit\Framework\TestCase;

final class InsuranceManifestTest extends TestCase
{
    public function testCsvHasHeaderRowsAndTotals(): void
    {
        $rows = [
            ['artist' => 'Devo', 'title' => 'Freedom Of Choice', 'condition_used' => 'Very Good Plus (VG+)', 'value' => 18.5, 'currency' => 'GBP', 'source' => 'suggestion'],
            ['artist' => 'Cabaret Voltaire', 'title' => 'Red Mecca', 'condition_used' => null, 'value' => 9.0, 'currency' => 'GBP', 'source' => 'lowest_listed'],
        ];
        $totals = ['total' => 27.5, 'item_count' => 2, 'valued_count' => 2, 'currency' => 'GBP'];

        $csv = InsuranceManifest::toCsv($rows, $totals);

        $this->assertStringContainsString('Artist,Title,Condition,Value,Currency,Source', $csv);
        $this->assertStringContainsString('Devo,Freedom Of Choice,Very Good Plus (VG+),18.50,GBP,suggestion', $csv);
        $this->assertStringContainsString('Total,,,27.50,GBP,', $csv);
        $this->assertStringContainsString('Coverage,2 of 2 valued', $csv);
    }

    public function testFieldsWithCommasAreQuoted(): void
    {
        $rows = [['artist' => 'Earth, Wind & Fire', 'title' => 'I Am', 'condition_used' => 'Mint (M)', 'value' => 5.0, 'currency' => 'GBP', 'source' => 'suggestion']];
        $totals = ['total' => 5.0, 'item_count' => 1, 'valued_count' => 1, 'currency' => 'GBP'];
        $csv = InsuranceManifest::toCsv($rows, $totals);
        $this->assertStringContainsString('"Earth, Wind & Fire"', $csv);
    }

    public function testNullValueRendersAsEmptyField(): void
    {
        $rows = [['artist' => 'Pink Floyd', 'title' => 'The Wall', 'condition_used' => 'Excellent (EX)', 'value' => null, 'currency' => 'GBP', 'source' => 'suggestion']];
        $totals = ['total' => 0.0, 'item_count' => 1, 'valued_count' => 0, 'currency' => 'GBP'];
        $csv = InsuranceManifest::toCsv($rows, $totals);
        $this->assertStringContainsString('Pink Floyd,The Wall,Excellent (EX),,GBP,suggestion', $csv);
    }

    public function testEmbeddedDoubleQuoteIsDoubled(): void
    {
        $rows = [['artist' => 'AC/DC "Live"', 'title' => 'Live at Donington', 'condition_used' => 'Very Good Plus (VG+)', 'value' => 12.5, 'currency' => 'GBP', 'source' => 'suggestion']];
        $totals = ['total' => 12.5, 'item_count' => 1, 'valued_count' => 1, 'currency' => 'GBP'];
        $csv = InsuranceManifest::toCsv($rows, $totals);
        $this->assertStringContainsString('"AC/DC ""Live"""', $csv);
    }

    public function testFormulaInjectionArtistIsPrefixedWithSingleQuote(): void
    {
        $rows = [['artist' => '=1+2', 'title' => 'Exploit', 'condition_used' => 'Mint (M)', 'value' => 9.99, 'currency' => 'GBP', 'source' => 'suggestion']];
        $totals = ['total' => 9.99, 'item_count' => 1, 'valued_count' => 1, 'currency' => 'GBP'];
        $csv = InsuranceManifest::toCsv($rows, $totals);
        // Artist field must be prefixed with a single quote to neutralize the formula.
        $this->assertStringContainsString("'=1+2", $csv);
        // Numeric value column must not be altered.
        $this->assertStringContainsString('9.99', $csv);
        // Header row must not be altered.
        $this->assertStringContainsString('Artist,Title,Condition,Value,Currency,Source', $csv);
    }
}
