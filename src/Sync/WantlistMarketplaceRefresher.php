<?php
declare(strict_types=1);

namespace App\Sync;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Domain\Wantlist\WantlistAlertEvaluator;
use App\Infrastructure\DiscogsPricingClient;

final class WantlistMarketplaceRefresher
{
    public function __construct(
        private readonly DiscogsPricingClient $pricing,
        private readonly CollectionRepositoryInterface $repo,
        private readonly WantlistAlertEvaluator $evaluator,
    ) {}

    /**
     * Refresh live marketplace availability for every wantlist item, record price
     * history, and raise de-duped price-drop alerts.
     *
     * @return array{updated:int, failed:int, total:int, alerts:int}
     */
    public function refresh(string $username): array
    {
        $ids = $this->repo->getWantlistReleaseIds($username);
        $updated = 0;
        $failed = 0;
        $alerts = 0;

        foreach ($ids as $releaseId) {
            try {
                // Read pre-refresh state BEFORE the marketplace update overwrites it.
                $previousLowest = $this->repo->getStoredWantlistLowest($releaseId, $username);
                $target = $this->repo->getWantlistTarget($releaseId, $username);
                $lastAlertPrice = $this->repo->latestActiveAlertPrice($releaseId, $username);

                $stats = $this->pricing->marketplaceStats($releaseId);
                if ($stats === null) {
                    $failed++;
                    error_log("marketplaceStats returned null for release $releaseId");
                    continue;
                }

                $newLowest = $stats['lowest_price']['value'] ?? null;
                $currency = $stats['lowest_price']['currency'] ?? null;
                $now = gmdate('c');

                $this->repo->updateWantlistMarketplace(
                    $releaseId, $username, $stats['num_for_sale'], $newLowest, $currency, $now,
                );
                $this->repo->insertWantlistPriceHistory(
                    $releaseId, $username, $stats['num_for_sale'], $newLowest, $currency, $now,
                );

                $decision = $this->evaluator->evaluate($previousLowest, $newLowest, $target, $lastAlertPrice);
                if ($decision !== null) {
                    $this->repo->insertWantlistAlert(
                        $releaseId, $username, $decision['reason'],
                        $decision['old_price'], $decision['new_price'], $currency, $now,
                    );
                    $alerts++;
                }

                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                error_log("Wantlist marketplace refresh failed for release $releaseId: " . $e->getMessage());
            }
        }

        return ['updated' => $updated, 'failed' => $failed, 'total' => count($ids), 'alerts' => $alerts];
    }
}
