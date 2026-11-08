<?php

declare(strict_types=1);

use App\Telegram\PublishedDigestRepository;
use App\Telegram\TelegramApiClient;
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
$subscriptions = new TelegramSubscriptionRepository($connection);
$handler = new TelegramUpdateHandler($telegram, $subscriptions, new PublishedDigestRepository($connection), new TelegramMessages($translator));
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

echo "Telegram polling smoke passed.\n";
