<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Presentation\Twig\DiscogsFilters;
use PHPUnit\Framework\TestCase;

class DiscogsFiltersTest extends TestCase
{
    private DiscogsFilters $filters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filters = new DiscogsFilters();
    }

    // ==================== getFilters() ====================

    public function testGetFiltersReturnsArray(): void
    {
        $filters = $this->filters->getFilters();

        $this->assertIsArray($filters);
        $this->assertCount(1, $filters);
    }

    public function testGetFiltersIncludesStripDiscogsSuffix(): void
    {
        $filters = $this->filters->getFilters();

        $filterNames = array_map(fn($f) => $f->getName(), $filters);
        $this->assertContains('strip_discogs_suffix', $filterNames);
    }

    // ==================== stripDiscogsSuffix(): Happy Path ====================

    public function testStripDiscogsSuffixRemovesNumericSuffix(): void
    {
        $result = $this->filters->stripDiscogsSuffix('The Beatles (2)');

        $this->assertEquals('The Beatles', $result);
    }

    public function testStripDiscogsSuffixRemovesSingleDigitSuffix(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Artist Name (3)');

        $this->assertEquals('Artist Name', $result);
    }

    public function testStripDiscogsSuffixRemovesMultiDigitSuffix(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Common Name (123)');

        $this->assertEquals('Common Name', $result);
    }

    public function testStripDiscogsSuffixKeepsNameWithoutSuffix(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Pink Floyd');

        $this->assertEquals('Pink Floyd', $result);
    }

    // ==================== stripDiscogsSuffix(): Edge Cases ====================

    public function testStripDiscogsSuffixReturnsNullForNull(): void
    {
        $result = $this->filters->stripDiscogsSuffix(null);

        $this->assertNull($result);
    }

    public function testStripDiscogsSuffixReturnsEmptyForEmpty(): void
    {
        $result = $this->filters->stripDiscogsSuffix('');

        $this->assertEquals('', $result);
    }

    public function testStripDiscogsSuffixKeepsParensNotAtEnd(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Artist (2) Name');

        $this->assertEquals('Artist (2) Name', $result);
    }

    public function testStripDiscogsSuffixKeepsNonNumericParens(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Artist (UK)');

        $this->assertEquals('Artist (UK)', $result);
    }

    public function testStripDiscogsSuffixKeepsParensWithText(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Song Title (Remix)');

        $this->assertEquals('Song Title (Remix)', $result);
    }

    public function testStripDiscogsSuffixKeepsParensWithMixedContent(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Artist (Band 2)');

        $this->assertEquals('Artist (Band 2)', $result);
    }

    public function testStripDiscogsSuffixRequiresSpaceBeforeParens(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Artist(2)');

        $this->assertEquals('Artist(2)', $result);
    }

    public function testStripDiscogsSuffixHandlesMultipleParens(): void
    {
        // Only removes the final numeric suffix
        $result = $this->filters->stripDiscogsSuffix('Artist (UK) (2)');

        $this->assertEquals('Artist (UK)', $result);
    }

    public function testStripDiscogsSuffixHandlesWhitespaceOnly(): void
    {
        $result = $this->filters->stripDiscogsSuffix('   ');

        $this->assertEquals('   ', $result);
    }

    public function testStripDiscogsSuffixHandlesZeroSuffix(): void
    {
        $result = $this->filters->stripDiscogsSuffix('Artist (0)');

        $this->assertEquals('Artist', $result);
    }
}
