<?php
declare(strict_types=1);

namespace App\Console;

use App\Domain\Valuation\ValuationTeardown;
use App\Infrastructure\Config;
use App\Infrastructure\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'value:reset', description: 'Remove all valuation data (drops valuation tables; other data untouched).')]
final class ValueResetCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Required to actually drop the tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('confirm')) {
            $output->writeln('<comment>This will delete all valuation data. Re-run with --confirm to proceed.</comment>');
            return Command::SUCCESS;
        }

        $config = new Config();
        $pdo = (new Storage($config->getDbPath(dirname(__DIR__, 2))))->pdo();
        ValuationTeardown::reset($pdo);
        $output->writeln('<info>Valuation data removed. Run any command to re-create empty tables.</info>');
        return Command::SUCCESS;
    }
}
