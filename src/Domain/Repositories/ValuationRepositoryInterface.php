<?php
declare(strict_types=1);

namespace App\Domain\Repositories;

interface ValuationRepositoryInterface
{
    /** @param array<string, mixed> $row */
    public function upsertItemValuation(array $row): void;

    /** @param array<string, mixed> $row */
    public function appendSnapshot(array $row): void;

    /** @return array<string, mixed>|null */
    public function getItemValuation(string $scope, int $releaseId, int $instanceId): ?array;

    /** @return array<string, mixed>|null */
    public function bestValuationForRelease(int $releaseId): ?array;

    /** @return array{total: float, item_count: int, valued_count: int, currency: ?string} */
    public function getScopeTotals(string $scope): array;

    /** @return array<int, array<string, mixed>> */
    public function getSnapshots(string $scope): array;

    /** @return array<int, array<string, mixed>> */
    public function getMostValuable(string $scope, int $limit, int $offset): array;

    /** @return array<int, int> */
    public function staleReleaseIds(string $scope, int $ttlDays, string $username): array;
}
