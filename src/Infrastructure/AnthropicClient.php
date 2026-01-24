<?php
declare(strict_types=1);

namespace App\Infrastructure;

use GuzzleHttp\Client;

class AnthropicClient
{
    private Client $client;

    public function __construct(string $apiKey)
    {
        $this->client = new Client([
            'base_uri' => 'https://api.anthropic.com/v1/',
            'headers' => [
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'http_errors' => false,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getRecommendations(string $prompt): ?array
    {
        $response = $this->client->request('POST', 'messages', [
            'json' => [
                'model' => 'claude-3-haiku-20240307',
                'max_tokens' => 1024,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'system' => 'You are a music recommendation expert. Provide recommendations in JSON format. Each recommendation should include: "artist", "title", and "type" (artist or release). Return ONLY a JSON object with a "recommendations" key containing an array of items.',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            error_log('Anthropic API error: ' . $response->getBody()->getContents());
            return null;
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $content = $data['content'][0]['text'] ?? '';
        
        // Extract JSON if Claude wraps it in markdown
        if (preg_match('/\{(?:[^{}]|(?R))*\}/', $content, $matches)) {
            return json_decode($matches[0], true);
        }

        return json_decode($content, true);
    }
}
