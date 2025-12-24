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
     * Update a collection instance (rating and/or multiple notes/fields) on Discogs.
     * Only non-null fields are sent. Returns structured result with ok/code/body for diagnostics.
     * Discogs requires the specific folder_id for the instance.
     *
     * $fields is an associative array of field_id => value (string).
     *
     * @param array<int, string|null> $fields
     * @return array{ok:bool, code:int, body:string}
     */
    public function updateInstance(string $username, int $releaseId, int $instanceId, int $folderId, ?int $rating, array $fields = []): array
    {
        $basePath = sprintf('users/%s/collection/folders/%d/releases/%d/instances/%d', rawurlencode($username), $folderId, $releaseId, $instanceId);
        $folder0Path = sprintf('users/%s/collection/folders/0/releases/%d/instances/%d', rawurlencode($username), $releaseId, $instanceId);

        $lastResult = ['ok' => true, 'code' => 200, 'body' => 'No changes to push'];

        // 1. Update Rating if provided (uses the main instance endpoint)
        if ($rating !== null) {
            $payload = ['rating' => max(0, min(5, $rating))];
            try {
                $resp = $this->http->request('POST', $basePath, [
                    'json' => $payload,
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 30,
                ]);
                $lastResult = [
                    'ok' => $resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300,
                    'code' => $resp->getStatusCode(),
                    'body' => (string)$resp->getBody(),
                ];
                if (!$lastResult['ok']) return $lastResult;
            } catch (GuzzleException $e) {
                return ['ok' => false, 'code' => 0, 'body' => 'Rating update failed: ' . $e->getMessage()];
            }
        }

        // 2. Update Fields if provided
        foreach ($fields as $fieldId => $value) {
            if ($value === null) continue;

            $notesPath = $folder0Path . '/fields/' . $fieldId;
            $payload = ['value' => $value];

            try {
                $resp = $this->http->request('POST', $notesPath, [
                    'json' => $payload,
                    'headers' => ['Content-Type' => 'application/json'],
                    'timeout' => 30,
                ]);
                $code = $resp->getStatusCode();
                $body = (string)$resp->getBody();
                $lastResult = [
                    'ok' => $code >= 200 && $code < 300,
                    'code' => $code,
                    'body' => $body,
                ];
                if (!$lastResult['ok']) {
                    $lastResult['body'] = "Field ID $fieldId update failed: " . $lastResult['body'];
                    return $lastResult;
                }
            } catch (GuzzleException $e) {
                return [
                    'ok' => false,
                    'code' => 0,
                    'body' => "Field ID $fieldId update failed: " . $e->getMessage(),
                ];
            }
        }

        return $lastResult;
    }
}
