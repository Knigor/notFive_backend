<?php

declare(strict_types=1);

use App\Digest\PublishedDigest;
use App\Digest\PublishedEvent;
use App\Telegram\TelegramApiClient;
use App\Telegram\TelegramDigestSender;
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
$digest = new PublishedDigest('artifact-id', 'Дайджест & новости', "Тема: Дайджест & новости\n\n## 1. Событие\nСуть: <текст>\nИсточники: https://example.org/?a=1&b=2", [
    new PublishedEvent('00000000-0000-0000-0000-000000000001', 1, 'Событие', 'Описание', 'Последствия', []),
]);

$sender = new TelegramDigestSender($telegram, $messages);
assert('17' === $sender->send('42', $digest, 'ru'));
assert('POST' === $requests[0][0]);
assert('42' === $requests[0][2]['chat_id']);
assert('HTML' === $requests[0][2]['parse_mode']);
assert(str_starts_with($requests[0][2]['text'], '<b>🟩 NOTFIVE / DIGEST</b>'));
assert(str_contains($requests[0][2]['text'], '<b>Дайджест &amp; новости</b>'));
assert(str_contains($requests[0][2]['text'], '<b>Суть:</b> &lt;текст&gt;'));
assert(str_contains($requests[0][2]['text'], 'https://example.org/?a=1&amp;b=2'));
assert(1 === substr_count($requests[0][2]['text'], 'Дайджест'));
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
assert('🔔 Включить рассылку' === $messages->homeButtons('ru', 'inactive')[1][0]['text']);
assert('🔕 Отключить рассылку' === $messages->homeButtons('ru', 'active')[1][0]['text']);
assert('🔔 Включить рассылку' === $messages->settingsButtons('ru', 'inactive')[1][0]['text']);
assert('🔕 Отключить рассылку' === $messages->settingsButtons('ru', 'active')[1][0]['text']);
assert('🔔 Turn on updates' === $messages->homeButtons('en', 'inactive')[1][0]['text']);
assert('🔕 Turn off updates' === $messages->homeButtons('en', 'active')[1][0]['text']);
assert('🔔 Turn on updates' === $messages->settingsButtons('en', 'inactive')[1][0]['text']);
assert('🔕 Turn off updates' === $messages->settingsButtons('en', 'active')[1][0]['text']);
assert(grapheme_strlen($messages->profileDescription('ru')) <= 512);
assert(grapheme_strlen($messages->profileShortDescription('en')) <= 120);

$telegram->setDescription($messages->profileDescription('ru'));
$telegram->setShortDescription($messages->profileShortDescription('en'), 'en');
assert(str_ends_with($requests[1][1], '/setMyDescription'));
assert(!isset($requests[1][2]['language_code']));
assert(str_ends_with($requests[2][1], '/setMyShortDescription'));
assert('en' === $requests[2][2]['language_code']);

$twoEvents = new PublishedDigest('artifact-id', 'Тема выпуска', "## 1. Первое\nОписание\n\n## 2. Второе\nИсточники: https://example.org/end", $digest->events);
$sender->send('42', $twoEvents, 'ru');
assert(5 === count($requests));
assert(!isset($requests[3][2]['reply_markup']));
assert(str_contains($requests[3][2]['text'], '<b>Тема выпуска</b>'));
assert(str_contains($requests[3][2]['text'], "<b>01</b>\n<b>Первое</b>"));
assert(str_starts_with($requests[4][2]['text'], "<b>02</b>\n<b>Второе</b>"));
assert(str_contains($requests[4][2]['text'], 'https://example.org/end'));
assert(isset($requests[4][2]['reply_markup']));

$legacy = new PublishedDigest('artifact-id', null, "1. Первое\nОписание\n\n2. Второе\nОписание", []);
assert(2 === count($messages->digest($legacy, 'ru')));

echo "Telegram unit smoke passed.\n";
