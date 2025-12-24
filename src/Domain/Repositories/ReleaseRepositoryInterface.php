<?php
declare(strict_types=1);

namespace App\Domain\Repositories;

interface ReleaseRepositoryInterface
{
    public function findById(int $id): ?array;
    public function save(array $data): void;
    public function getPrimaryLocalPath(int $releaseId, ?string $coverUrl): ?string;
    public function getAnyLocalPath(int $releaseId): ?string;
    public function search(string $match, ?int $yearFrom, ?int $yearTo, string $username, string $itemsTable, string $orderBy, int $limit, int $offset): array;
    public function countSearch(string $match, ?int $yearFrom, ?int $yearTo, string $username, string $itemsTable): int;
    public function getAll(string $username, string $itemsTable, string $orderBy, int $limit, int $offset): array;
    public function countAll(string $username, string $itemsTable): int;
    public function getImages(int $releaseId): array;
}
