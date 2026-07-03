<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\DiscogsPricingClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

final class DiscogsPricingClientTest extends MockeryTestCase
{
    public function testPriceSuggestionsParsesGrades(): void
    {
        $body = json_encode([
            'Near Mint (NM or M-)' => ['currency' => 'GBP', 'value' => 21.0],
            'Very Good Plus (VG+)' => ['currency' => 'GBP', 'value' => 18.5],
        ]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/price_suggestions/123')
            ->once()
            ->andReturn(new Response(200, [], $body));

        $client = new DiscogsPricingClient($http);
        $out = $client->priceSuggestions(123);

        $this->assertSame(18.5, $out['Very Good Plus (VG+)']['value']);
        $this->assertSame('GBP', $out['Very Good Plus (VG+)']['currency']);
    }

    public function testPriceSuggestions404ReturnsEmpty(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/price_suggestions/123')
            ->once()
            ->andReturn(new Response(404, [], '{"message":"none"}'));

        $client = new DiscogsPricingClient($http);
        $this->assertSame([], $client->priceSuggestions(123));
    }

    public function testLowestListedParsesStats(): void
    {
        $body = json_encode([
            'lowest_price' => ['currency' => 'GBP', 'value' => 12.99],
            'num_for_sale' => 42,
        ]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/stats/123')
            ->once()
            ->andReturn(new Response(200, [], $body));

        $client = new DiscogsPricingClient($http);
        $out = $client->lowestListed(123);

        $this->assertSame(12.99, $out['value']);
        $this->assertSame('GBP', $out['currency']);
    }

    public function testLowestListedNullWhenNoneForSale(): void
    {
        $body = json_encode(['lowest_price' => null, 'num_for_sale' => 0]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/stats/123')
            ->once()
            ->andReturn(new Response(200, [], $body));

        $client = new DiscogsPricingClient($http);
        $this->assertNull($client->lowestListed(123));
    }
}
