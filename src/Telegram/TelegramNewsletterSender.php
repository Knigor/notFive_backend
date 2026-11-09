<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Digest\DeliveryRepository;
use App\Digest\PublishedDigestRepository;
use Doctrine\DBAL\Connection;

final readonly class TelegramNewsletterSender
{
    public function __construct(
        private Connection $connection,
        private DeliveryRepository $deliveries,
        private PublishedDigestRepository $digests,
        private TelegramApiClient $telegram,
        private TelegramMessages $messages,
    ) {
    }

    /** @return array{sent: int, failed: int, queued: int} */
    public function send(string $runId): array
    {
        $digest = $this->telegram->isConfigured() ? $this->digests->find($runId) : null;
        if (null === $digest || null === $digest->postArtifactId) {
            return $this->deliveries->counts($runId, 'telegram');
        }

        foreach ($this->deliveries->pending($runId, 'telegram') as $delivery) {
            // Commit each accepted part before trying the next one.
            do {
                $more = $this->connection->transactional(function () use ($delivery, $digest): bool {
                    $id = $delivery['id'];
                    $target = $this->deliveries->lockMessage($id, $delivery['targetId'], 'telegram');
                    if (null === $target) {
                        return false;
                    }

                    $parts = $this->messages->digest($digest, $target['locale']);
                    $index = $target['nextPart'];
                    if (!isset($parts[$index])) {
                        $this->deliveries->failed($id, 'telegram_part_missing');

                        return false;
                    }

                    $last = $index === array_key_last($parts);
                    $buttons = $last ? $this->messages->digestButtons($digest, $target['locale']) : [];
                    $this->deliveries->attempt($id);
                    try {
                        $messageId = $this->telegram->sendMessage($target['recipient'], $parts[$index], $buttons, html: true);
                    } catch (TelegramApiException) {
                        $this->deliveries->failed($id, 'telegram_transport_failed');

                        return false;
                    }

                    if ($last) {
                        $this->deliveries->sent($id, $messageId);

                        return false;
                    }

                    $this->deliveries->advance($id);

                    return true;
                });
            } while ($more);
        }

        return $this->deliveries->counts($runId, 'telegram');
    }
}
