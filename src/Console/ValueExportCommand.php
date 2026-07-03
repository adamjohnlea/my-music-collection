<?php
declare(strict_types=1);

namespace App\Console;

use App\Domain\Valuation\InsuranceManifest;
use App\Infrastructure\Config;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteValuationRepository;
use App\Infrastructure\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'value:export', description: 'Export a dated CSV insurance manifest of collection values.')]
final class ValueExportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output CSV path', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $storage = new Storage($config->getDbPath($baseDir));
        (new MigrationRunner($storage->pdo()))->run();
        $repo = new SqliteValuationRepository($storage->pdo());

        // All collection items, highest value first (limit large enough to include everything).
        $rows = $repo->getMostValuable('collection', PHP_INT_MAX, 0);
        $totals = $repo->getScopeTotals('collection');
        $csv = InsuranceManifest::toCsv($rows, $totals);

        $out = (string)$input->getOption('out');
        if ($out === '') {
            $out = $baseDir . '/var/valuation-' . gmdate('Ymd') . '.csv';
        }
        file_put_contents($out, $csv);
        $output->writeln('<info>Wrote manifest to ' . $out . '</info>');
        return Command::SUCCESS;
    }
}
