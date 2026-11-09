<?php

declare(strict_types=1);

use App\Digest\DeliveryRepository;
use App\Digest\PublishedDigestRepository;
use App\Telegram\TelegramApiClient;
use App\Telegram\TelegramDigestSender;
use App\Telegram\TelegramMessages;
use App\Telegram\TelegramPoller;
use App\Telegram\TelegramSubscriptionRepository;
use App\Telegram\TelegramUpdateHandler;
use App\Telegram\TelegramUpdateRepository;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

require dirname(__DIR__).'/vendor/autoload.php';

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$connection->executeStatement('CREATE TABLE telegram_updates (update_id INTEGER PRIMARY KEY, processed_at TEXT)');
$connection->executeStatement('CREATE TABLE delivery_targets (id TEXT PRIMARY KEY, channel TEXT, destination TEXT, status TEXT, confirmed_at TEXT, locale TEXT, UNIQUE(channel, destination))');
$connection->executeStatement('CREATE TABLE runs (id TEXT PRIMARY KEY, status TEXT, published_at TEXT)');
$connection->executeStatement('CREATE TABLE artifacts (id TEXT PRIMARY KEY, run_id TEXT, content TEXT, kind TEXT)');
$connection->executeStatement('CREATE TABLE email_preview_details (artifact_id TEXT PRIMARY KEY, subject TEXT)');
$connection->executeStatement('CREATE TABLE events (id TEXT PRIMARY KEY, run_id TEXT, rank INTEGER, title TEXT, summary TEXT, consequences TEXT, selection_reason TEXT)');
$connection->executeStatement('CREATE TABLE materials (id TEXT PRIMARY KEY, source_name TEXT, url TEXT)');
$connection->executeStatement('CREATE TABLE event_materials (event_id TEXT, material_id TEXT, is_primary_source BOOLEAN)');
$pdo = $connection->getNativeConnection();
assert($pdo instanceof PDO);
$pdo->sqliteCreateFunction('gen_random_uuid', static fn (): string => '00000000-0000-0000-0000-000000000001');

$updates = [
    ['update_id' => 100, 'message' => ['chat' => ['id' => 42, 'type' => 'private'], 'text' => '/start']],
    ['update_id' => 101, 'callback_query' => ['id' => 'language', 'from' => ['id' => 42], 'message' => ['chat' => ['id' => 42, 'type' => 'private']], 'data' => 'lang:en']],
    ['update_id' => 102, 'callback_query' => ['id' => 'latest', 'from' => ['id' => 42], 'message' => ['chat' => ['id' => 42, 'type' => 'private']], 'data' => 'latest']],
];
$requests = [];
$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $updates): MockResponse {
    $name = basename($url);
    $requests[] = [$name, json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR)];

    return match ($name) {
        'getUpdates' => new MockResponse(json_encode(['ok' => true, 'result' => $updates], JSON_THROW_ON_ERROR)),
        'sendMessage' => new MockResponse('{"ok":true,"result":{"message_id":17}}'),
        default => new MockResponse('{"ok":true,"result":true}'),
    };
});

$translator = new Translator('ru');
$translator->addLoader('yaml', new YamlFileLoader());
$translator->addResource('yaml', dirname(__DIR__).'/translations/messages.ru.yaml', 'ru');
$translator->addResource('yaml', dirname(__DIR__).'/translations/messages.en.yaml', 'en');
$telegram = new TelegramApiClient($client, 'test-token', 'https://api.telegram.org');
$subscriptions = new TelegramSubscriptionRepository($connection, new DeliveryRepository($connection));
$messages = new TelegramMessages($translator);
$handler = new TelegramUpdateHandler($telegram, $subscriptions, new PublishedDigestRepository($connection), $messages, new TelegramDigestSender($telegram, $messages));
$poller = new TelegramPoller($telegram, new TelegramUpdateRepository($connection), $handler);

assert(103 === $poller->pollOnce(0, 0));
assert('en' === $connection->fetchOne("SELECT locale FROM delivery_targets WHERE destination = '42' LIMIT 1"));
assert(3 === (int) $connection->fetchOne('SELECT COUNT(*) FROM telegram_updates'));
$poller->pollOnce(0, 0);
assert(3 === count(array_filter($requests, static fn (array $request): bool => 'sendMessage' === $request[0])));
assert(0 === $requests[0][1]['offset']);

$sent = array_values(array_filter($requests, static fn (array $request): bool => 'sendMessage' === $request[0]));
assert(str_contains($sent[0][1]['text'], 'notFive'));
assert(str_contains($sent[1][1]['text'], 'Language: English'));
assert(str_contains($sent[2][1]['text'], 'edition is being prepared'));
assert('success' === $sent[0][1]['reply_markup']['inline_keyboard'][0][0]['style']);

$connection->executeStatement("INSERT INTO runs VALUES ('run', 'completed', CURRENT_TIMESTAMP)");
$connection->executeStatement("INSERT INTO artifacts VALUES ('post', 'run', 'Короткий пост', 'post'), ('email', 'run', 'Полный текст выпуска', 'email_preview')");
$connection->executeStatement("INSERT INTO email_preview_details VALUES ('email', 'Тема выпуска')");
$handler->handle($updates[2]);
$lastIndex = array_key_last($requests);
assert(null !== $lastIndex);
$last = $requests[$lastIndex][1];
assert('HTML' === $last['parse_mode']);
assert(str_contains($last['text'], '<b>Тема выпуска</b>'));
assert(str_contains($last['text'], 'Полный текст выпуска'));
assert(!str_contains($last['text'], 'Короткий пост'));

$connection->executeStatement("INSERT INTO events VALUES ('00000000-0000-0000-0000-000000000001', 'run', 1, 'Новость', 'Описание', 'Последствия', 'Почему в выпуске')");
$connection->executeStatement("INSERT INTO materials VALUES ('source', 'Первоисточник', 'https://example.org/story')");
$connection->executeStatement("INSERT INTO event_materials VALUES ('00000000-0000-0000-0000-000000000001', 'source', TRUE)");
$handler->handle($updates[2]);
$latestCard = $requests[count($requests) - 1][1];
assert(str_contains($latestCard['text'], '<b>🔎 1. Новость</b>'));
assert(str_contains($latestCard['text'], '↗ Sources:'));
assert(str_contains($latestCard['text'], 'Первоисточник https://example.org/story'));
assert($messages->homeButton('en') === $latestCard['reply_markup']['inline_keyboard']);

$oldButton = $updates[2];
$oldButton['callback_query']['data'] = 'event:00000000-0000-0000-0000-000000000001';
$handler->handle($oldButton);
$oldCard = $requests[count($requests) - 1][1];
assert(str_starts_with($oldCard['text'], '<b>🔎 1. Новость</b>'));
assert(str_ends_with($latestCard['text'], $oldCard['text']));

echo "Telegram polling smoke passed.\n";
