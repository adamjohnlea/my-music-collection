<?php
declare(strict_types=1);

namespace App\Sync;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Infrastructure\DiscogsPricingClient;

final class WantlistMarketplaceRefresher
{
    public function __construct(
        private readonly DiscogsPricingClient $pricing,
        private readonly CollectionRepositoryInterface $repo,
    ) {}

    /**
     * Refresh live marketplace availability for every wantlist item.
     *
     * @return array{updated:int, failed:int, total:int}
     */
    public function refresh(string $username): array
    {
        $ids = $this->repo->getWantlistReleaseIds($username);
        $updated = 0;
        $failed = 0;

        foreach ($ids as $releaseId) {
            try {
                $stats = $this->pricing->marketplaceStats($releaseId);
                if ($stats === null) {
                    $failed++;
                    error_log("marketplaceStats returned null for release $releaseId");
                    continue;
                }
                $this->repo->updateWantlistMarketplace(
                    $releaseId,
                    $username,
                    $stats['num_for_sale'],
                    $stats['lowest_price']['value'] ?? null,
                    $stats['lowest_price']['currency'] ?? null,
                    gmdate('c'),
                );
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                error_log("Wantlist marketplace refresh failed for release $releaseId: " . $e->getMessage());
            }
        }

        return ['updated' => $updated, 'failed' => $failed, 'total' => count($ids)];
    }
}
