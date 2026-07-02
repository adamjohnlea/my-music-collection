<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Http\DiscogsClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * Test factory that returns a Guzzle client backed by a MockHandler, so the
 * controllers' live-Discogs paths can be exercised without real network calls.
 * Records outgoing requests so the request construction can be asserted too.
 */
final class FakeDiscogsClientFactory implements DiscogsClientFactory
{
    /** The token passed to the most recent forToken() call. */
    public ?string $lastToken = null;

    /** @var array<int, array<string, mixed>> Guzzle history transaction container */
    public array $transactions = [];

    /** @param array<int, mixed> $responses Guzzle responses/exceptions, returned in order */
    public function __construct(private array $responses = [])
    {
    }

    public function forToken(string $token): ClientInterface
    {
        $this->lastToken = $token;
        $stack = HandlerStack::create(new MockHandler($this->responses));
        $stack->push(Middleware::history($this->transactions));
        return new Client(['handler' => $stack, 'http_errors' => false]);
    }

    /**
     * Parsed query params of the most recent outgoing request.
     *
     * @return array<string, string>
     */
    public function lastQuery(): array
    {
        $last = end($this->transactions);
        if ($last === false) {
            return [];
        }
        parse_str($last['request']->getUri()->getQuery(), $query);
        /** @var array<string, string> $query */
        return $query;
    }
}
