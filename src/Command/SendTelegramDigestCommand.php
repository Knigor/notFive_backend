<?php

declare(strict_types=1);

namespace App\Command;

use App\Digest\PublishedDigestRepository;
use App\Telegram\TelegramNewsletterSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('telegram:send-digest', description: 'Retry queued or failed Telegram deliveries for a published digest')]
final class SendTelegramDigestCommand extends Command
{
    public function __construct(
        private readonly PublishedDigestRepository $digests,
        private readonly TelegramNewsletterSender $sender,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('runId', InputArgument::OPTIONAL, 'Published digest run ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argument = $input->getArgument('runId');
        $runId = is_string($argument) ? $argument : $this->digests->latestRunId();
        if (null === $runId || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId)) {
            $output->writeln($this->translator->trans('telegram.command.no_digest'));

            return Command::FAILURE;
        }

        $counts = $this->sender->send($runId);
        $output->writeln($this->translator->trans('telegram.command.delivery_result', [
            '%sent%' => $counts['sent'],
            '%failed%' => $counts['failed'],
            '%pending%' => $counts['queued'],
        ]));

        return 0 === $counts['failed'] + $counts['queued'] ? Command::SUCCESS : Command::FAILURE;
    }
}
