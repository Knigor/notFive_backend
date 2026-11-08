<?php

declare(strict_types=1);

use App\Dataset\DatasetFileReader;
use App\Dataset\DatasetMaterial;
use Symfony\Component\Validator\Validation;

require dirname(__DIR__).'/vendor/autoload.php';

$directory = sys_get_temp_dir().'/notfive-dataset-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);

$item = [
    'id' => 'A-1',
    'title' => 'Security update',
    'text' => 'A vendor published an update.',
    'source' => 'Vendor',
    'url' => 'https://vendor.example/update',
    'published_at' => '2026-11-06T09:00:00+03:00',
    'missing' => [],
    'error' => null,
];
file_put_contents($directory.'/news.json', json_encode([
    'control_time' => '2026-11-06T10:00:00+03:00',
    'period_start' => '2026-11-05T10:00:00+03:00',
    'timezone' => 'Europe/Moscow',
    'items' => [$item],
], JSON_THROW_ON_ERROR));
file_put_contents($directory.'/news.csv', "\xEF\xBB\xBFid;title;text;source;url;published_at;missing;error\nA-1;Security update;A vendor published an update.;Vendor;https://vendor.example/update;2026-11-06T09:00:00+03:00;;\n");

$reader = new DatasetFileReader();
$json = $reader->read($directory.'/news.json');
$csv = $reader->read($directory.'/news.csv');
$validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

assert(1 === count($json->rows));
assert(1 === count($csv->rows));
assert('2026-11-06T10:00:00+03:00' === $json->controlTime?->format(DATE_ATOM));
assert(null === $csv->controlTime);
assert(array_column($json->rows, 'id') === array_column($csv->rows, 'id'));

foreach ($json->rows as $row) {
    assert(0 === count($validator->validate(DatasetMaterial::fromRow($row))));
}

foreach ($csv->rows as $row) {
    assert(0 === count($validator->validate(DatasetMaterial::fromRow($row))));
}

$invalid = DatasetMaterial::fromRow(['id' => null, 'missing' => ['text', 42]]);
assert(count($validator->validate($invalid)) > 0);

unlink($directory.'/news.json');
unlink($directory.'/news.csv');
rmdir($directory);

$organizerDirectory = dirname(__DIR__).'/Дайджест_данные_для_команд/train';

if (is_file($organizerDirectory.'/news.json') && is_file($organizerDirectory.'/news.csv')) {
    $organizerJson = $reader->read($organizerDirectory.'/news.json');
    $organizerCsv = $reader->read($organizerDirectory.'/news.csv');
    assert(30 === count($organizerJson->rows));
    assert(30 === count($organizerCsv->rows));
    assert(array_column($organizerJson->rows, 'id') === array_column($organizerCsv->rows, 'id'));
}

echo "Dataset formats and row validation: OK\n";
