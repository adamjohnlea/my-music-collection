<?php
declare(strict_types=1);

namespace App\Images;

use App\Infrastructure\KvStore;
use GuzzleHttp\Client;

class ImageCache
{
    private Client $http;
    private KvStore $kv;
    private string $userAgent;

    public function __construct(KvStore $kv, string $userAgent = 'MyDiscogsApp/0.1 (+images)')
    {
        $this->kv = $kv;
        $this->userAgent = $userAgent;
        $this->http = new Client([
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept' => 'image/*, */*',
            ],
            'http_errors' => false,
            'timeout' => 30,
        ]);
    }

    /**
     * Downloads an image to the specified local path, honoring 1 rps and 1000/day limit.
     * Returns true on success, false if quota reached or HTTP failure.
     */
    public function fetch(string $sourceUrl, string $localPath): bool
    {
        // Enforce daily cap
        $today = gmdate('Ymd');
        $dailyKey = 'rate:images:daily_count:' . $today;
        $g = $this->kv->get($dailyKey, '0');
        $count = (int)($g !== null ? $g : '0');
        if ($count >= 1000) {
            return false; // quota reached
        }

        // Enforce 1 rps
        $lastKey = 'rate:images:last_fetch_epoch';
        $g2 = $this->kv->get($lastKey, '0');
        $last = (int)($g2 !== null ? $g2 : '0');
        $now = time();
        $elapsed = $now - $last;
        if ($last > 0 && $elapsed < 1) {
            usleep((1 - $elapsed) * 1000000);
        }

        $res = $this->http->get($sourceUrl);
        $code = $res->getStatusCode();
        $this->kv->set($lastKey, (string)time());

        if ($code !== 200) {
            return false;
        }

        $body = (string)$res->getBody();
        $bytes = strlen($body);

        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (file_put_contents($localPath, $body) === false) {
            return false;
        }

        // Update daily counter
        $this->kv->incr($dailyKey, 1);

        return true;
    }
}
