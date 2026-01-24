<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Repositories\ReleaseRepositoryInterface;
use App\Http\Validation\Validator;
use App\Infrastructure\AnthropicClient;
use PDO;
use Twig\Environment;

class RecommendationController extends BaseController
{
    public function __construct(
        Environment $twig,
        private ReleaseRepositoryInterface $releaseRepository,
        private PDO $pdo,
        Validator $validator
    ) {
        parent::__construct($twig, $validator);
    }

    public function getRecommendations(int $rid, ?array $currentUser): void
    {
        if (!$currentUser || empty($currentUser['anthropic_api_key'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Anthropic API key not configured.']);
            return;
        }

        // Check cache first
        $cached = $this->releaseRepository->getCachedRecommendations($rid);
        if ($cached) {
            header('Content-Type: application/json');
            echo json_encode($cached);
            return;
        }

        $release = $this->releaseRepository->findById($rid);
        if (!$release) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Release not found.']);
            return;
        }

        // Gather context
        $username = $currentUser['discogs_username'];
        $collectionSummary = $this->getCollectionSummary($username);

        $prompt = "The user is looking for recommendations similar to the release: \"{$release['artist']} - {$release['title']}\".\n";
        $prompt .= "Release details: Year: {$release['year']}, Country: {$release['country']}.\n";
        if (!empty($release['genres'])) {
            $genres = implode(', ', json_decode($release['genres'], true) ?: []);
            $prompt .= "Genres: $genres.\n";
        }
        if (!empty($release['styles'])) {
            $styles = implode(', ', json_decode($release['styles'], true) ?: []);
            $prompt .= "Styles: $styles.\n";
        }

        $prompt .= "\nUser's Collection Context:\n$collectionSummary\n";
        $prompt .= "\nPlease recommend 5 similar artists or releases. For each, provide the artist name, title (if it's a release), type ('artist' or 'release'), and a Discogs URL if known.";

        $client = new AnthropicClient($currentUser['anthropic_api_key']);
        $recommendations = $client->getRecommendations($prompt);

        if ($recommendations) {
            $this->releaseRepository->saveRecommendations($rid, $recommendations);
            header('Content-Type: application/json');
            echo json_encode($recommendations);
        } else {
            error_log("Failed to get recommendations for release $rid");
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'Failed to get recommendations from AI.']);
        }
    }

    private function getCollectionSummary(string $username): string
    {
        // Get top artists and genres in collection for context
        $st = $this->pdo->prepare("
            SELECT artist, COUNT(*) as count 
            FROM releases r
            JOIN collection_items ci ON r.id = ci.release_id
            WHERE ci.username = :u
            GROUP BY artist
            ORDER BY count DESC
            LIMIT 5
        ");
        $st->execute([':u' => $username]);
        $topArtists = $st->fetchAll(PDO::FETCH_ASSOC);

        $artistList = array_map(fn($a) => "{$a['artist']} ({$a['count']} releases)", $topArtists);

        $st = $this->pdo->prepare("
            SELECT value as genre, COUNT(*) as count
            FROM (
                SELECT json_each.value
                FROM releases r
                JOIN collection_items ci ON r.id = ci.release_id,
                json_each(r.genres)
                WHERE ci.username = :u
            )
            GROUP BY genre
            ORDER BY count DESC
            LIMIT 5
        ");
        $st->execute([':u' => $username]);
        $topGenres = $st->fetchAll(PDO::FETCH_ASSOC);

        $genreList = array_map(fn($g) => "{$g['genre']} ({$g['count']} releases)", $topGenres);

        $summary = "Top artists in user's collection: " . implode(', ', $artistList) . ".\n";
        $summary .= "Top genres in user's collection: " . implode(', ', $genreList) . ".";

        return $summary;
    }
}
