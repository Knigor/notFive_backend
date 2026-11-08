<?php

declare(strict_types=1);

namespace App\Dataset;

final class DatasetImportException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
