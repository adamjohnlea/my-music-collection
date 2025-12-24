<?php
declare(strict_types=1);

namespace App\Http;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientInterface;

class DiscogsCollectionWriter
{
    private ClientInterface $http;

    public function __construct(DiscogsHttpClient $client)
    {
        $this->http = $client->client();
    }

    /**
     * Update a collection instance (rating and/or notes) on Discogs.
     * Only non-null fields are sent. Returns structured result with ok/code/body for diagnostics.
     * Discogs requires the specific folder_id for the instance.
     *
     * If $notesFieldId is provided, notes will be sent to the specific fields endpoint
     * as recommended by Discogs: POST /users/{username}/collection/folders/{folder_id}/releases/{release_id}/instances/{instance_id}/fields/{field_id}
     *
     * @return array{ok:bool, code:int, body:string}
     */
    public function updateInstance(string $username, int $releaseId, int $instanceId, int $folderId, ?int $rating, ?string $notes, ?int $notesFieldId = null): array
    {
        $basePath = sprintf('users/%s/collection/folders/%d/releases/%d/instances/%d', rawurlencode($username), $folderId, $releaseId, $instanceId);
        $folder0Path = sprintf('users/%s/collection/folders/0/releases/%d/instances/%d', rawurlencode($username), $releaseId, $instanceId);

        // 1. Update Rating if provided (uses the main instance endpoint)
        if ($rating !== null) {
            $payload = ['rating' => max(0, min(5, $rating))];
            try {
                $this->http->request('POST', $basePath, [
                    'json' => $payload,
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 30,
                ]);
            } catch (GuzzleException $e) {
                return ['ok' => false, 'code' => 0, 'body' => 'Rating update failed: ' . $e->getMessage()];
            }
        }

        // 2. Update Notes if provided
        if ($notes !== null) {
            if ($notesFieldId !== null) {
                // Use the specific fields endpoint as recommended by Discogs support
                // We use folder 0 here as it is often more robust for field updates
                $notesPath = $folder0Path . '/fields/' . $notesFieldId;
                $payload = ['value' => $notes];
            } else {
                // Fallback: update via main instance endpoint
                $notesPath = $basePath;
                $payload = ['notes' => $notes];
            }

            try {
                $resp = $this->http->request('POST', $notesPath, [
                    'json' => $payload,
                    'headers' => ['Content-Type' => 'application/json'],
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
                    'body' => 'Notes update failed: ' . $e->getMessage(),
                ];
            }
        }

        return ['ok' => true, 'code' => 200, 'body' => 'No changes to push'];
    }
}
