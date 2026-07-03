<?php
declare(strict_types=1);

namespace App\Infrastructure;

use GuzzleHttp\ClientInterface;

final class DiscogsPricingClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /** @return array<string, array{value: float, currency: string}> */
    public function priceSuggestions(int $releaseId): array
    {
        $resp = $this->http->request('GET', 'marketplace/price_suggestions/' . $releaseId);
        if ($resp->getStatusCode() !== 200) {
            return [];
        }
        $data = json_decode((string)$resp->getBody(), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $grade => $listing) {
            if (is_array($listing) && isset($listing['value'], $listing['currency'])) {
                $out[(string)$grade] = [
                    'value' => (float)$listing['value'],
                    'currency' => (string)$listing['currency'],
                ];
            }
        }
        return $out;
    }

    /** @return array{value: float, currency: string}|null */
    public function lowestListed(int $releaseId): ?array
    {
        $resp = $this->http->request('GET', 'marketplace/stats/' . $releaseId);
        if ($resp->getStatusCode() !== 200) {
            return null;
        }
        $data = json_decode((string)$resp->getBody(), true);
        $listing = is_array($data) ? ($data['lowest_price'] ?? null) : null;
        if (!is_array($listing) || !isset($listing['value'], $listing['currency'])) {
            return null;
        }
        return ['value' => (float)$listing['value'], 'currency' => (string)$listing['currency']];
    }

    /**
     * @return array{num_for_sale:int, lowest_price: array{value:float, currency:string}|null}|null
     */
    public function marketplaceStats(int $releaseId): ?array
    {
        $resp = $this->http->request('GET', 'marketplace/stats/' . $releaseId);
        if ($resp->getStatusCode() !== 200) {
            return null;
        }
        $data = json_decode((string)$resp->getBody(), true);
        if (!is_array($data)) {
            return null;
        }
        $listing = $data['lowest_price'] ?? null;
        $lowest = (is_array($listing) && isset($listing['value'], $listing['currency']))
            ? ['value' => (float)$listing['value'], 'currency' => (string)$listing['currency']]
            : null;

        return [
            'num_for_sale' => (int)($data['num_for_sale'] ?? 0),
            'lowest_price' => $lowest,
        ];
    }
}
