<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Digest\PublishedDigest;
use App\Digest\PublishedEvent;

final readonly class TelegramDigestSender
{
    public function __construct(private TelegramApiClient $telegram, private TelegramMessages $messages)
    {
    }

    public function send(string $chatId, PublishedDigest $digest, string $locale): string
    {
        return $this->sendParts($chatId, $this->messages->latestDigest($digest, $locale), $this->messages->homeButton($locale));
    }

    public function sendEvent(string $chatId, PublishedEvent $event, string $locale): void
    {
        $this->sendParts($chatId, $this->messages->event($event, $locale), $this->messages->backButton($locale));
    }

    /**
     * @param non-empty-list<string>                                                                         $parts
     * @param list<list<array{ text: string, callback_data: string, style?: 'primary'|'success'|'danger' }>> $buttons
     */
    private function sendParts(string $chatId, array $parts, array $buttons): string
    {
        $last = array_pop($parts);
        foreach ($parts as $part) {
            $this->telegram->sendMessage($chatId, $part, html: true);
        }

        return $this->telegram->sendMessage($chatId, $last, $buttons, html: true);
    }
}
