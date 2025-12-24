<?php
declare(strict_types=1);

namespace App\Domain\Repositories;

interface UserRepositoryInterface
{
    public function findById(int $id): ?array;
    public function findByUsernameOrEmail(string $usernameOrEmail): ?array;
    public function exists(string $username, string $email): bool;
    public function create(string $username, string $email, string $passwordHash): int;
    public function updateDiscogsCredentials(int $userId, string $username, string $tokenEnc, bool $excludeTitle): void;
}
