<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class RetryMiddleware
{
    public function __construct(private readonly int $maxRetries = 5)
    {
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $retries = 0;

            $fn = function ($request, $options) use (&$retries, $handler, &$fn): PromiseInterface {
                $promise = $handler($request, $options);

                return $promise->then(function (ResponseInterface $response) use (&$retries, $request, $options, $handler, $fn) {
                    $code = $response->getStatusCode();
                    if ($this->shouldRetry($code) && $retries < $this->maxRetries) {
                        $delay = $this->computeBackoff($response, $retries);
                        $retries++;
                        usleep($delay);
                        return $fn($request, $options);
                    }
                    return $response;
                });
            };

            return $fn($request, $options);
        };
    }

    private function shouldRetry(int $status): bool
    {
        if ($status === 429) return true;
        if ($status >= 500 && $status < 600) return true;
        return false;
    }

    private function computeBackoff(ResponseInterface $response, int $attempt): int
    {
        $retryAfter = $response->getHeaderLine('Retry-After');
        if ($retryAfter !== '') {
            if (ctype_digit($retryAfter)) {
                $seconds = (int)$retryAfter;
            } else {
                $ts = strtotime($retryAfter);
                $seconds = $ts !== false ? max(0, $ts - time()) : 0;
            }
        } else {
            // exponential base 2 with full jitter, start at 1s
            $base = min(60, 2 ** max(0, $attempt));
            $seconds = random_int(1, max(1, (int)$base));
        }
        // convert to microseconds and add 0-250ms jitter
        $jitterMs = random_int(0, 250);
        return ($seconds * 1_000_000) + ($jitterMs * 1000);
    }
}
