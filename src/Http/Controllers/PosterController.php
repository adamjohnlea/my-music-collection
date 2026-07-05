<?php
declare(strict_types=1);

namespace App\Http\Controllers;

final class PosterController
{
    /**
     * Validate and resolve a poster download request.
     * @return array{status:int, path:?string, error:?string}
     */
    public function download(string $file, string $baseDir): array
    {
        // Only a plain basename is allowed — no directories, no traversal.
        if ($file === '' || basename($file) !== $file || str_contains($file, "\0")) {
            return ['status' => 400, 'path' => null, 'error' => 'Invalid filename'];
        }

        $dir = rtrim($baseDir, '/\\') . '/var/posters';
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            return ['status' => 404, 'path' => null, 'error' => 'Not found'];
        }

        return ['status' => 200, 'path' => $path, 'error' => null];
    }
}
