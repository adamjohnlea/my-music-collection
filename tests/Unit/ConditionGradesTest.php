<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\ConditionGrades;
use PHPUnit\Framework\TestCase;

final class ConditionGradesTest extends TestCase
{
    public function testNormalizeExactMatch(): void
    {
        $this->assertSame('Very Good Plus (VG+)', ConditionGrades::normalize('Very Good Plus (VG+)'));
    }

    public function testNormalizeTrimsAndCollapsesWhitespace(): void
    {
        $this->assertSame('Near Mint (NM or M-)', ConditionGrades::normalize('  Near Mint (NM or M-)  '));
    }

    public function testNormalizeUnknownReturnsNull(): void
    {
        $this->assertNull(ConditionGrades::normalize('Brand New'));
        $this->assertNull(ConditionGrades::normalize(''));
        $this->assertNull(ConditionGrades::normalize(null));
    }

    public function testMediaConditionFromNotesPicksFieldId1(): void
    {
        $notes = json_encode([
            ['field_id' => 1, 'value' => 'Very Good Plus (VG+)'],
            ['field_id' => 2, 'value' => 'Near Mint (NM or M-)'],
            ['field_id' => 3, 'value' => 'nice copy'],
        ]);
        $this->assertSame('Very Good Plus (VG+)', ConditionGrades::mediaConditionFromNotes($notes));
    }

    public function testMediaConditionFromNotesHandlesMissingOrBadInput(): void
    {
        $this->assertNull(ConditionGrades::mediaConditionFromNotes(null));
        $this->assertNull(ConditionGrades::mediaConditionFromNotes('not json'));
        $this->assertNull(ConditionGrades::mediaConditionFromNotes(json_encode([
            ['field_id' => 2, 'value' => 'Near Mint (NM or M-)'],
        ])));
    }
}
