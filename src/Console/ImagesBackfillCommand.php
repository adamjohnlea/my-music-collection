<?php
declare(strict_types=1);

namespace App\Console;

use App\Images\ImageCache;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'images:backfill', description: 'Download missing cover images to the local cache (1 rps, 1000/day).')]
class ImagesBackfillCommand extends Command
{
    private function env($key, $default = null)
    {
        $value = isset($_ENV[$key]) ? $_ENV[$key] : (isset($_SERVER[$key]) ? $_SERVER[$key] : getenv($key));
        if ($value === false || $value === null) {
            return $default;
        }
        return $value;
    }

    private function isAbsolutePath($path)
    {
        if ($path === '') return false;
        if ($path[0] === DIRECTORY_SEPARATOR) return true;
        return (bool)preg_match('#^[A-Za-z]:[\\/]#', $path);
    }

    protected function configure()
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max images to download in this run', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPath = $this->env('DB_PATH', 'var/app.db');
        $baseDir = dirname(__DIR__, 2);
        if (!$this->isAbsolutePath($dbPath)) {
            $dbPath = $baseDir . '/' . ltrim($dbPath, '/');
        }

        $userAgent = $this->env('USER_AGENT', 'MyDiscogsApp/0.1 (+images)');

        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();
        $kv = new KvStore($pdo);
        $cache = new ImageCache($kv, $userAgent);

        $limit = (int)$input->getOption('limit');
        if ($limit <= 0) $limit = 200;

        $output->writeln('<info>Starting image backfill…</info>');

        // Fetch candidates
        $stmt = $pdo->query("SELECT id, release_id, source_url, local_path FROM images ORDER BY id ASC");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $base = dirname(__DIR__, 2);
        $done = 0; $skipped = 0; $failed = 0; $quotaStop = false;
        foreach ($rows as $row) {
            if ($done >= $limit) break;
            $local = $base . '/' . ltrim($row['local_path'], '/');
            if (is_file($local)) { $skipped++; continue; }

            $ok = $cache->fetch($row['source_url'], $local);
            if ($ok) {
                $done++;
                // update fetched_at and bytes
                $bytes = @filesize($local);
                $now = gmdate('c');
                $u = $pdo->prepare('UPDATE images SET bytes = :b, fetched_at = :t WHERE id = :id');
                $u->execute([':b' => $bytes !== false ? $bytes : null, ':t' => $now, ':id' => $row['id']]);
                $output->writeln(sprintf('  ✓ [%d] release %d', $row['id'], $row['release_id']));
            } else {
                // Could be quota or HTTP failure; check daily quota key
                $today = gmdate('Ymd');
                $dailyKey = 'rate:images:daily_count:' . $today;
                $count = (int)($kv->get($dailyKey, '0') ?: '0');
                if ($count >= 1000) { $quotaStop = true; break; }
                $failed++;
                $output->writeln(sprintf('  ! Failed to fetch [%d] %s', $row['id'], $row['source_url']));
            }
        }

        $msg = sprintf('Backfill complete. downloaded=%d, skipped=%d, failed=%d', $done, $skipped, $failed);
        if ($quotaStop) {
            $msg .= ' (stopped due to daily cap)';
        }
        $output->writeln('<info>'.$msg.'</info>');

        return Command::SUCCESS;
    }
}
