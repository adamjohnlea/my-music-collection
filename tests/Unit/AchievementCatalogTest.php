<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Achievements\AchievementCatalog;
use PHPUnit\Framework\TestCase;

final class AchievementCatalogTest extends TestCase
{
    public function testHasElevenBadges(): void
    {
        $this->assertCount(11, (new AchievementCatalog())->all());
    }

    public function testKeysAreUnique(): void
    {
        $keys = array_map(fn($d) => $d->key, (new AchievementCatalog())->all());
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function testTiersAreAscendingAndNonEmpty(): void
    {
        foreach ((new AchievementCatalog())->all() as $def) {
            $this->assertNotEmpty($def->tiers, "{$def->key} has no tiers");
            $sorted = $def->tiers;
            sort($sorted);
            $this->assertSame($sorted, $def->tiers, "{$def->key} tiers not ascending");
        }
    }

    public function testUnitIsCountOrMoney(): void
    {
        foreach ((new AchievementCatalog())->all() as $def) {
            $this->assertContains($def->unit, ['count', 'money'], "{$def->key} bad unit");
        }
    }

    public function testExpectedKeysPresent(): void
    {
        $keys = array_map(fn($d) => $d->key, (new AchievementCatalog())->all());
        foreach ([
            'collector','portfolio','blue_chip','time_traveler','omnivore',
            'globetrotter','format_fluent','superfan','label_loyalist','critic','annotator',
        ] as $k) {
            $this->assertContains($k, $keys);
        }
    }
}
