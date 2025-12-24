<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use PDO;
use Twig\Environment;

class ReleaseController extends BaseController
{
    public function __construct(
        Environment $twig,
        private PDO $pdo
    ) {
        parent::__construct($twig);
    }

    public function show(int $rid, ?array $currentUser): void
    {
        $stmt = $this->pdo->prepare("SELECT r.* FROM releases r WHERE r.id = :id");
        $stmt->execute([':id' => $rid]);
        $release = $stmt->fetch() ?: null;

        $imageUrl = null;
        $images = [];
        $details = [
            'labels' => [],
            'formats' => [],
            'genres' => [],
            'styles' => [],
            'tracklist' => [],
            'videos' => [],
            'extraartists' => [],
            'companies' => [],
            'identifiers' => [],
            'notes' => null,
            'user_notes' => null,
            'user_rating' => null,
            'barcodes' => [],
            'other_identifiers' => [],
            'in_collection' => false,
            'in_wantlist' => false,
        ];

        if ($release) {
            $imgStmt = $this->pdo->prepare('SELECT source_url, local_path FROM images WHERE release_id = :rid ORDER BY id ASC');
            $imgStmt->execute([':rid' => $rid]);
            $rows = $imgStmt->fetchAll();
            $baseDirFs = dirname(dirname(dirname(__DIR__)));
            $primaryUrl = $release['cover_url'] ?: ($release['thumb_url'] ?? null);
            foreach ($rows as $row) {
                $local = $row['local_path'] ?? null;
                $url = null;
                if ($local) {
                    $abs = $baseDirFs . '/' . ltrim($local, '/');
                    if (is_file($abs)) {
                        $url = '/' . ltrim(preg_replace('#^public/#','', $local), '/');
                    }
                }
                if (!$url) {
                    $url = $row['source_url'];
                }
                $images[] = [
                    'url' => $url,
                    'source_url' => $row['source_url'],
                    'is_primary' => ($primaryUrl && $row['source_url'] === $primaryUrl),
                ];
            }
            foreach ($images as $img) {
                if ($img['is_primary']) { $imageUrl = $img['url']; break; }
            }
            if (!$imageUrl && !empty($images)) { $imageUrl = $images[0]['url']; }
            if (!$imageUrl) { $imageUrl = $release['cover_url'] ?: ($release['thumb_url'] ?? null); }

            foreach (['labels','formats','genres','styles','tracklist','videos','extraartists','companies','identifiers'] as $k) {
                if (!empty($release[$k])) {
                    $decoded = json_decode((string)$release[$k], true);
                    if (is_array($decoded)) $details[$k] = $decoded;
                }
            }
            if (!empty($release['notes'])) $details['notes'] = (string)$release['notes'];
            if (!empty($details['identifiers'])) {
                foreach ($details['identifiers'] as $idf) {
                    $type = isset($idf['type']) ? (string)$idf['type'] : '';
                    if (strcasecmp($type, 'Barcode') === 0) $details['barcodes'][] = $idf; else $details['other_identifiers'][] = $idf;
                }
            }

            $username = $currentUser['discogs_username'] ?? null;
            if ($username) {
                $ci = $this->pdo->prepare('SELECT notes, rating FROM collection_items WHERE release_id = :rid AND username = :u ORDER BY added DESC LIMIT 1');
                $ci->execute([':rid' => $rid, ':u' => $username]);
                $ciRow = $ci->fetch();
                if ($ciRow) {
                    $details['in_collection'] = true;
                    $userNotes = $ciRow['notes'] ?? null;
                    if ($userNotes && is_string($userNotes) && str_starts_with($userNotes, '[')) {
                        $maybe = json_decode($userNotes, true);
                        if (is_array($maybe)) {
                            foreach ($maybe as $n) {
                                $fid = (int)($n['field_id'] ?? 0);
                                $val = (string)($n['value'] ?? '');
                                if ($fid === 1) $details['user_media_condition'] = $val;
                                elseif ($fid === 2) $details['user_sleeve_condition'] = $val;
                                elseif ($fid === 3) $details['user_notes'] = $val;
                            }
                        }
                    } else {
                        $details['user_notes'] = $userNotes ?: null;
                    }
                    $details['user_rating'] = isset($ciRow['rating']) ? (int)$ciRow['rating'] : null;
                }

                $wi = $this->pdo->prepare('SELECT notes, rating FROM wantlist_items WHERE release_id = :rid AND username = :u LIMIT 1');
                $wi->execute([':rid' => $rid, ':u' => $username]);
                $wiRow = $wi->fetch();
                if ($wiRow) {
                    $details['in_wantlist'] = true;
                    if (!$details['in_collection']) {
                        $details['user_notes'] = $wiRow['notes'] ?: null;
                        $details['user_rating'] = isset($wiRow['rating']) ? (int)$wiRow['rating'] : null;
                    }
                }
                if (isset($_GET['sr']) && $_GET['sr'] !== '') $details['user_rating'] = (int)$_GET['sr'];
                if (array_key_exists('sn', $_GET)) $details['user_notes'] = (string)$_GET['sn'];
                if (array_key_exists('smc', $_GET)) $details['user_media_condition'] = (string)$_GET['smc'];
                if (array_key_exists('ssc', $_GET)) $details['user_sleeve_condition'] = (string)$_GET['ssc'];
            }
        }

        $backUrl = '/';
        if (isset($_GET['return']) && is_string($_GET['return']) && str_starts_with($_GET['return'], '/')) {
            $backUrl = $_GET['return'];
        } elseif ($ref = $_SERVER['HTTP_REFERER'] ?? null) {
            $refPath = parse_url($ref, PHP_URL_PATH) ?: '/';
            $refQuery = parse_url($ref, PHP_URL_QUERY);
            if ($refPath && $refPath[0] === '/') $backUrl = $refPath . ($refQuery ? ('?' . $refQuery) : '');
        }

        $this->render('release.html.twig', [
            'title' => $release ? ($release['title'] . ' — ' . ($release['artist'] ?? '')) : 'Not found',
            'release' => $release,
            'image_url' => $imageUrl,
            'images' => $images,
            'details' => $details,
            'saved' => $_GET['saved'] ?? null,
            'back_url' => $backUrl,
        ]);
    }

    public function save(?array $currentUser): void
    {
        if (!$currentUser || empty($currentUser['discogs_username'])) {
            $rid = (int)($_POST['release_id'] ?? 0);
            $this->redirect('/login?return=' . rawurlencode('/release/'.$rid));
        }

        $rid = (int)($_POST['release_id'] ?? 0);
        if (!$this->isCsrfValid()) { $this->redirect('/release/' . $rid . '?saved=invalid_csrf'); }

        $rating = isset($_POST['rating']) && $_POST['rating'] !== '' ? max(0, min(5, (int)$_POST['rating'])) : null;
        $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : null;
        $mediaCondition = isset($_POST['media_condition']) ? trim((string)$_POST['media_condition']) : null;
        $sleeveCondition = isset($_POST['sleeve_condition']) ? trim((string)$_POST['sleeve_condition']) : null;
        $action = (string)($_POST['action'] ?? 'update_collection');
        $username = (string)$currentUser['discogs_username'];

        $ok = false; $msg = 'queued';
        if ($rid > 0 && $username) {
            if ($action === 'update_collection') {
                $ci = $this->pdo->prepare('SELECT instance_id FROM collection_items WHERE release_id = :rid AND username = :u ORDER BY added DESC LIMIT 1');
                $ci->execute([':rid' => $rid, ':u' => $username]);
                $iid = (int)($ci->fetchColumn() ?: 0);
                if ($iid > 0) {
                    $this->pdo->beginTransaction();
                    try {
                        $sel = $this->pdo->prepare('SELECT id FROM push_queue WHERE status = "pending" AND instance_id = :iid AND action = "update_collection" LIMIT 1');
                        $sel->execute([':iid' => $iid]);
                        $jobId = $sel->fetchColumn();
                        if ($jobId) {
                            $upd = $this->pdo->prepare('UPDATE push_queue SET rating = :rating, notes = :notes, media_condition = :mc, sleeve_condition = :sc, attempts = 0, last_error = NULL, created_at = strftime("%Y-%m-%dT%H:%M:%fZ", "now") WHERE id = :id');
                            $upd->execute([':rating' => $rating, ':notes' => $notes, ':mc' => $mediaCondition, ':sc' => $sleeveCondition, ':id' => $jobId]);
                        } else {
                            $ins = $this->pdo->prepare('INSERT INTO push_queue (instance_id, release_id, username, rating, notes, media_condition, sleeve_condition, action) VALUES (:iid, :rid, :u, :rating, :notes, :mc, :sc, :action)');
                            $ins->execute([':iid' => $iid, ':rid' => $rid, ':u' => $username, ':rating' => $rating, ':notes' => $notes, ':mc' => $mediaCondition, ':sc' => $sleeveCondition, ':action' => $action]);
                        }
                        $this->pdo->commit();
                        $ok = true;
                    } catch (\Throwable $e) { $this->pdo->rollBack(); $ok = false; $msg = 'error'; }
                } else { $msg = 'no_instance'; }
            } elseif (in_array($action, ['add_want', 'remove_want', 'want_to_collection'])) {
                $this->pdo->beginTransaction();
                try {
                    $ins = $this->pdo->prepare('INSERT INTO push_queue (instance_id, release_id, username, action) VALUES (:iid, :rid, :u, :action)');
                    $ins->execute([':iid' => 0, ':rid' => $rid, ':u' => $username, ':action' => $action]);
                    if ($action === 'remove_want') {
                        $del = $this->pdo->prepare('DELETE FROM wantlist_items WHERE release_id = :rid AND username = :u');
                        $del->execute([':rid' => $rid, ':u' => $username]);
                    } elseif ($action === 'want_to_collection') {
                        $del = $this->pdo->prepare('DELETE FROM wantlist_items WHERE release_id = :rid AND username = :u');
                        $del->execute([':rid' => $rid, ':u' => $username]);
                    }
                    $this->pdo->commit();
                    $ok = true;
                } catch (\Throwable $e) { $this->pdo->rollBack(); $ok = false; $msg = 'error'; }
            }
        } else { $msg = 'invalid'; }

        $ret = null;
        if (isset($_POST['return']) && is_string($_POST['return']) && str_starts_with($_POST['return'], '/')) $ret = $_POST['return'];
        $qs = http_build_query(['saved' => ($ok ? $msg : $msg), 'sr' => $rating, 'sn' => $notes, 'smc' => $mediaCondition, 'ssc' => $sleeveCondition]);
        if ($ret) $qs .= '&return=' . rawurlencode($ret);
        $this->redirect('/release/' . $rid . '?' . $qs);
    }

    public function add(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/login'); }
        if (!$this->isCsrfValid()) { $this->redirect('/'); }

        $rid = (int)($_POST['release_id'] ?? 0);
        $action = (string)($_POST['action'] ?? 'add_collection');
        $ret = (string)($_POST['return'] ?? '/');
        $username = (string)$currentUser['discogs_username'];

        if ($rid > 0 && $username) {
            $discogsClient = new DiscogsHttpClient('MyMusicCollection/1.0', $currentUser['discogs_token'], new KvStore($this->pdo));
            $http = $discogsClient->client();
            try {
                $resp = $http->request('GET', sprintf('releases/%d', $rid));
                if ($resp->getStatusCode() === 200) {
                    $data = json_decode((string)$resp->getBody(), true);
                    $now = gmdate('c');
                    $title = $data['title'] ?? null;
                    $artists = $data['artists'] ?? [];
                    $names = [];
                    foreach ($artists as $a) { $n = $a['name'] ?? null; if ($n) $names[] = $n; }
                    $artistSummary = $names ? implode(', ', $names) : null;
                    $year = isset($data['year']) ? (int)$data['year'] : null;
                    $thumb = $data['thumb'] ?? null;
                    $cover = $data['images'][0]['uri'] ?? $data['cover_image'] ?? null;

                    $cReleases = $this->pdo->prepare('INSERT INTO releases (id, title, artist, year, thumb_url, cover_url, imported_at, updated_at, raw_json) VALUES (:id, :title, :artist, :year, :thumb_url, :cover_url, :imported_at, :updated_at, :raw_json) ON CONFLICT(id) DO UPDATE SET title = COALESCE(excluded.title, releases.title), artist = COALESCE(excluded.artist, releases.artist), year = COALESCE(excluded.year, releases.year), thumb_url = COALESCE(excluded.thumb_url, releases.thumb_url), cover_url = COALESCE(excluded.cover_url, releases.cover_url), updated_at = excluded.updated_at, raw_json = COALESCE(excluded.raw_json, releases.raw_json)');
                    $cReleases->execute([':id' => $rid, ':title' => $title, ':artist' => $artistSummary, ':year' => $year, ':thumb_url' => $thumb, ':cover_url' => $cover, ':imported_at' => $now, ':updated_at' => $now, ':raw_json' => json_encode($data, JSON_UNESCAPED_SLASHES)]);

                    $pushAction = ($action === 'add_collection') ? 'add_collection' : 'add_want';
                    $ins = $this->pdo->prepare('INSERT INTO push_queue (instance_id, release_id, username, action) VALUES (:iid, :rid, :u, :action)');
                    $ins->execute([':iid' => 0, ':rid' => $rid, ':u' => $username, ':action' => $pushAction]);

                    if ($action === 'add_want') {
                        $insWant = $this->pdo->prepare('INSERT OR IGNORE INTO wantlist_items (username, release_id, added) VALUES (:u, :rid, :added)');
                        $insWant->execute([':u' => $username, ':rid' => $rid, ':added' => $now]);
                    }
                }
            } catch (\Throwable $e) { /* Error handling */ }
        }
        $this->redirect($ret);
    }

    private function isCsrfValid(): bool
    {
        return isset($_POST['_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_token']);
    }
}
