<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\UserRepositoryInterface;
use PDO;

class SqliteUserRepository implements UserRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT id, username, email, discogs_username, discogs_token_enc, discogs_search_exclude_title FROM auth_users WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findByUsernameOrEmail(string $usernameOrEmail): ?array
    {
        $st = $this->pdo->prepare('SELECT id, password_hash FROM auth_users WHERE username = :u OR email = :u');
        $st->execute([':u' => $usernameOrEmail]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function exists(string $username, string $email): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM auth_users WHERE username = :u OR email = :e');
        $st->execute([':u' => $username, ':e' => $email]);
        return (bool)$st->fetchColumn();
    }

    public function create(string $username, string $email, string $passwordHash): int
    {
        $st = $this->pdo->prepare('INSERT INTO auth_users (username, email, password_hash) VALUES (:u, :e, :p)');
        $st->execute([':u' => $username, ':e' => $email, ':p' => $passwordHash]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateDiscogsCredentials(int $userId, string $username, string $tokenEnc, bool $excludeTitle): void
    {
        $up = $this->pdo->prepare('UPDATE auth_users SET discogs_username = :u, discogs_token_enc = :t, discogs_search_exclude_title = :et, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $up->execute([':u' => $username, ':t' => $tokenEnc, ':et' => (int)$excludeTitle, ':id' => $userId]);
    }
}
