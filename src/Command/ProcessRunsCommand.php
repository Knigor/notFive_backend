<?php

declare(strict_types=1);

namespace App\Command;

use App\Run\RunProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('digest:process-runs', description: 'Process queued digest runs.')]
final class ProcessRunsCommand extends Command
{
    public function __construct(private readonly RunProcessor $processor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process at most one run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while (true) {
            if ($this->processor->processNext()) {
                if ($input->getOption('once')) {
                    return Command::SUCCESS;
                }

                continue;
            }
            if ($input->getOption('once')) {
                return Command::SUCCESS;
            }
            sleep(2);
        }
    }
}
