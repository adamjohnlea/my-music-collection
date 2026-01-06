<?php
declare(strict_types=1);

namespace App\Infrastructure;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class AppleMusicClient
{
    private Client $client;

    public function __construct(string $userAgent)
    {
        $this->client = new Client([
            'base_uri' => 'https://api.music.apple.com/v1/',
            'headers' => [
                'User-Agent' => $userAgent,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ]);
    }

    /**
     * Search for an album by UPC.
     * Note: This requires an Apple Music Developer Token for authorization.
     * If the user doesn't provide one, we might have to use a public search if available,
     * but usually Apple Music API requires a JWT.
     * 
     * However, the embed player doesn't strictly need an API key for the IFRAME itself.
     * If we want to find the ID via API, we DO need a token.
     */
    public function searchByUpc(string $upc, string $developerToken, string $storefront = 'us'): ?string
    {
        try {
            $response = $this->client->request('GET', "catalog/{$storefront}/albums", [
                'query' => ['filter[upc]' => $upc],
                'headers' => [
                    'Authorization' => 'Bearer ' . $developerToken,
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode((string)$response->getBody(), true);
                if (!empty($data['data'])) {
                    return $data['data'][0]['id'];
                }
            }
        } catch (GuzzleException $e) {
            error_log("Apple Music API error (UPC): " . $e->getMessage());
        }

        return null;
    }

    /**
     * Search for an album by artist and title.
     */
    public function searchByText(string $artist, string $title, string $developerToken, string $storefront = 'us'): ?string
    {
        try {
            $term = $artist . ' ' . $title;
            $response = $this->client->request('GET', "catalog/{$storefront}/search", [
                'query' => [
                    'term' => $term,
                    'types' => 'albums',
                    'limit' => 5
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . $developerToken,
                ]
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode((string)$response->getBody(), true);
                if (!empty($data['results']['albums']['data'])) {
                    // Simple validation: check if the first result roughly matches
                    $result = $data['results']['albums']['data'][0];
                    $resultTitle = $result['attributes']['name'] ?? '';
                    $resultArtist = $result['attributes']['artistName'] ?? '';

                    // Very loose check just to avoid totally wrong matches
                    if ($this->isMatch($artist, $title, $resultArtist, $resultTitle)) {
                        return $result['id'];
                    }
                }
            }
        } catch (GuzzleException $e) {
            error_log("Apple Music API error (Text): " . $e->getMessage());
        }

        return null;
    }

    private function isMatch(string $artist, string $title, string $resArtist, string $resTitle): bool
    {
        $normalize = function($s) {
            $s = strtolower($s);
            $s = preg_replace('/\s\(\d+\)$/', '', $s); // Strip Discogs suffixes
            $s = preg_replace('/[^a-z0-9]/', '', $s);
            return $s;
        };

        $nArtist = $normalize($artist);
        $nTitle = $normalize($title);
        $nrArtist = $normalize($resArtist);
        $nrTitle = $normalize($resTitle);

        // Check if artist and title are both present in the result
        return (str_contains($nrArtist, $nArtist) || str_contains($nArtist, $nrArtist)) 
            && (str_contains($nrTitle, $nTitle) || str_contains($nTitle, $nrTitle));
    }
}
