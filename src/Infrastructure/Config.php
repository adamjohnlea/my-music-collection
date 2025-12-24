<?php
declare(strict_types=1);

namespace App\Infrastructure;

final class Config
{
    public function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return is_string($value) ? $value : (string)$value;
    }

    public function isAbsolutePath(string $path): bool
    {
        if ($path === '') return false;
        if ($path[0] === DIRECTORY_SEPARATOR) return true; // POSIX
        return (bool)preg_match('#^[A-Za-z]:[\\/]#', $path); // Windows drive
    }

    public function getDbPath(string $baseDir): string
    {
        $dbPath = $this->env('DB_PATH', 'var/app.db') ?? 'var/app.db';
        if (!$this->isAbsolutePath($dbPath)) {
            $dbPath = rtrim($baseDir, '/\\') . '/' . ltrim($dbPath, '/\\');
        }
        // Safety: the app refuses any DB path under public/
        $publicPrefix = rtrim($baseDir, '/\\') . '/public/';
        if (str_starts_with($dbPath, $publicPrefix)) {
            // move to var even if misconfigured
            $dbPath = rtrim($baseDir, '/\\') . '/var/app.db';
        }
        return $dbPath;
    }

    public function getImgDir(string $baseDir): string
    {
        $imgDir = $this->env('IMG_DIR', 'public/images') ?? 'public/images';
        if (!$this->isAbsolutePath($imgDir)) {
            $imgDir = rtrim($baseDir, '/\\') . '/' . ltrim($imgDir, '/\\');
        }
        return $imgDir;
    }

    public function getUserAgent(string $default = 'MyDiscogsApp/0.1 (+contact: you@example.com)'): string
    {
        return $this->env('USER_AGENT', $default) ?? $default;
    }

    public function getAppKey(): ?string
    {
        return $this->env('APP_KEY');
    }

    public function getDiscogsUsername(): ?string
    {
        return $this->env('DISCOGS_USERNAME');
    }

    public function getDiscogsToken(): ?string
    {
        return $this->env('DISCOGS_TOKEN');
    }
}
