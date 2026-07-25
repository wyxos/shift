<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class ExternalNotificationException extends RuntimeException
{
    public function __construct(
        public readonly string $failureType,
        public readonly bool $retryable,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $statusCode === null
                ? "External notification failed: {$failureType}"
                : "External notification failed with status {$statusCode}: {$failureType}",
            previous: $previous,
        );
    }
}
