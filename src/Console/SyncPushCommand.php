<?php
declare(strict_types=1);

namespace App\Console;

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
        $baseDir = dirname(__DIR__, 2);
        $envFile = $baseDir.'/.env';
        if (is_file($envFile)) {
            \Dotenv\Dotenv::createImmutable($baseDir)->load();
        }
        $dbPath = $_ENV['DB_PATH'] ?? $_SERVER['DB_PATH'] ?? getenv('DB_PATH') ?? 'var/app.db';
        if (!($dbPath !== '' && ($dbPath[0] === DIRECTORY_SEPARATOR || preg_match('#^[A-Za-z]:[\\/]#', $dbPath)))) {
            $dbPath = $baseDir . '/' . ltrim($dbPath, '/');
        }
        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        $ua = $_ENV['USER_AGENT'] ?? $_SERVER['USER_AGENT'] ?? getenv('USER_AGENT') ?? 'MyDiscogsApp/0.1 (+push)';

        // Use current logged-in user (from kv_store)
        $kv = new KvStore($pdo);
        $uidStr = $kv->get('current_user_id', '');
        $uid = (int)($uidStr ?: '0');
        if ($uid <= 0) {
            $output->writeln('<error>No user is logged in. Please sign in via the web app first.</error>');
            return Command::INVALID;
        }
        $appKey = $_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? getenv('APP_KEY') ?: null;
        $crypto = new \App\Infrastructure\Crypto($appKey, $baseDir);
        $st = $pdo->prepare('SELECT discogs_username, discogs_token_enc FROM auth_users WHERE id = :id');
        $st->execute([':id' => $uid]);
        $row = $st->fetch();
        $username = $row && $row['discogs_username'] ? (string)$row['discogs_username'] : '';
        $token = '';
        if ($row && $row['discogs_token_enc']) {
            $dec = $crypto->decrypt((string)$row['discogs_token_enc']);
            $token = $dec ?: '';
        }
        if ($username === '' || $token === '') {
            $output->writeln('<error>Your Discogs credentials are not configured. Go to /settings and save your Discogs username and token.</error>');
            return Command::INVALID;
        }

        $kv = new KvStore($pdo);
        $client = new DiscogsHttpClient($ua, $token, $kv);
        $writer = new DiscogsCollectionWriter($client);

        // Feature flag: only push notes when explicitly enabled
        $sendNotes = (($_ENV['PUSH_NOTES'] ?? $_SERVER['PUSH_NOTES'] ?? getenv('PUSH_NOTES')) === '1');

        // Resolve the field_id for the built-in "Notes" collection field (only if pushing notes)
        $notesFieldId = null;
        if ($sendNotes) {
            try {
                $resp = $client->client()->request('GET', sprintf('users/%s/collection/fields', rawurlencode($username)), ['timeout' => 20]);
                if ($resp->getStatusCode() >= 200 && $resp->getStatusCode() < 300) {
                    $data = json_decode((string)$resp->getBody(), true);
                    if (is_array($data)) {
                        foreach ($data as $field) {
                            $name = isset($field['name']) ? strtolower((string)$field['name']) : '';
                            if ($name === 'notes' && isset($field['id'])) { $notesFieldId = (int)$field['id']; break; }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal; we'll skip notes if we can't resolve field id
                $notesFieldId = null;
            }
        }

        // Fetch a batch of pending jobs
        $stmt = $pdo->query("SELECT id, instance_id, release_id, username, rating, notes, attempts FROM push_queue WHERE status = 'pending' ORDER BY created_at ASC, id ASC LIMIT 50");
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

            try {
                $notesToSend = ($sendNotes && $notes !== null && $notesFieldId !== null) ? $notes : null;
                $res = $writer->updateInstance($u, $rid, $iid, $folderId, $rating, $notesToSend, $notesFieldId);
                if ($res['ok']) {
                    $upd = $pdo->prepare("UPDATE push_queue SET status='done', attempts = attempts + 1, last_error = NULL WHERE id = :id");
                    $upd->execute([':id' => $id]);
                    $processed++;
                    $extra = $notesToSend !== null ? ' notes=1' : '';
                    $output->writeln("<info>OK</info> #$id release=$rid instance=$iid folder=$folderId code=".$res['code'].$extra);
                } else {
                    // store concise error for diagnostics
                    $body = trim($res['body'] ?? '');
                    if (strlen($body) > 400) { $body = substr($body, 0, 400) . '…'; }
                    $err = 'HTTP ' . ($res['code'] ?? 0) . ' ' . $body;
                    $upd = $pdo->prepare("UPDATE push_queue SET attempts = attempts + 1, last_error = :err, status = CASE WHEN attempts + 1 >= 5 THEN 'failed' ELSE status END WHERE id = :id");
                    $upd->execute([':id' => $id, ':err' => $err]);
                    $failed++;
                    $output->writeln("<error>FAIL</error> #$id release=$rid instance=$iid folder=$folderId code=".($res['code'] ?? 0));
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
