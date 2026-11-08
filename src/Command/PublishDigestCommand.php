<?php

declare(strict_types=1);

namespace App\Command;

use App\Digest\DigestPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('digest:publish', description: 'Publish a completed digest and send its email newsletter.')]
final class PublishDigestCommand extends Command
{
    public function __construct(private readonly DigestPublisher $publisher, private readonly TranslatorInterface $translator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('run-id', InputArgument::REQUIRED, 'Run UUID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->publisher->publish((string) $input->getArgument('run-id'));
        } catch (\InvalidArgumentException $exception) {
            $output->writeln($this->translator->trans('digest_publish.'.$exception->getMessage()));

            return Command::INVALID;
        }

        $output->writeln($this->translator->trans('digest_publish.result', [
            '%run_id%' => $result['runId'], '%sent%' => $result['emailSent'],
            '%failed%' => $result['emailFailed'], '%pending%' => $result['emailPending'],
        ]));

        return 0 === $result['emailFailed'] && 0 === $result['emailPending'] ? Command::SUCCESS : Command::FAILURE;
    }
}
