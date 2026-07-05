<?php
declare(strict_types=1);

namespace App\Domain\Repositories;

interface CollectionRepositoryInterface
{
    /** @return array<int, array{id: int, name: string, query: string}> */
    public function getSavedSearches(int $userId): array;
    public function saveSearch(int $userId, string $name, string $query): void;
    public function deleteSearch(int $id, int $userId): void;
    /** @return array{notes: string|null, rating: int|null, instance_id: int}|null */
    public function findCollectionItem(int $releaseId, string $username): ?array;
    public function existsInCollection(int $releaseId, string $username): bool;
    public function existsInWantlist(int $releaseId, string $username): bool;
    /** @param array<string, mixed> $data */
    public function addToPushQueue(array $data): void;
    /** @param array<string, mixed> $data */
    public function updatePushQueue(int $id, array $data): void;
    /** @return array{id: int}|null */
    public function findPendingPushJob(int $instanceId, string $action): ?array;
    public function removeFromWantlist(int $releaseId, string $username): void;
    public function addToWantlist(int $releaseId, string $username, string $addedAt): void;
    /** @return array{total_count: int, top_artists: array<int, array{artist: string, count: int}>, top_genres: array<int, array{genre: string, count: int}>, decades: array<int, array{decade: int, count: int}>, formats: array<int, array{format_name: string, count: int}>} */
    public function getCollectionStats(string $username): array;
    /** @return array<string,int|float> */
    public function getAchievementMetrics(string $username): array;
    public function getRandomReleaseId(string $username): ?int;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
    /** @return array{notes: string|null, rating: int|null}|null */
    public function findWantlistItem(int $releaseId, string $username): ?array;

    /** @return int[] */
    public function getWantlistReleaseIds(string $username): array;

    public function updateWantlistMarketplace(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $fetchedAt): void;

    /**
     * @param int[] $releaseIds
     * @return array<int, array{num_for_sale:?int, lowest_price:?float, lowest_price_currency:?string, market_fetched_at:?string, target_price:?float}>
     */
    public function getWantlistMarketplaceStats(array $releaseIds, string $username): array;

    public function insertWantlistPriceHistory(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $capturedAt): void;
    /**
     * @param int[] $releaseIds
     * @return array<int, array<int, array{lowest_price: float, captured_at: string}>> keyed by release_id, each list ASC by captured_at
     */
    public function getWantlistPriceHistories(array $releaseIds, string $username): array;
    public function getStoredWantlistLowest(int $releaseId, string $username): ?float;
    public function getWantlistTarget(int $releaseId, string $username): ?float;
    public function setWantlistTarget(int $releaseId, string $username, ?float $target): void;
    public function latestActiveAlertPrice(int $releaseId, string $username): ?float;
    public function insertWantlistAlert(int $releaseId, string $username, string $reason, ?float $oldPrice, float $newPrice, ?string $currency, string $createdAt): void;
    /** @return array<int, array{id:int, release_id:int, reason:string, old_price:?float, new_price:float, currency:?string, created_at:string, read_at:?string, artist:?string, title:?string, cover_url:?string, thumb_url:?string}> newest first, undismissed only */
    public function listWantlistAlerts(string $username): array;
    public function countUnreadWantlistAlerts(string $username): int;
    public function markWantlistAlertsRead(string $username, string $readAt): void;
    public function dismissWantlistAlert(int $id, string $username, string $dismissedAt): void;
}
