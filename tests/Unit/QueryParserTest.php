<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Search\QueryParser;
use PHPUnit\Framework\TestCase;

class QueryParserTest extends TestCase
{
    private QueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new QueryParser();
    }

    // ==================== Happy Path Tests ====================

    public function testParsesSimpleSearchTerm(): void
    {
        // Arrange
        $query = 'beatles';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertStringContainsString('beatles', $result['match']);
        $this->assertNull($result['year_from']);
        $this->assertNull($result['year_to']);
        $this->assertFalse($result['is_discogs']);
    }

    public function testParsesArtistFilter(): void
    {
        // Arrange
        $query = 'artist:Beatles';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertStringContainsString('artist:', $result['match']);
        $this->assertEquals('Beatles', $result['filters']['artist']);
        $this->assertCount(1, $result['chips']);
        $this->assertEquals('Artist: Beatles', $result['chips'][0]['label']);
    }

    public function testParsesSingleYearFilter(): void
    {
        // Arrange
        $query = 'year:1985';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertEquals(1985, $result['year_from']);
        $this->assertEquals(1985, $result['year_to']);
        $this->assertEquals('1985', $result['filters']['year']);
    }

    public function testParsesYearRangeFilter(): void
    {
        // Arrange
        $query = 'year:1980..1989';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertEquals(1980, $result['year_from']);
        $this->assertEquals(1989, $result['year_to']);
        $this->assertEquals('1980..1989', $result['filters']['year']);
    }

    public function testParsesQuotedPhrase(): void
    {
        // Arrange
        $query = '"Abbey Road"';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertStringContainsString('"abbey road"', $result['match']);
    }

    public function testParsesMultipleFilters(): void
    {
        // Arrange
        $query = 'artist:Beatles year:1969 label:Apple';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertEquals('Beatles', $result['filters']['artist']);
        $this->assertEquals(1969, $result['year_from']);
        $this->assertEquals('Apple', $result['filters']['label']);
        $this->assertCount(3, $result['chips']);
    }

    public function testParsesMasterFilter(): void
    {
        // Arrange
        $query = 'master:12345';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertEquals(12345, $result['master_id']);
        $this->assertEquals('12345', $result['filters']['master']);
    }

    public function testParsesDiscogsSearchPrefix(): void
    {
        // Arrange
        $query = 'discogs: beatles';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertTrue($result['is_discogs']);
        $this->assertStringContainsString('beatles', $result['match']);
    }

    public function testParsesGenreFilter(): void
    {
        // Arrange
        $query = 'genre:jazz';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertStringContainsString('genre_style_text:', $result['match']);
        $this->assertEquals('jazz', $result['filters']['genre']);
    }

    public function testParsesNotesFilter(): void
    {
        // Arrange
        $query = 'notes:vinyl';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        // Notes searches both release_notes and user_notes
        $this->assertStringContainsString('release_notes:', $result['match']);
        $this->assertStringContainsString('user_notes:', $result['match']);
    }

    // ==================== Negative Tests: Empty/Null Inputs ====================

    public function testEmptyStringReturnsEmptyResult(): void
    {
        // Arrange
        $query = '';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertEquals('', $result['match']);
        $this->assertEmpty($result['chips']);
        $this->assertEmpty($result['filters']);
        $this->assertNull($result['year_from']);
        $this->assertNull($result['year_to']);
    }

    public function testWhitespaceOnlyReturnsEmptyResult(): void
    {
        // Arrange
        $query = '   ';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertEquals('', $result['match']);
        $this->assertEmpty($result['chips']);
    }

    public function testFilterWithEmptyValueIsIgnored(): void
    {
        // Arrange
        $query = 'artist:';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertArrayNotHasKey('artist', $result['filters']);
    }

    // ==================== Negative Tests: Malformed Input ====================

    public function testInvalidYearFormatTreatedAsText(): void
    {
        // Arrange
        $query = 'year:abc';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertNull($result['year_from']);
        $this->assertNull($result['year_to']);
    }

    public function testPartialYearRangeNotParsed(): void
    {
        // Arrange
        $query = 'year:1980..';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        // Invalid range format should not set year filters
        $this->assertNull($result['year_from']);
    }

    public function testUnknownFilterPassedThrough(): void
    {
        // Arrange
        $query = 'unknownfield:value';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        // Unknown fields are added to filters but not to FTS
        $this->assertEquals('value', $result['filters']['unknownfield']);
    }

    public function testSpecialCharactersStripped(): void
    {
        // Arrange
        $query = 'test@#$%query';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        // Special characters should be stripped for safety
        $this->assertStringNotContainsString('@', $result['match']);
        $this->assertStringNotContainsString('#', $result['match']);
    }

    // ==================== Negative Tests: Edge Cases ====================

    public function testUnmatchedQuoteHandledGracefully(): void
    {
        // Arrange
        $query = '"incomplete quote';

        // Act
        $result = $this->parser->parse($query);

        // Assert - should not throw, should produce some result
        $this->assertIsArray($result);
        $this->assertArrayHasKey('match', $result);
    }

    public function testVeryLongQueryDoesNotCrash(): void
    {
        // Arrange
        $query = str_repeat('a', 10000);

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('match', $result);
    }

    public function testManyFiltersHandled(): void
    {
        // Arrange
        $query = 'artist:a label:b genre:c style:d country:e format:f';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertCount(6, $result['chips']);
        $this->assertCount(6, $result['filters']);
    }

    public function testWhitespaceAfterColonNormalized(): void
    {
        // Arrange - space after colon should be normalized
        $query = 'artist: Beatles';

        // Act
        $result = $this->parser->parse($query);

        // Assert
        $this->assertEquals('Beatles', $result['filters']['artist']);
    }

    // ==================== Data Provider Tests ====================

    #[\PHPUnit\Framework\Attributes\DataProvider('fieldMappingProvider')]
    public function testFieldMappingsCorrect(string $field, string $expectedColumn): void
    {
        // Arrange
        $query = "{$field}:testvalue";

        // Act
        $result = $this->parser->parse($query);

        // Assert
        if ($expectedColumn !== 'type') {
            $this->assertStringContainsString($expectedColumn . ':', $result['match']);
        }
        $this->assertEquals('testvalue', $result['filters'][$field]);
    }

    public static function fieldMappingProvider(): array
    {
        return [
            'artist maps to artist' => ['artist', 'artist'],
            'title maps to title' => ['title', 'title'],
            'label maps to label_text' => ['label', 'label_text'],
            'format maps to format_text' => ['format', 'format_text'],
            'genre maps to genre_style_text' => ['genre', 'genre_style_text'],
            'style maps to genre_style_text' => ['style', 'genre_style_text'],
            'country maps to country' => ['country', 'country'],
            'barcode maps to identifier_text' => ['barcode', 'identifier_text'],
        ];
    }
}
