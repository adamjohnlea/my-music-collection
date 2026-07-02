<?php
declare(strict_types=1);

namespace App\Http;

use GuzzleHttp\ClientInterface;

/**
 * Produces a Discogs-configured HTTP client for a given personal access token.
 *
 * The token is per-user (it comes from the logged-in user, not global config),
 * so this is injected as a collaborator rather than a pre-built client. That
 * also makes the controllers' live-Discogs code paths testable with a mock
 * transport instead of hitting the network.
 */
interface DiscogsClientFactory
{
    public function forToken(string $token): ClientInterface;
}
