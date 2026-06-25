<?php

declare(strict_types=1);

namespace BatchZipStream\Contracts;

/**
 * Abstract readable stream interface for file input.
 * 
 * Used to read source files incrementally for compression.
 * Implementations may read from:
 * - Local filesystem
 * - Remote storage
 * - Network streams
 */
interface ReadableStreamInterface
{
    /**
     * Read a chunk of data from the stream.
     * 
     * @param int $length Maximum bytes to read
     * @return string Binary data (may be shorter than $length at EOF)
     * @throws \BatchZipStream\Exceptions\ReadFailureException On read error
     */
    public function read(int $length): string;

    /**
     * Check if end of stream is reached.
     * 
     * @return bool True if no more data available
     */
    public function eof(): bool;

    /**
     * Get total size of the stream if known.
     * 
     * @return int|null Size in bytes, or null if unknown
     */
    public function getSize(): ?int;

    /**
     * Close the stream and release resources.
     */
    public function close(): void;

    /**
     * Get the stream identifier (path, URI, etc.) for logging.
     * 
     * @return string Human-readable identifier
     */
    public function getIdentifier(): string;
}
