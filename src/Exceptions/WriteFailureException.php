<?php

declare(strict_types=1);

namespace BatchZipStream\Exceptions;

/**
 * Thrown when writing to the output stream fails.
 * 
 * This is a FATAL error - the entire ZIP is invalidated.
 */
class WriteFailureException extends BatchZipStreamException
{
    private int $bytesAttempted;
    private int $bytesWritten;
    private int $positionAtFailure;

    public function __construct(
        string $message,
        int $bytesAttempted = 0,
        int $bytesWritten = 0,
        int $positionAtFailure = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->bytesAttempted = $bytesAttempted;
        $this->bytesWritten = $bytesWritten;
        $this->positionAtFailure = $positionAtFailure;
    }

    public function getBytesAttempted(): int
    {
        return $this->bytesAttempted;
    }

    public function getBytesWritten(): int
    {
        return $this->bytesWritten;
    }

    public function getPositionAtFailure(): int
    {
        return $this->positionAtFailure;
    }
}
