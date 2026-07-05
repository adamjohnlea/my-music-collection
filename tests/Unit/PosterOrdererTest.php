<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Poster\PosterOrderer;
use PHPUnit\Framework\TestCase;

final class PosterOrdererTest extends TestCase
{
    /** @return array<int, array<string,mixed>> */
    private function rows(): array
    {
        return [
            ['id' => 1, 'artist' => 'Beta',  'title' => 'Zed', 'year' => 1990, 'rating' => 3, 'added_at' => '2020-01-01', 'valuation' => 10.0, 'cover_color' => '#ff0000'],
            ['id' => 2, 'artist' => 'alpha', 'title' => 'Amp', 'year' => 1970, 'rating' => 5, 'added_at' => '2022-01-01', 'valuation' => 50.0, 'cover_color' => '#00ff00'],
            ['id' => 3, 'artist' => 'Gamma', 'title' => 'Mid', 'year' => 1980, 'rating' => 1, 'added_at' => '2021-01-01', 'valuation' => 30.0, 'cover_color' => '#0000ff'],
        ];
    }

    private function ids(array $rows): array
    {
        return array_map(fn($r) => $r['id'], $rows);
    }

    public function testArtistIsCaseInsensitiveAscending(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 1, 3], $this->ids($o->order($this->rows(), 'artist')));
    }

    public function testYearAscending(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 3, 1], $this->ids($o->order($this->rows(), 'year')));
    }

    public function testValuationHighFirst(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 3, 1], $this->ids($o->order($this->rows(), 'valuation')));
    }

    public function testRatingHighFirst(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 1, 3], $this->ids($o->order($this->rows(), 'rating')));
    }

    public function testAddedNewestFirst(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 3, 1], $this->ids($o->order($this->rows(), 'added')));
    }

    public function testColorOrdersByHueRedGreenBlue(): void
    {
        $o = new PosterOrderer();
        // Hue: red(0) < green(120) < blue(240)
        $this->assertSame([1, 2, 3], $this->ids($o->order($this->rows(), 'color')));
    }

    public function testShuffleIsDeterministicForSameSeed(): void
    {
        $o = new PosterOrderer();
        $a = $this->ids($o->order($this->rows(), 'shuffle', 42));
        $b = $this->ids($o->order($this->rows(), 'shuffle', 42));
        $this->assertSame($a, $b);
        $this->assertEqualsCanonicalizing([1, 2, 3], $a);
    }

    public function testUnknownKeyPreservesAscendingIdOrder(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([1, 2, 3], $this->ids($o->order($this->rows(), 'bogus')));
    }

    public function testColorSortsMissingOrInvalidLast(): void
    {
        $o = new PosterOrderer();
        $rows = $this->rows();
        $rows[0]['cover_color'] = null;
        $rows[1]['cover_color'] = 'notahex';
        // Only row 3 (blue, #0000ff) has a valid color; the other two must sort last.
        $this->assertSame([3, 1, 2], $this->ids($o->order($rows, 'color')));
    }

    public function testTiesBreakByAscendingId(): void
    {
        $o = new PosterOrderer();
        $rows = $this->rows();
        $rows[1]['rating'] = 3; // same rating as row 1 (id 1)
        $rows[2]['rating'] = 3; // same rating as row 1 (id 1)
        $this->assertSame([1, 2, 3], $this->ids($o->order($rows, 'rating')));
    }
}
