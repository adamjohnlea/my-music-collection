<?php
declare(strict_types=1);

namespace App\Domain\Repositories;

interface CollectionRepositoryInterface
{
    public function getSavedSearches(int $userId): array;
    public function saveSearch(int $userId, string $name, string $query): void;
    public function deleteSearch(int $id, int $userId): void;
    public function findCollectionItem(int $releaseId, string $username): ?array;
    public function existsInCollection(int $releaseId, string $username): bool;
    public function existsInWantlist(int $releaseId, string $username): bool;
    public function addToPushQueue(array $data): void;
    public function updatePushQueue(int $id, array $data): void;
    public function findPendingPushJob(int $instanceId, string $action): ?array;
    public function removeFromWantlist(int $releaseId, string $username): void;
    public function addToWantlist(int $releaseId, string $username, string $addedAt): void;
    public function getCollectionStats(string $username): array;
    public function getRandomReleaseId(string $username): ?int;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
    public function findWantlistItem(int $releaseId, string $username): ?array;
}
