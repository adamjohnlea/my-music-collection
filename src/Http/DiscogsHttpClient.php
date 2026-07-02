<?php
declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\RateLimiterMiddleware;
use App\Http\Middleware\RetryMiddleware;
use App\Http\Middleware\HealthCheckMiddleware;
use App\Infrastructure\KvStore;
use App\Infrastructure\RealSleeper;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

class DiscogsHttpClient
{
    private Client $client;

    public function __construct(string $userAgent, string $token, KvStore $kv)
    {
        $stack = HandlerStack::create();

        // Add RateLimiter and Retry middlewares
        $sleeper = new RealSleeper();
        $stack->push(new RateLimiterMiddleware($kv, $sleeper));
        $stack->push(new RetryMiddleware($sleeper));

        $this->client = new Client([
            'base_uri' => 'https://api.discogs.com/',
            'headers' => [
                'User-Agent' => $userAgent,
                'Authorization' => 'Discogs token=' . $token,
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip, deflate',
            ],
            'http_errors' => false,
            'handler' => $stack,
        ]);
    }

    public function client(): Client
    {
        return $this->client;
    }
}
