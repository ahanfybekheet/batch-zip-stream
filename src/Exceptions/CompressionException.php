<?php

declare(strict_types=1);

namespace BatchZipStream\Exceptions;

/**
 * Thrown when compression operations fail.
 */
class CompressionException extends BatchZipStreamException
{
    private string $filename;
    private int $method;

    public function __construct(
        string $message,
        string $filename = '',
        int $method = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->filename = $filename;
        $this->method = $method;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMethod(): int
    {
        return $this->method;
    }
}
