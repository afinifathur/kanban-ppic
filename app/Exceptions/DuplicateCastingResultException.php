<?php

namespace App\Exceptions;

use InvalidArgumentException;

class DuplicateCastingResultException extends InvalidArgumentException
{
    /**
     * Structured details regarding the duplicate conflict.
     */
    protected array $duplicateInfo = [];

    public function __construct(string $message, array $duplicateInfo = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->duplicateInfo = $duplicateInfo;
    }

    /**
     * Get the structured duplicate details.
     */
    public function getDuplicateInfo(): array
    {
        return $this->duplicateInfo;
    }
}
