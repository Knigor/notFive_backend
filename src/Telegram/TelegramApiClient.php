<?php

declare(strict_types=1);

namespace App\Telegram;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class TelegramApiClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(TELEGRAM_BOT_TOKEN)%')]
        private string $token,
        #[Autowire('%env(TELEGRAM_API_BASE_URL)%')]
        private string $baseUrl,
    ) {
    }

    /** @param list<list<array{ text: string, callback_data?: string, url?: string, style?: 'primary'|'success'|'danger' }>> $buttons */
    public function sendMessage(string $chatId, string $text, array $buttons = []): string
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];

        if ([] !== $buttons) {
            $payload['reply_markup'] = ['inline_keyboard' => $buttons];
        }

        $result = $this->request('sendMessage', $payload);
        $message = $result['result'] ?? null;
        $messageId = is_array($message) ? ($message['message_id'] ?? null) : null;

        if (!is_int($messageId)) {
            throw new TelegramApiException('Telegram did not return a message ID.');
        }

        return (string) $messageId;
    }

    public function answerCallback(string $callbackId): void
    {
        $this->request('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }

    public function setDescription(string $description, ?string $locale = null): void
    {
        $payload = ['description' => $description];

        if (null !== $locale) {
            $payload['language_code'] = $locale;
        }

        $this->request('setMyDescription', $payload);
    }

    public function setShortDescription(string $description, ?string $locale = null): void
    {
        $payload = ['short_description' => $description];

        if (null !== $locale) {
            $payload['language_code'] = $locale;
        }

        $this->request('setMyShortDescription', $payload);
    }

    /** @return list<array<string, mixed>> */
    public function getUpdates(int $offset, int $timeout = 20): array
    {
        $data = $this->request('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], $timeout + 5);
        $updates = $data['result'] ?? null;

        if (!is_array($updates) || !array_is_list($updates)) {
            throw new TelegramApiException('Telegram did not return an update list.');
        }

        return $updates;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $method, array $payload, int $timeout = 10): array
    {
        if ('' === $this->token) {
            throw new TelegramApiException('Telegram bot token is not configured.');
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/bot'.$this->token.'/'.$method, [
                'json' => $payload,
                'timeout' => $timeout,
            ]);
            $data = $response->toArray(false);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface) {
            // Symfony's HTTP exception may contain the token-bearing URL.
            throw new TelegramApiException('Telegram API request failed.');
        }

        if (200 !== $status || true !== ($data['ok'] ?? null)) {
            throw new TelegramApiException('Telegram API rejected the request.');
        }

        return $data;
    }
}
