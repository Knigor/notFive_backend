<?php

declare(strict_types=1);

use App\Telegram\PublishedDigest;
use App\Telegram\PublishedEvent;
use App\Telegram\TelegramApiClient;
use App\Telegram\TelegramMessages;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

require dirname(__DIR__).'/vendor/autoload.php';

$requests = [];
$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
    $requests[] = [$method, $url, json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR)];

    return new MockResponse('{"ok":true,"result":{"message_id":17}}');
});

$telegram = new TelegramApiClient($client, 'test-token', 'https://api.telegram.org');
$translator = new Translator('ru');
$translator->addLoader('yaml', new YamlFileLoader());
$translator->addResource('yaml', dirname(__DIR__).'/translations/messages.ru.yaml', 'ru');
$translator->addResource('yaml', dirname(__DIR__).'/translations/messages.en.yaml', 'en');
$messages = new TelegramMessages($translator);
$digest = new PublishedDigest('artifact-id', 'Готовый пост', [
    new PublishedEvent('00000000-0000-0000-0000-000000000001', 1, 'Событие', 'Описание', 'Последствия', []),
]);

assert('17' === $telegram->sendMessage('42', $messages->digest($digest, 'ru'), $messages->digestButtons($digest, 'ru')));
assert('POST' === $requests[0][0]);
assert('42' === $requests[0][2]['chat_id']);
assert('Готовый пост' === $requests[0][2]['text']);
assert('event:00000000-0000-0000-0000-000000000001' === $requests[0][2]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
assert(str_contains($messages->home('en', 'active'), 'Subscription is on.'));
assert(str_contains($messages->noDigest('ru'), 'Выпуск готовится'));
assert(str_contains($messages->noDigest('en'), 'edition is being prepared'));
assert('home' === $messages->homeButton('ru')[0][0]['callback_data']);
assert('lang:en' === $messages->settingsButtons('ru', 'inactive')[0][1]['callback_data']);
assert('success' === ($messages->homeButtons('ru', 'inactive')[0][0]['style'] ?? null));
assert('primary' === ($messages->homeButtons('ru', 'inactive')[1][0]['style'] ?? null));
assert('danger' === ($messages->homeButtons('ru', 'active')[1][0]['style'] ?? null));
assert('success' === ($messages->settingsButtons('en', 'active')[0][1]['style'] ?? null));
assert(grapheme_strlen($messages->profileDescription('ru')) <= 512);
assert(grapheme_strlen($messages->profileShortDescription('en')) <= 120);

$telegram->setDescription($messages->profileDescription('ru'));
$telegram->setShortDescription($messages->profileShortDescription('en'), 'en');
assert(str_ends_with($requests[1][1], '/setMyDescription'));
assert(!isset($requests[1][2]['language_code']));
assert(str_ends_with($requests[2][1], '/setMyShortDescription'));
assert('en' === $requests[2][2]['language_code']);

echo "Telegram unit smoke passed.\n";
