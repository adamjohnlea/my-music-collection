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

    public function testMarketplaceStatsParsesForSaleAndPrice(): void
    {
        $body = json_encode(['num_for_sale' => 3, 'lowest_price' => ['currency' => 'GBP', 'value' => 12.0]]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/123')->once()
            ->andReturn(new Response(200, [], $body));

        $out = (new DiscogsPricingClient($http))->marketplaceStats(123);
        $this->assertSame(3, $out['num_for_sale']);
        $this->assertSame(12.0, $out['lowest_price']['value']);
        $this->assertSame('GBP', $out['lowest_price']['currency']);
    }

    public function testMarketplaceStatsZeroForSaleNullPrice(): void
    {
        $body = json_encode(['num_for_sale' => 0, 'lowest_price' => null]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/123')->once()
            ->andReturn(new Response(200, [], $body));

        $out = (new DiscogsPricingClient($http))->marketplaceStats(123);
        $this->assertSame(0, $out['num_for_sale']);
        $this->assertNull($out['lowest_price']);
    }

    public function testMarketplaceStatsNullOnNon200(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/123')->once()
            ->andReturn(new Response(404, [], '{"message":"none"}'));

        $this->assertNull((new DiscogsPricingClient($http))->marketplaceStats(123));
    }
}
