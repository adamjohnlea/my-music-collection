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
    public function getRandomReleaseId(string $username): ?int;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
    /** @return array{notes: string|null, rating: int|null}|null */
    public function findWantlistItem(int $releaseId, string $username): ?array;
}
