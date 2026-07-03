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
        $this->assertCount(3, $filters);
    }

    public function testGetFiltersIncludesDiscogsMarkup(): void
    {
        $filterNames = array_map(fn($f) => $f->getName(), $this->filters->getFilters());
        $this->assertContains('discogs_markup', $filterNames);
    }

    // ==================== discogsMarkup() ====================

    public function testDiscogsMarkupReturnsEmptyForNullOrEmpty(): void
    {
        $this->assertSame('', $this->filters->discogsMarkup(null));
        $this->assertSame('', $this->filters->discogsMarkup(''));
    }

    public function testDiscogsMarkupLeavesPlainTextEscaped(): void
    {
        $this->assertSame(
            'Larry Mullen Jr plays Yamaha Drums',
            $this->filters->discogsMarkup('Larry Mullen Jr plays Yamaha Drums')
        );
    }

    public function testDiscogsMarkupNamedLabelBecomesPlainName(): void
    {
        $this->assertSame(
            'Crowd funded through Bandcamp',
            $this->filters->discogsMarkup('Crowd funded through [l=Bandcamp]')
        );
    }

    public function testDiscogsMarkupReleaseReferenceBecomesLink(): void
    {
        $result = $this->filters->discogsMarkup('See [r=1678402] for the clear rim');
        $this->assertStringContainsString('href="https://www.discogs.com/release/1678402"', $result);
        $this->assertStringContainsString('>release</a>', $result);
    }

    public function testDiscogsMarkupMasterAndBareIdReferences(): void
    {
        $result = $this->filters->discogsMarkup('versions of [m=48830] and [r2170344]');
        $this->assertStringContainsString('https://www.discogs.com/master/48830', $result);
        $this->assertStringContainsString('https://www.discogs.com/release/2170344', $result);
    }

    public function testDiscogsMarkupBoldAndItalic(): void
    {
        $this->assertSame(
            '<strong>heavy</strong> and <em>light</em>',
            $this->filters->discogsMarkup('[b]heavy[/b] and [i]light[/i]')
        );
    }

    public function testDiscogsMarkupEscapesHtmlToPreventInjection(): void
    {
        $result = $this->filters->discogsMarkup('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testDiscogsMarkupRejectsUnsafeUrlScheme(): void
    {
        $result = $this->filters->discogsMarkup('[url=javascript:alert(1)]click[/url]');
        $this->assertStringNotContainsString('href', $result);
        $this->assertStringContainsString('click', $result);
    }

    public function testDiscogsMarkupAllowsHttpLink(): void
    {
        $result = $this->filters->discogsMarkup('[url=https://example.com]site[/url]');
        $this->assertStringContainsString('href="https://example.com"', $result);
        $this->assertStringContainsString('>site</a>', $result);
    }

    public function testGetFiltersIncludesStripDiscogsSuffix(): void
    {
        $filters = $this->filters->getFilters();

        $filterNames = array_map(fn($f) => $f->getName(), $filters);
        $this->assertContains('strip_discogs_suffix', $filterNames);
    }

    public function testGetFiltersIncludesCurrencySymbol(): void
    {
        $filters = $this->filters->getFilters();

        $filterNames = array_map(fn($f) => $f->getName(), $filters);
        $this->assertContains('currency_symbol', $filterNames);
    }

    public function testCurrencySymbolDelegatesToFormatter(): void
    {
        $this->assertEquals('$', $this->filters->currencySymbol('USD'));
        $this->assertEquals('£', $this->filters->currencySymbol('GBP'));
        $this->assertEquals('SEK', $this->filters->currencySymbol('SEK'));
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
