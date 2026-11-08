<?php

declare(strict_types=1);

namespace App\Command;

use App\Digest\PublishedDigestRepository;
use App\Telegram\TelegramApiException;
use App\Telegram\TelegramDeliveryRepository;
use App\Telegram\TelegramDigestSender;
use App\Telegram\TelegramSubscriptionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand('telegram:send-digest', description: 'Send the latest published digest to Telegram subscribers')]
final class SendTelegramDigestCommand extends Command
{
    public function __construct(
        private readonly PublishedDigestRepository $digests,
        private readonly TelegramSubscriptionRepository $subscriptions,
        private readonly TelegramDeliveryRepository $deliveries,
        private readonly TelegramDigestSender $sender,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $digest = $this->digests->latest();

        if (null === $digest || null === $digest->postArtifactId) {
            $output->writeln($this->translator->trans('telegram.command.no_digest'));

            return Command::FAILURE;
        }

        $sent = 0;
        $failed = 0;

        foreach ($this->subscriptions->activeTargets() as $target) {
            $deliveryId = $this->deliveries->claim($digest->postArtifactId, $target['id']);

            if (null === $deliveryId) {
                continue;
            }

            try {
                $messageId = $this->sender->send($target['destination'], $digest, $target['locale']);
                $this->deliveries->sent($deliveryId, $messageId);
                ++$sent;
            } catch (TelegramApiException $exception) {
                $this->deliveries->failed($deliveryId, $exception->getMessage());
                ++$failed;
            }
        }

        $output->writeln($this->translator->trans('telegram.command.delivery_result', [
            '%sent%' => $sent,
            '%failed%' => $failed,
        ]));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
