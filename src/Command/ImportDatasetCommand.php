<?php

declare(strict_types=1);

namespace App\Command;

use App\Dataset\DatasetFileReader;
use App\Dataset\DatasetImporter;
use App\Dataset\DatasetImportException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('dataset:import', description: 'Import a JSON or CSV news dataset')]
final class ImportDatasetCommand extends Command
{
    public function __construct(
        private readonly DatasetImporter $importer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'Path to the JSON or CSV file');
        $this->addOption('control-time', null, InputOption::VALUE_REQUIRED, 'ISO 8601 control time with timezone; required for CSV');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $time = $input->getOption('control-time');

        if (!is_string($path) || (null !== $time && !is_string($time))) {
            $output->writeln($this->translator->trans('dataset_import.invalid_arguments'));

            return Command::INVALID;
        }

        try {
            $result = $this->importer->import($path, null === $time ? null : DatasetFileReader::parseTime($time));
        } catch (DatasetImportException $exception) {
            $output->writeln($this->translator->trans('dataset_import.failed', [
                '%reason%' => $this->translator->trans('dataset_import.error.'.$exception->reason),
            ]));

            return Command::FAILURE;
        }

        $output->writeln($this->translator->trans('dataset_import.completed', [
            '%run_id%' => $result->runId,
            '%imported%' => $result->imported,
            '%failed%' => $result->failed,
        ]));

        return Command::SUCCESS;
    }
}
