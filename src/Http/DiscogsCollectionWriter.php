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
     * If $notesFieldId is provided, notes will be sent as the collection field array
     * format expected by Discogs: [{"field_id": id, "value": "..."}].
     *
     * @return array{ok:bool, code:int, body:string}
     */
    public function updateInstance(string $username, int $releaseId, int $instanceId, int $folderId, ?int $rating, ?string $notes, ?int $notesFieldId = null): array
    {
        $path = sprintf('users/%s/collection/folders/%d/releases/%d/instances/%d', rawurlencode($username), $folderId, $releaseId, $instanceId);
        $payload = [];
        if ($rating !== null) {
            // Discogs expects 0..5 (0 clears)
            $payload['rating'] = max(0, min(5, $rating));
        }
        if ($notes !== null) {
            if ($notesFieldId !== null) {
                $payload['notes'] = [
                    ['field_id' => $notesFieldId, 'value' => $notes]
                ];
            } else {
                // Fallback: some API variants may still accept a plain string
                $payload['notes'] = $notes;
            }
        }
        try {
            $resp = $this->http->request('POST', $path, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
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
