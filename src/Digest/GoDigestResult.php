<?php

declare(strict_types=1);

namespace App\Digest;

use Symfony\Component\Validator\Constraints as Assert;

/** Structured model result returned by the private Go GraphQL operation. */
final readonly class GoDigestResult
{
    /** @param list<array<string, mixed>> $groups
     * @param list<string>               $recommendedOrder
     * @param list<array<string, mixed>> $excluded
     * @param list<array<string, mixed>> $events
     * @param list<string>               $warnings
     */
    public function __construct(
        #[Assert\Count(max: 5)]
        public array $recommendedOrder,
        public array $groups,
        public array $excluded,
        public array $events,
        public string $selectionSummary,
        public array $warnings,
        public string $analysisModel,
        public string $draftModel,
        #[Assert\NotBlank]
        public string $analysisPromptVersion,
        #[Assert\NotBlank]
        public string $draftPromptVersion,
        public ?string $analysisUsageJson,
        public ?string $draftUsageJson,
        #[Assert\PositiveOrZero]
        public int $analysisDurationMs,
        #[Assert\PositiveOrZero]
        public int $draftDurationMs,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::strings($data, 'recommendedOrder'),
            self::records($data, 'groups'),
            self::records($data, 'excluded'),
            self::records($data, 'events'),
            self::string($data, 'selectionSummary'),
            self::strings($data, 'warnings'),
            self::string($data, 'analysisModel'),
            self::string($data, 'draftModel'),
            self::string($data, 'analysisPromptVersion'),
            self::string($data, 'draftPromptVersion'),
            self::optionalString($data, 'analysisUsageJson'),
            self::optionalString($data, 'draftUsageJson'),
            self::integer($data, 'analysisDurationMs'),
            self::integer($data, 'draftDurationMs'),
        );
    }

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Invalid Go digest field: '.$key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException('Invalid Go digest field: '.$key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Invalid Go digest field: '.$key);
        }

        return $value;
    }

    /** @param array<string, mixed> $data
     * @return list<string>
     */
    public static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid Go digest list: '.$key);
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException('Invalid Go digest list item: '.$key);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function records(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid Go digest records: '.$key);
        }
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \UnexpectedValueException('Invalid Go digest record: '.$key);
            }
        }

        return $value;
    }
}
