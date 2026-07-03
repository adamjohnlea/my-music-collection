<?php
declare(strict_types=1);

namespace App\Domain;

final class CommunityStats
{
    /**
     * Extract the community stats from a release's stored raw_json.
     *
     * @return array{have:int, want:int, rating_average:float|null, rating_count:int}|null
     */
    public static function fromReleaseRaw(?string $rawJson): ?array
    {
        if ($rawJson === null || $rawJson === '') {
            return null;
        }
        $data = json_decode($rawJson, true);
        if (!is_array($data) || !isset($data['community']) || !is_array($data['community'])) {
            return null;
        }
        $c = $data['community'];
        $rating = is_array($c['rating'] ?? null) ? $c['rating'] : [];

        return [
            'have' => (int)($c['have'] ?? 0),
            'want' => (int)($c['want'] ?? 0),
            'rating_average' => isset($rating['average']) ? (float)$rating['average'] : null,
            'rating_count' => (int)($rating['count'] ?? 0),
        ];
    }
}
