<?php

declare(strict_types=1);

namespace App\Command;

use App\Telegram\TelegramPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('telegram:poll', description: 'Receive Telegram updates using long polling')]
final class PollTelegramCommand extends Command
{
    public function __construct(private readonly TelegramPoller $poller)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Fetch one batch and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $offset = 0;

        do {
            $offset = $this->poller->pollOnce($offset);
        } while (true !== $input->getOption('once'));

        return Command::SUCCESS;
    }
}
