<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Repositories\ReleaseRepositoryInterface;
use App\Http\Validation\Validator;
use App\Infrastructure\AppleMusicClient;
use App\Infrastructure\Config;
use Twig\Environment;

class AppleMusicController extends BaseController
{
    public function __construct(
        Environment $twig,
        private ReleaseRepositoryInterface $releaseRepository,
        private Config $config,
        private AppleMusicClient $appleMusicClient,
        Validator $validator
    ) {
        parent::__construct($twig, $validator);
    }

    public function getAppleMusicId(int $rid): void
    {
        header('Content-Type: application/json');

        $release = $this->releaseRepository->findById($rid);
        if (!$release) {
            http_response_code(404);
            echo json_encode(['error' => 'Release not found']);
            return;
        }

        // Return cached ID if exists
        if (!empty($release['apple_music_id'])) {
            echo json_encode(['apple_music_id' => $release['apple_music_id']]);
            return;
        }

        $developerToken = $this->config->getAppleMusicDeveloperToken();
        if (!$developerToken) {
            echo json_encode(['apple_music_id' => null, 'message' => 'Apple Music Developer Token not configured']);
            return;
        }

        $storefront = $this->config->getAppleMusicStorefront();

        // Extract barcodes from identifiers
        $barcodes = [];
        if (!empty($release['identifiers'])) {
            $identifiers = json_decode($release['identifiers'], true) ?: [];
            foreach ($identifiers as $idf) {
                if (strcasecmp($idf['type'] ?? '', 'Barcode') === 0) {
                    $value = preg_replace('/[^0-9]/', '', (string)($idf['value'] ?? ''));
                    if ($value) {
                        $barcodes[] = $value;
                    }
                }
            }
        }

        foreach ($barcodes as $barcode) {
            $appleId = $this->appleMusicClient->searchByUpc($barcode, $developerToken, $storefront);
            if ($appleId) {
                $this->releaseRepository->updateAppleMusicId($rid, $appleId);
                echo json_encode(['apple_music_id' => $appleId]);
                return;
            }
        }

        // Fallback to text search
        $appleId = $this->appleMusicClient->searchByText($release['artist'] ?? '', $release['title'] ?? '', $developerToken, $storefront);
        if ($appleId) {
            $this->releaseRepository->updateAppleMusicId($rid, $appleId);
            echo json_encode(['apple_music_id' => $appleId]);
            return;
        }

        echo json_encode(['apple_music_id' => null]);
    }
}
