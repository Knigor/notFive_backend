<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Digest\PublishedDigest;

final readonly class TelegramDigestSender
{
    public function __construct(private TelegramApiClient $telegram, private TelegramMessages $messages)
    {
    }

    public function send(string $chatId, PublishedDigest $digest, string $locale): string
    {
        $parts = $this->messages->digest($digest, $locale);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            $this->telegram->sendMessage($chatId, $part, html: true);
        }

        return $this->telegram->sendMessage($chatId, $last, $this->messages->digestButtons($digest, $locale), html: true);
    }
}
