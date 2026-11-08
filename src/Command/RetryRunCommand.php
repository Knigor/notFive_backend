<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\ImportRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('digest:retry-run', description: 'Requeue a failed digest run after inspecting the cause.')]
final class RetryRunCommand extends Command
{
    public function __construct(private readonly ImportRepository $repository, private readonly TranslatorInterface $translator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('run-id', InputArgument::REQUIRED, 'Run UUID.');
        $this->addOption('force-running', null, InputOption::VALUE_NONE, 'Requeue an interrupted running run after stopping the worker.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runId = (string) $input->getArgument('run-id');
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId)) {
            $output->writeln($this->translator->trans('digest_export.invalid_run_id', locale: 'ru'));

            return Command::INVALID;
        }
        if (!$this->repository->requeue($runId, (bool) $input->getOption('force-running'))) {
            $output->writeln($this->translator->trans('run_status.retry_unavailable', locale: 'ru'));

            return Command::FAILURE;
        }
        $output->writeln($this->translator->trans('run_status.requeued', locale: 'ru'));

        return Command::SUCCESS;
    }
}
