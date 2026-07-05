<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

final class AchievementCatalog
{
    public const CAT_MILESTONES = 'Milestones';
    public const CAT_DIVERSITY  = 'Diversity';
    public const CAT_DEPTH       = 'Depth & Curation';

    /** @return list<AchievementDefinition> */
    public function all(): array
    {
        return [
            new AchievementDefinition('collector', 'Collector',
                'Grow your collection.', self::CAT_MILESTONES, '💿',
                'total_count', 'count', [10, 50, 100, 500, 1000]),
            new AchievementDefinition('portfolio', 'Portfolio',
                'Total collection value.', self::CAT_MILESTONES, '💰',
                'total_value', 'money', [100, 500, 1000, 5000]),
            new AchievementDefinition('blue_chip', 'Blue Chip',
                'Own a high-value single record.', self::CAT_MILESTONES, '💎',
                'max_single_value', 'money', [100, 250, 500]),

            new AchievementDefinition('time_traveler', 'Time Traveler',
                'Own records from many decades.', self::CAT_DIVERSITY, '🕰️',
                'distinct_decades', 'count', [3, 5, 7]),
            new AchievementDefinition('omnivore', 'Omnivore',
                'Span many genres.', self::CAT_DIVERSITY, '🎧',
                'distinct_genres', 'count', [3, 5, 10]),
            new AchievementDefinition('globetrotter', 'Globetrotter',
                'Own records pressed in many countries.', self::CAT_DIVERSITY, '🌍',
                'distinct_countries', 'count', [3, 5, 10]),
            new AchievementDefinition('format_fluent', 'Format Fluent',
                'Collect across formats.', self::CAT_DIVERSITY, '📼',
                'distinct_formats', 'count', [2, 3, 4]),

            new AchievementDefinition('superfan', 'Superfan',
                'Go deep on a single artist.', self::CAT_DEPTH, '⭐',
                'max_by_artist', 'count', [5, 10, 20]),
            new AchievementDefinition('label_loyalist', 'Label Loyalist',
                'Go deep on a single label.', self::CAT_DEPTH, '🏷️',
                'max_by_label', 'count', [5, 10, 20]),
            new AchievementDefinition('critic', 'Critic',
                'Rate your records.', self::CAT_DEPTH, '📝',
                'rated_count', 'count', [10, 50, 100]),
            new AchievementDefinition('annotator', 'Annotator',
                'Add notes to your records.', self::CAT_DEPTH, '🗒️',
                'noted_count', 'count', [5, 25, 50]),
        ];
    }
}
