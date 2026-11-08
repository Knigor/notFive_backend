<?php

declare(strict_types=1);

namespace App\Telegram;

final readonly class TelegramPoller
{
    public function __construct(
        private TelegramApiClient $telegram,
        private TelegramUpdateRepository $updates,
        private TelegramUpdateHandler $handler,
    ) {
    }

    public function pollOnce(int $offset, int $timeout = 20): int
    {
        foreach ($this->telegram->getUpdates($offset, $timeout) as $update) {
            $updateId = $update['update_id'] ?? null;

            if (!is_int($updateId)) {
                throw new TelegramApiException('Telegram returned an update without an ID.');
            }

            if (!$this->updates->hasProcessed($updateId)) {
                $this->handler->handle($update);
                $this->updates->markProcessed($updateId);
            }

            $offset = max($offset, $updateId + 1);
        }

        return $offset;
    }
}
