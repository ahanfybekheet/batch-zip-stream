<?php

declare(strict_types=1);

namespace BatchZipStream\Streams;

use BatchZipStream\Contracts\ReadableStreamInterface;

/**
 * String-based readable stream for small data.
 * 
 * Wraps a string as a readable stream. Useful for testing
 * or for adding small files without creating temporary files.
 * 
 * WARNING: The entire string is held in memory.
 */
final class StringReadableStream implements ReadableStreamInterface
{
    private string $data;
    private int $position = 0;
    private string $identifier;

    /**
     * Create a string readable stream.
     * 
     * @param string $data The data to read
     * @param string $identifier Identifier for logging
     */
    public function __construct(string $data, string $identifier = 'string://memory')
    {
        $this->data = $data;
        $this->identifier = $identifier;
    }

    /**
     * @inheritDoc
     */
    public function read(int $length): string
    {
        if ($this->position >= strlen($this->data)) {
            return '';
        }

        $chunk = substr($this->data, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    /**
     * @inheritDoc
     */
    public function eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    /**
     * @inheritDoc
     */
    public function getSize(): ?int
    {
        return strlen($this->data);
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        // Free memory
        $this->data = '';
        $this->position = 0;
    }

    /**
     * @inheritDoc
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
