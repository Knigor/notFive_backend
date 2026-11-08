<?php

declare(strict_types=1);

namespace App\Command;

use App\Telegram\TelegramApiClient;
use App\Telegram\TelegramMessages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('telegram:configure-profile', description: 'Update the Telegram bot descriptions')]
final class ConfigureTelegramProfileCommand extends Command
{
    public function __construct(
        private readonly TelegramApiClient $telegram,
        private readonly TelegramMessages $messages,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->telegram->setDescription($this->messages->profileDescription('ru'));
        $this->telegram->setShortDescription($this->messages->profileShortDescription('ru'));

        foreach (['ru', 'en'] as $locale) {
            $this->telegram->setDescription($this->messages->profileDescription($locale), $locale);
            $this->telegram->setShortDescription($this->messages->profileShortDescription($locale), $locale);
        }

        return Command::SUCCESS;
    }
}
