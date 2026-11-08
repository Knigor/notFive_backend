<?php

declare(strict_types=1);

namespace App\Command;

use App\Digest\DigestFixtureLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('digest:load-fixture', description: 'Load and publish a saved digest fixture without generation or delivery.')]
final class LoadDigestFixtureCommand extends Command
{
    public function __construct(private readonly DigestFixtureLoader $loader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Directory containing the saved digest fixture.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $result = $this->loader->load((string) $input->getArgument('directory'));
        } catch (\InvalidArgumentException|\UnexpectedValueException|\JsonException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        $io->success(sprintf('%s fixture run %s (%d events). No deliveries created.', $result['alreadyLoaded'] ? 'Already loaded' : 'Loaded', $result['runId'], $result['eventCount']));

        return Command::SUCCESS;
    }
}
