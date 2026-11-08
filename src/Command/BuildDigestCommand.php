<?php

declare(strict_types=1);

namespace App\Command;

use App\Digest\DigestBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('digest:build', description: 'Build digest artifacts from a collected run.')]
final class BuildDigestCommand extends Command
{
    public function __construct(private readonly DigestBuilder $builder, private readonly TranslatorInterface $translator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('run-id', InputArgument::REQUIRED, 'Run UUID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runId = (string) $input->getArgument('run-id');
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId)) {
            $io->error($this->translator->trans('digest_export.invalid_run_id'));

            return Command::INVALID;
        }

        try {
            $result = $this->builder->build($runId);
        } catch (\Throwable $exception) {
            $io->error($this->translator->trans('digest_export.build_failed'));

            return Command::FAILURE;
        }

        $io->success($this->translator->trans('digest_export.built', ['%run_id%' => $result['runId'], '%events%' => $result['eventCount']]));
        foreach ($result['warnings'] as $warning) {
            $io->warning($warning);
        }

        return Command::SUCCESS;
    }
}
