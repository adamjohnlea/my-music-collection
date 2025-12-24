<?php
declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Config;
use App\Http\DiscogsCollectionWriter;
use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:push', description: 'Push queued rating/notes changes back to Discogs')]
class SyncPushCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $config->getDbPath($baseDir);
        $ua = $config->getUserAgent('MyDiscogsApp/0.1 (+push)');

        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        // Resolve Discogs credentials from config
        $username = $config->getDiscogsUsername();
        $token = $config->getDiscogsToken();

        if (!$username || !$token) {
            $output->writeln('<error>Discogs credentials (DISCOGS_USERNAME and DISCOGS_TOKEN) not found in .env</error>');
            return 2;
        }

        $kv = new KvStore($pdo);
        $client = new DiscogsHttpClient($ua, $token, $kv);
        $writer = new DiscogsCollectionWriter($client);
        $wantWriter = new \App\Http\DiscogsWantlistWriter($client);

        // Feature flag: only push notes when explicitly enabled
        $sendNotes = ($config->env('PUSH_NOTES') === '1');

        // Resolve the field_id for the built-in "Notes" collection field (only if pushing notes)
        $notesFieldId = null;
        if ($sendNotes) {
            try {
                $resp = $client->client()->request('GET', sprintf('users/%s/collection/fields', rawurlencode($username)), ['timeout' => 20]);
                if ($resp->getStatusCode() === 404) {
                    $output->writeln(sprintf('<error>Discogs API error: User \'%s\' does not exist or may have been deleted. Please check DISCOGS_USERNAME in your .env file.</error>', $username));
                    return 2;
                }
                if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
                    $data = json_decode((string)$resp->getBody(), true);
                    if (is_array($data) && isset($data['fields']) && is_array($data['fields'])) {
                        foreach ($data['fields'] as $field) {
                            $name = isset($field['name']) ? strtolower((string)$field['name']) : '';
                            if ($name === 'notes' && isset($field['id'])) { $notesFieldId = (int)$field['id']; break; }
                        }
                    }
                }
                if ($notesFieldId === null) {
                    $output->writeln('<comment>Warning: Could not resolve "Notes" field ID from Discogs. Falling back to default field 3.</comment>');
                    $notesFieldId = 3; // Discogs default for Notes is usually 3
                }
            } catch (\Throwable $e) {
                $output->writeln('<comment>Warning: Failed to fetch collection fields: ' . $e->getMessage() . '. Using default field 3.</comment>');
                $notesFieldId = 3;
            }
        }

        // Fetch a batch of pending jobs
        $stmt = $pdo->query("SELECT id, instance_id, release_id, username, rating, notes, media_condition, sleeve_condition, attempts, action FROM push_queue WHERE status = 'pending' ORDER BY created_at ASC, id ASC LIMIT 50");
        $jobs = $stmt->fetchAll();
        if (!$jobs) {
            $output->writeln('<info>No pending jobs.</info>');
            return Command::SUCCESS;
        }

        $processed = 0; $failed = 0;
        foreach ($jobs as $job) {
            $id = (int)$job['id'];
            $iid = (int)$job['instance_id'];
            $rid = (int)$job['release_id'];
            $u = (string)$job['username'];
            $rating = isset($job['rating']) ? (int)$job['rating'] : null;
            $notes = $job['notes'] !== null ? (string)$job['notes'] : null;
            $mediaCondition = $job['media_condition'] !== null ? (string)$job['media_condition'] : null;
            $sleeveCondition = $job['sleeve_condition'] !== null ? (string)$job['sleeve_condition'] : null;
            $action = (string)($job['action'] ?? 'update_collection');

            try {
                if ($action === 'add_want') {
                    $res = $wantWriter->addToWantlist($u, $rid);
                } elseif ($action === 'add_collection') {
                    $res = $wantWriter->addToCollection($u, $rid);
                } elseif ($action === 'remove_want') {
                    $res = $wantWriter->removeFromWantlist($u, $rid);
                } elseif ($action === 'want_to_collection') {
                    // Chained action: add to collection, then remove from wantlist
                    $res = $wantWriter->addToCollection($u, $rid);
                    if ($res['ok']) {
                        $res = $wantWriter->removeFromWantlist($u, $rid);
                    }
                } else {
                    // Default: update_collection
                    // Determine folder_id for this instance; required by Discogs endpoint
                    $folderId = 0;
                    $fs = $pdo->prepare('SELECT folder_id FROM collection_items WHERE instance_id = :iid LIMIT 1');
                    $fs->execute([':iid' => $iid]);
                    $fRow = $fs->fetch();
                    if ($fRow && isset($fRow['folder_id'])) {
                        $folderId = (int)$fRow['folder_id'];
                    }
                    if ($folderId <= 0) {
                        // Discogs typically uses folder 1 for the default folder; fallback to 1 if unknown
                        $folderId = 1;
                    }

                    $fields = [];
                    if ($sendNotes && $notes !== null) {
                        $fields[$notesFieldId] = $notes;
                    }
                    if ($mediaCondition !== null) {
                        $fields[1] = $mediaCondition; // Field 1 is Media Condition
                    }
                    if ($sleeveCondition !== null) {
                        $fields[2] = $sleeveCondition; // Field 2 is Sleeve Condition
                    }

                    $res = $writer->updateInstance($u, $rid, $iid, $folderId, $rating, $fields);
                    
                    if ($res['ok']) {
                        if (isset($fields[$notesFieldId])) $output->writeln("  <info>-> Notes pushed to field ID $notesFieldId</info>");
                        if (isset($fields[1])) $output->writeln("  <info>-> Media Condition pushed to field ID 1</info>");
                        if (isset($fields[2])) $output->writeln("  <info>-> Sleeve Condition pushed to field ID 2</info>");
                    }
                }

                if ($res['ok']) {
                    $upd = $pdo->prepare("UPDATE push_queue SET status='done', attempts = attempts + 1, last_error = NULL WHERE id = :id");
                    $upd->execute([':id' => $id]);
                    $processed++;
                    $output->writeln("<info>OK</info> #$id action=$action release=$rid");
                } else {
                    // store concise error for diagnostics
                    $body = trim($res['body'] ?? '');
                    if (strlen($body) > 400) { $body = substr($body, 0, 400) . '…'; }
                    $err = 'HTTP ' . ($res['code'] ?? 0) . ' ' . $body;
                    $upd = $pdo->prepare("UPDATE push_queue SET attempts = attempts + 1, last_error = :err, status = CASE WHEN attempts + 1 >= 5 THEN 'failed' ELSE status END WHERE id = :id");
                    $upd->execute([':id' => $id, ':err' => $err]);
                    $failed++;
                    $output->writeln("<error>FAIL</error> #$id action=$action release=$rid code=".($res['code'] ?? 0));
                }
            } catch (\Throwable $e) {
                $upd = $pdo->prepare("UPDATE push_queue SET attempts = attempts + 1, last_error = :err, status = CASE WHEN attempts + 1 >= 5 THEN 'failed' ELSE status END WHERE id = :id");
                $upd->execute([':id' => $id, ':err' => $e->getMessage()]);
                $failed++;
                $output->writeln("<error>ERROR</error> #$id: ".$e->getMessage());
            }
        }

        $output->writeln("Done. processed=$processed failed=$failed");
        return Command::SUCCESS;
    }
}
