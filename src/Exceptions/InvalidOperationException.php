<?php

declare(strict_types=1);

namespace BatchZipStream\Exceptions;

/**
 * Thrown when attempting an invalid operation on the archive.
 * 
 * Examples:
 * - Adding files after finalization
 * - Finalizing an empty archive
 * - Operations on a failed archive
 */
class InvalidOperationException extends BatchZipStreamException
{
    private string $currentPhase;
    private string $attemptedOperation;

    public function __construct(
        string $message,
        string $currentPhase = '',
        string $attemptedOperation = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->currentPhase = $currentPhase;
        $this->attemptedOperation = $attemptedOperation;
    }

    public function getCurrentPhase(): string
    {
        return $this->currentPhase;
    }

    public function getAttemptedOperation(): string
    {
        return $this->attemptedOperation;
    }
}
