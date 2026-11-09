<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Digest\PublishedDigestRepository;

final readonly class TelegramUpdateHandler
{
    public function __construct(
        private TelegramApiClient $telegram,
        private TelegramSubscriptionRepository $subscriptions,
        private PublishedDigestRepository $digests,
        private TelegramMessages $messages,
        private TelegramDigestSender $digestSender,
    ) {
    }

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;

        if (is_array($message)) {
            $this->handleMessage($message);

            return;
        }

        $callback = $update['callback_query'] ?? null;

        if (is_array($callback)) {
            $this->handleCallback($callback);
        }
    }

    /** @param array<string, mixed> $message */
    private function handleMessage(array $message): void
    {
        $chat = $message['chat'] ?? null;

        if (!is_array($chat) || 'private' !== ($chat['type'] ?? null)) {
            return;
        }

        $chatId = $chat['id'] ?? null;

        if (!is_int($chatId)) {
            return;
        }

        $text = $message['text'] ?? null;

        if (!is_string($text)) {
            return;
        }

        $destination = (string) $chatId;
        $this->subscriptions->register($destination);

        match (strtok($text, ' ') ?: '') {
            '/latest' => $this->showLatest($destination),
            '/settings' => $this->showSettings($destination),
            default => $this->showHome($destination),
        };
    }

    /** @param array<string, mixed> $callback */
    private function handleCallback(array $callback): void
    {
        $callbackId = $callback['id'] ?? null;
        $message = $callback['message'] ?? null;

        if (!is_string($callbackId) || !is_array($message)) {
            return;
        }

        $chat = $message['chat'] ?? null;

        if (!is_array($chat) || 'private' !== ($chat['type'] ?? null)) {
            return;
        }

        $chatId = $chat['id'] ?? null;
        $from = $callback['from'] ?? null;

        if (!is_int($chatId) || !is_array($from) || $chatId !== ($from['id'] ?? null)) {
            return;
        }

        $this->telegram->answerCallback($callbackId);
        $destination = (string) $chatId;
        $action = $callback['data'] ?? null;

        if (!is_string($action)) {
            return;
        }

        if (str_starts_with($action, 'event:')) {
            $this->showEvent($destination, substr($action, 6));

            return;
        }

        if (str_starts_with($action, 'lang:')) {
            $language = substr($action, 5);

            if (in_array($language, ['ru', 'en'], true)) {
                $this->subscriptions->setLocale($destination, $language);
            }

            $this->showSettings($destination);

            return;
        }

        switch ($action) {
            case 'subscribe':
                $this->subscriptions->subscribe($destination);
                $this->showHome($destination);
                break;
            case 'pause':
                $this->subscriptions->pause($destination);
                $this->showHome($destination);
                break;
            case 'latest':
                $this->showLatest($destination);
                break;
            case 'settings':
                $this->showSettings($destination);
                break;
            default:
                $this->showHome($destination);
        }
    }

    private function showHome(string $chatId): void
    {
        $subscription = $this->subscriptions->get($chatId);
        $this->telegram->sendMessage(
            $chatId,
            $this->messages->home($subscription['locale'], $subscription['status']),
            $this->messages->homeButtons($subscription['locale'], $subscription['status']),
        );
    }

    private function showSettings(string $chatId): void
    {
        $subscription = $this->subscriptions->get($chatId);
        $this->telegram->sendMessage(
            $chatId,
            $this->messages->settings($subscription['locale'], $subscription['status']),
            $this->messages->settingsButtons($subscription['locale'], $subscription['status']),
        );
    }

    private function showLatest(string $chatId): void
    {
        $subscription = $this->subscriptions->get($chatId);
        $digest = $this->digests->latest();

        if (null === $digest) {
            $this->telegram->sendMessage(
                $chatId,
                $this->messages->noDigest($subscription['locale']),
                $this->messages->homeButton($subscription['locale']),
            );

            return;
        }

        $this->digestSender->send($chatId, $digest, $subscription['locale']);
    }

    private function showEvent(string $chatId, string $eventId): void
    {
        $subscription = $this->subscriptions->get($chatId);
        $event = $this->digests->event($eventId);

        if (null === $event) {
            $this->telegram->sendMessage(
                $chatId,
                $this->messages->noEvent($subscription['locale']),
                $this->messages->homeButton($subscription['locale']),
            );

            return;
        }

        $this->digestSender->sendEvent($chatId, $event, $subscription['locale']);
    }
}
