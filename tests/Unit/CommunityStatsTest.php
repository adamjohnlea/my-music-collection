<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\CommunityStats;
use PHPUnit\Framework\TestCase;

final class CommunityStatsTest extends TestCase
{
    public function testParsesFullCommunityBlock(): void
    {
        $raw = json_encode(['community' => [
            'have' => 3382, 'want' => 213,
            'rating' => ['count' => 187, 'average' => 3.9],
        ]]);
        $out = CommunityStats::fromReleaseRaw($raw);
        $this->assertSame(3382, $out['have']);
        $this->assertSame(213, $out['want']);
        $this->assertSame(3.9, $out['rating_average']);
        $this->assertSame(187, $out['rating_count']);
    }

    public function testReturnsNullWhenNoCommunityBlock(): void
    {
        $this->assertNull(CommunityStats::fromReleaseRaw(json_encode(['title' => 'x'])));
    }

    public function testReturnsNullForNullOrMalformedJson(): void
    {
        $this->assertNull(CommunityStats::fromReleaseRaw(null));
        $this->assertNull(CommunityStats::fromReleaseRaw('not json'));
    }

    public function testMissingRatingYieldsNullAverageZeroCount(): void
    {
        $raw = json_encode(['community' => ['have' => 10, 'want' => 2]]);
        $out = CommunityStats::fromReleaseRaw($raw);
        $this->assertSame(10, $out['have']);
        $this->assertNull($out['rating_average']);
        $this->assertSame(0, $out['rating_count']);
    }

    public function testZeroCountsArePreserved(): void
    {
        $raw = json_encode(['community' => ['have' => 0, 'want' => 0, 'rating' => ['count' => 0, 'average' => 0]]]);
        $out = CommunityStats::fromReleaseRaw($raw);
        $this->assertSame(0, $out['have']);
        $this->assertSame(0, $out['rating_count']);
    }
}
