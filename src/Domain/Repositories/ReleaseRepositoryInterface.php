<?php
declare(strict_types=1);

namespace App\Domain\Repositories;

interface ReleaseRepositoryInterface
{
    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;
    /** @param array<string, mixed> $data */
    public function save(array $data): void;
    public function getPrimaryLocalPath(int $releaseId, ?string $coverUrl): ?string;
    public function getAnyLocalPath(int $releaseId): ?string;
    /** @return array<int, array<string, mixed>> */
    public function search(string $match, ?int $yearFrom, ?int $yearTo, ?int $masterId, string $username, string $itemsTable, string $orderBy, int $limit, int $offset): array;
    public function countSearch(string $match, ?int $yearFrom, ?int $yearTo, ?int $masterId, string $username, string $itemsTable): int;
    /** @return array<int, array<string, mixed>> */
    public function getAll(string $username, string $itemsTable, string $orderBy, int $limit, int $offset): array;
    public function countAll(string $username, string $itemsTable): int;
    /** @return array<int, array{source_url: string, local_path: string|null}> */
    public function getImages(int $releaseId): array;
    /** @return array<string, mixed>|null */
    public function getCachedRecommendations(int $releaseId): ?array;
    /** @param array<string, mixed> $recommendations */
    public function saveRecommendations(int $releaseId, array $recommendations): void;
    public function updateAppleMusicId(int $releaseId, string $appleMusicId): void;
}
