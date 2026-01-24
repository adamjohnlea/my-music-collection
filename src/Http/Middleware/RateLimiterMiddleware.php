<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\KvStore;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Header-aware rate limiter for Discogs core API.
 * - Tracks X-Discogs-Ratelimit* headers and persists in kv_store
 * - Sleeps conservatively when remaining == 0
 * - Honors Retry-After on 429
 */
class RateLimiterMiddleware
{
    public function __construct(private readonly KvStore $kv)
    {
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $this->maybeThrottleBeforeRequest();

            $promise = $handler($request, $options);

            return $promise->then(function (ResponseInterface $response) {
                $this->recordHeaders($response);
                if ($response->getStatusCode() === 429) {
                    $this->sleepForRetryAfter($response);
                }
                return $response;
            });
        };
    }

    private function maybeThrottleBeforeRequest(): void
    {
        $remaining = (int)($this->kv->get('rate:core:remaining', '1') ?? '1');
        $lastSeen = (int)($this->kv->get('rate:core:last_seen_at', '0') ?? '0');
        $bucket = (int)($this->kv->get('rate:core:bucket', '60') ?? '60');

        // If we haven't seen headers in >120s, don't block preemptively
        if ($lastSeen === 0 || (time() - $lastSeen) > 120) {
            return;
        }

        if ($remaining <= 0) {
            // Conservative window: 60s from last_seen
            $elapsed = time() - $lastSeen;
            $sleep = max(1, 60 - $elapsed);
            // @phpstan-ignore greater.alwaysTrue (defensive: max() guarantees >= 1, but explicit check is clearer)
            if ($sleep > 0) {
                usleep($sleep * 1_000_000);
                // After sleep, reset remaining optimistically to bucket-1
                $this->kv->set('rate:core:remaining', (string)max(0, $bucket - 1));
                $this->kv->set('rate:core:last_seen_at', (string)time());
            }
        }
    }

    private function recordHeaders(ResponseInterface $response): void
    {
        $bucket = $this->headerInt($response, 'X-Discogs-Ratelimit');
        $remaining = $this->headerInt($response, 'X-Discogs-Ratelimit-Remaining');
        // $used = $this->headerInt($response, 'X-Discogs-Ratelimit-Used');

        if ($bucket !== null) {
            $this->kv->set('rate:core:bucket', (string)$bucket);
        }
        if ($remaining !== null) {
            $this->kv->set('rate:core:remaining', (string)$remaining);
        }
        $this->kv->set('rate:core:last_seen_at', (string)time());
    }

    private function sleepForRetryAfter(ResponseInterface $response): void
    {
        $retryAfter = $response->getHeaderLine('Retry-After');
        $seconds = 0;
        if ($retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                $seconds = (int)$retryAfter;
            } else {
                $ts = strtotime($retryAfter);
                if ($ts !== false) {
                    $seconds = max(0, $ts - time());
                }
            }
        }
        if ($seconds <= 0) {
            $seconds = 5; // default small backoff
        }
        // add small jitter up to 500ms
        $ms = random_int(0, 500);
        usleep($seconds * 1_000_000 + $ms * 1000);
    }

    private function headerInt(ResponseInterface $response, string $name): ?int
    {
        $val = $response->getHeaderLine($name);
        if ($val === '') {
            return null;
        }
        if (!preg_match('/^\d+$/', $val)) {
            return null;
        }
        return (int)$val;
    }
}
