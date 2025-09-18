<?php
declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

class KvStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT v FROM kv_store WHERE k = :k');
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) {
            return $default;
        }
        return (string)$val;
    }

    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('REPLACE INTO kv_store (k, v) VALUES (:k, :v)');
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    public function incr(string $key, int $by = 1): int
    {
        $current = (int)($this->get($key, '0') ?? '0');
        $current += $by;
        $this->set($key, (string)$current);
        return $current;
    }
}
