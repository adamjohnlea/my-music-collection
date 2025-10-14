<?php
declare(strict_types=1);

namespace App\Console;

use App\Images\ImageCache;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use App\Infrastructure\Config;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'images:backfill', description: 'Download missing cover images to the local cache (1 rps, 1000/day).')]
class ImagesBackfillCommand extends Command
{
    protected function configure()
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max images to download in this run', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $config->getDbPath($baseDir);
        $userAgent = $config->getUserAgent('MyDiscogsApp/0.1 (+images)');

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

        // Normalize DB paths: make local_path relative to project root (strip absolute base prefix)
        $basePrefix = rtrim($base, '/\\') . '/';
        try {
            $sel = $pdo->prepare('SELECT id, local_path FROM images WHERE local_path LIKE :pfx');
            $sel->execute([':pfx' => $basePrefix . '%']);
            $toFix = $sel->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($toFix as $fix) {
                $lp = (string)$fix['local_path'];
                if (str_starts_with($lp, $basePrefix)) {
                    $new = ltrim(substr($lp, strlen($basePrefix)), '/\\');
                    $up = $pdo->prepare('UPDATE images SET local_path = :p WHERE id = :id');
                    $up->execute([':p' => $new, ':id' => $fix['id']]);
                }
            }
        } catch (\Throwable $e) {
            // best-effort; continue
        }

        // Move any mistakenly nested images (e.g., <base>/<base>/public/images -> <base>/public/images)
        $nested = $base . '/' . ltrim($base, '/\\') . '/public/images';
        $target = $base . '/public/images';
        if (is_dir($nested) && $nested !== $target) {
            $output->writeln('<comment>Repairing misplaced images…</comment>');
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($nested, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $path => $info) {
                if ($info->isFile()) {
                    $rel = ltrim(substr($path, strlen($nested)), '/\\');
                    $dst = $target . '/' . $rel;
                    $dir = dirname($dst);
                    if (!is_dir($dir)) @mkdir($dir, 0777, true);
                    @rename($path, $dst);
                }
            }
            // attempt to remove empty dirs
            $it2 = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($nested, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it2 as $p => $info2) { if ($info2->isDir()) @rmdir($p); }
            @rmdir($nested);
        }

        $done = 0; $skipped = 0; $failed = 0; $quotaStop = false;
        foreach ($rows as $row) {
            if ($done >= $limit) break;
            $lp = (string)$row['local_path'];
            $local = $config->isAbsolutePath($lp) ? $lp : ($base . '/' . ltrim($lp, '/'));
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
