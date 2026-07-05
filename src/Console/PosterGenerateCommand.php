<?php
declare(strict_types=1);

namespace App\Console;

use App\Domain\Poster\PosterOrderer;
use App\Domain\Search\QueryParser;
use App\Images\CoverColorExtractor;
use App\Images\PosterComposer;
use App\Infrastructure\Config;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\PosterReleaseFinder;
use App\Infrastructure\Storage;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'poster:generate', description: 'Render a cover-wall poster image from your collection')]
final class PosterGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('wantlist', null, InputOption::VALUE_NONE, 'Use the wantlist instead of the collection')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Search query to narrow the poster (e.g. "genre:Jazz")')
            ->addOption('smart', null, InputOption::VALUE_REQUIRED, 'Saved smart-collection name to use as the filter')
            ->addOption('order', null, InputOption::VALUE_REQUIRED, 'Ordering: added|artist|title|year|rating|valuation|shuffle|color', 'added')
            ->addOption('cols', null, InputOption::VALUE_REQUIRED, 'Columns (default: auto near-square)')
            ->addOption('resolution', null, InputOption::VALUE_REQUIRED, 'Long-edge pixels (max 7200)', '4000')
            ->addOption('gap', null, InputOption::VALUE_REQUIRED, 'Gap between tiles in px', '0')
            ->addOption('bg', null, InputOption::VALUE_REQUIRED, 'Background colour', '#111111')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Optional caption; adds a title bar + stats footer')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'jpg|png', 'jpg')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Shuffle seed', '0')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory', 'var/posters')
            ->addOption('compute-colors-only', null, InputOption::VALUE_NONE, 'Only compute+store missing cover colours, then exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!extension_loaded('imagick')) {
            $output->writeln('<error>Imagick extension is required for poster generation.</error>');
            return Command::FAILURE;
        }

        $baseDir = dirname(__DIR__, 2);
        $config = new Config();
        $storage = new Storage($config->getDbPath($baseDir));
        $pdo = $storage->pdo();
        (new MigrationRunner($pdo))->run();

        $username = $this->resolveUsername($pdo, $config);
        if ($username === null) {
            $output->writeln('<error>No Discogs username configured.</error>');
            return Command::INVALID;
        }

        $scope = $input->getOption('wantlist') ? 'wantlist' : 'collection';

        $filter = $input->getOption('filter') !== null ? (string)$input->getOption('filter') : null;
        if ($input->getOption('smart') !== null) {
            $filter = $this->smartQuery($pdo, (string)$input->getOption('smart')) ?? $filter;
        }

        $finder = new PosterReleaseFinder($pdo, new QueryParser());
        $rows = $finder->find($username, $scope, $filter);
        if ($rows === []) {
            $output->writeln('<error>No releases matched — nothing to render.</error>');
            return Command::INVALID;
        }

        $order = (string)$input->getOption('order');
        $computeOnly = (bool)$input->getOption('compute-colors-only');

        if ($order === 'color' || $computeOnly) {
            $this->ensureColors($pdo, $rows, $baseDir, $output);
            // reload colours after extraction
            $rows = $finder->find($username, $scope, $filter);
            if ($computeOnly) {
                $output->writeln('<info>Cover colours computed.</info>');
                return Command::SUCCESS;
            }
        }

        $rows = (new PosterOrderer())->order($rows, $order, (int)$input->getOption('seed'));

        $placeholders = 0;
        $tiles = [];
        foreach ($rows as $r) {
            $abs = $r['cover_path'] !== null ? $baseDir . '/' . ltrim((string)$r['cover_path'], '/') : null;
            $hasCover = $abs !== null && is_file($abs);
            if (!$hasCover) { $placeholders++; }
            $tiles[] = [
                'path' => $hasCover ? $abs : null,
                'color' => $r['cover_color'] ?? $this->hashColor($r['artist'] . '|' . $r['title']),
                'caption' => trim($r['artist'] . ' — ' . $r['title'], ' —'),
            ];
        }

        $count = count($tiles);
        $cols = $input->getOption('cols') !== null
            ? max(1, (int)$input->getOption('cols'))
            : max(1, (int)round(sqrt($count)));

        $outDir = (string)$input->getOption('out');
        if ($outDir[0] !== '/' && !preg_match('#^[A-Za-z]:[\\/]#', $outDir)) {
            $outDir = $baseDir . '/' . ltrim($outDir, '/');
        }
        if (!is_dir($outDir)) { mkdir($outDir, 0777, true); }

        $format = ((string)$input->getOption('format')) === 'png' ? 'png' : 'jpg';
        $filename = 'poster-' . date('Ymd-His') . '.' . $format;
        $outPath = $outDir . '/' . $filename;

        // Optional footer: --title turns on a title bar + stats line.
        $title = $input->getOption('title') !== null ? (string)$input->getOption('title') : '';
        $subtitle = '';
        if ($title !== '') {
            $parts = [sprintf('%d releases', $count)];
            $total = 0.0;
            $haveValue = false;
            foreach ($rows as $r) {
                if (($r['valuation'] ?? null) !== null) { $total += (float)$r['valuation']; $haveValue = true; }
            }
            if ($haveValue) {
                $symbol = \App\Domain\Valuation\CurrencyFormat::symbol($this->currencyFor($pdo, $scope));
                $parts[] = $symbol . number_format($total, 0);
            }
            $parts[] = date('Y-m-d');
            $subtitle = implode('  •  ', $parts);
        }

        (new PosterComposer())->compose($tiles, [
            'cols' => $cols,
            'resolution' => min(7200, (int)$input->getOption('resolution')),
            'gap' => (int)$input->getOption('gap'),
            'bg' => (string)$input->getOption('bg'),
            'format' => $format,
            'quality' => 90,
            'title' => $title,
            'subtitle' => $subtitle,
        ], $outPath);

        $output->writeln(sprintf('<info>Poster written:</info> %s (%d tiles, %d placeholders)', $outPath, $count, $placeholders));
        $output->writeln('Download: /poster/download?file=' . rawurlencode($filename));
        return Command::SUCCESS;
    }

    /** @param array<int, array<string,mixed>> $rows */
    private function ensureColors(PDO $pdo, array $rows, string $baseDir, OutputInterface $output): void
    {
        $extractor = new CoverColorExtractor();
        $upd = $pdo->prepare('UPDATE images SET cover_color = :c WHERE release_id = :r AND cover_color IS NULL');
        $done = 0;
        foreach ($rows as $r) {
            if (($r['cover_color'] ?? null) !== null || ($r['cover_path'] ?? null) === null) {
                continue;
            }
            $abs = $baseDir . '/' . ltrim((string)$r['cover_path'], '/');
            $hex = $extractor->extract($abs);
            if ($hex !== null) {
                $upd->execute([':c' => $hex, ':r' => (int)$r['id']]);
                $done++;
            }
        }
        if ($done > 0) {
            $output->writeln(sprintf('  - computed %d cover colours', $done));
        }
    }

    private function smartQuery(PDO $pdo, string $name): ?string
    {
        $st = $pdo->prepare('SELECT query FROM saved_searches WHERE name = :n ORDER BY id DESC LIMIT 1');
        $st->execute([':n' => $name]);
        $q = $st->fetchColumn();
        return $q === false ? null : (string)$q;
    }

    private function hashColor(string $seed): string
    {
        return '#' . substr(md5($seed), 0, 6);
    }

    private function currencyFor(PDO $pdo, string $scope): ?string
    {
        $st = $pdo->prepare('SELECT currency FROM item_valuations WHERE scope = :s AND currency IS NOT NULL LIMIT 1');
        $st->execute([':s' => $scope]);
        $c = $st->fetchColumn();
        return $c === false ? null : (string)$c;
    }

    private function resolveUsername(PDO $pdo, Config $config): ?string
    {
        $u = $config->getDiscogsUsername();
        if ($u !== null && $u !== '' && $u !== 'your_username') {
            return $u;
        }
        return null;
    }
}
