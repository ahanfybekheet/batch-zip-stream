<?php

declare(strict_types=1);

namespace BatchZipStream\Exceptions;

/**
 * Thrown when reading from a source stream fails.
 */
class ReadFailureException extends BatchZipStreamException
{
    private string $streamIdentifier;

    public function __construct(
        string $message,
        string $streamIdentifier = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->streamIdentifier = $streamIdentifier;
    }

    public function getStreamIdentifier(): string
    {
        return $this->streamIdentifier;
    }
}
