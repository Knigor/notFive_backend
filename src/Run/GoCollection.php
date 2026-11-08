<?php

declare(strict_types=1);

namespace App\Run;

final class GoCollection
{
    /** @param array<string, mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid Go collection field.');
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException('Invalid Go collection item.');
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function articles(array $data): array
    {
        $value = $data['articles'] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid Go collection articles.');
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException('Invalid Go article.');
            }
        }

        return $value;
    }
}
