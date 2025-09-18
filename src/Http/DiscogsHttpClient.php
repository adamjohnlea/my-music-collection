<?php
declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\RateLimiterMiddleware;
use App\Http\Middleware\RetryMiddleware;
use App\Infrastructure\KvStore;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Psr\Http\Client\ClientInterface;

class DiscogsHttpClient
{
    private ClientInterface $client;

    public function __construct(string $userAgent, string $token, KvStore $kv)
    {
        $stack = HandlerStack::create();

        // Add RateLimiter and Retry middlewares
        $stack->push(new RateLimiterMiddleware($kv));
        $stack->push(new RetryMiddleware());

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

    public function client(): ClientInterface
    {
        return $this->client;
    }
}
