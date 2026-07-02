<?php
declare(strict_types=1);

namespace App\Http;

use App\Infrastructure\KvStore;
use GuzzleHttp\ClientInterface;

/**
 * Production factory: builds a fully-configured Discogs HTTP client (with the
 * rate-limiter and retry middleware) for the given token.
 */
final class DefaultDiscogsClientFactory implements DiscogsClientFactory
{
    public function __construct(
        private readonly string $userAgent,
        private readonly KvStore $kv,
    ) {
    }

    public function forToken(string $token): ClientInterface
    {
        return (new DiscogsHttpClient($this->userAgent, $token, $this->kv))->client();
    }
}
