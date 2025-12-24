<?php
declare(strict_types=1);

namespace App\Http;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientInterface;

class DiscogsWantlistWriter
{
    private ClientInterface $http;

    public function __construct(DiscogsHttpClient $client)
    {
        $this->http = $client->client();
    }

    /**
     * Add a release to the user's wantlist.
     * PUT /users/{username}/wants/{release_id}
     */
    public function addToWantlist(string $username, int $releaseId): array
    {
        $path = sprintf('users/%s/wants/%d', rawurlencode($username), $releaseId);
        try {
            $resp = $this->http->request('PUT', $path, [
                'timeout' => 30,
            ]);
            $code = $resp->getStatusCode();
            $body = (string) $resp->getBody();
            return [
                'ok' => $code >= 200 && $code < 300,
                'code' => $code,
                'body' => $body,
            ];
        } catch (GuzzleException $e) {
            return [
                'ok' => false,
                'code' => 0,
                'body' => $e->getMessage(),
            ];
        }
    }

    /**
     * Remove a release from the user's wantlist.
     * DELETE /users/{username}/wants/{release_id}
     */
    public function removeFromWantlist(string $username, int $releaseId): array
    {
        $path = sprintf('users/%s/wants/%d', rawurlencode($username), $releaseId);
        try {
            $resp = $this->http->request('DELETE', $path, [
                'timeout' => 30,
            ]);
            $code = $resp->getStatusCode();
            $body = (string) $resp->getBody();
            return [
                'ok' => $code >= 200 && $code < 300,
                'code' => $code,
                'body' => $body,
            ];
        } catch (GuzzleException $e) {
            return [
                'ok' => false,
                'code' => 0,
                'body' => $e->getMessage(),
            ];
        }
    }

    /**
     * Add a release to the user's collection.
     * POST /users/{username}/collection/folders/{folder_id}/releases/{release_id}
     */
    public function addToCollection(string $username, int $releaseId, int $folderId = 1): array
    {
        $path = sprintf('users/%s/collection/folders/%d/releases/%d', rawurlencode($username), $folderId, $releaseId);
        try {
            $resp = $this->http->request('POST', $path, [
                'timeout' => 30,
            ]);
            $code = $resp->getStatusCode();
            $body = (string) $resp->getBody();
            return [
                'ok' => $code >= 200 && $code < 300,
                'code' => $code,
                'body' => $body,
            ];
        } catch (GuzzleException $e) {
            return [
                'ok' => false,
                'code' => 0,
                'body' => $e->getMessage(),
            ];
        }
    }
}
