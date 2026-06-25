<?php

declare(strict_types=1);

namespace BatchZipStream\Exceptions;

/**
 * Thrown when state persistence operations fail.
 */
class StatePersistenceException extends BatchZipStreamException
{
    private string $sessionId;

    public function __construct(
        string $message,
        string $sessionId = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->sessionId = $sessionId;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }
}
