<?php

declare(strict_types=1);

namespace BatchZipStream\Streams;

use BatchZipStream\Contracts\WritableStreamInterface;
use BatchZipStream\Exceptions\WriteFailureException;

/**
 * In-memory writable stream for testing and small archives.
 *
 * All data is held in memory. Useful for:
 * - Unit testing without file I/O
 * - Creating small archives to send directly over HTTP
 * - Capturing output for inspection
 *
 * WARNING: Not suitable for large archives as all data stays in memory.
 */
final class MemoryWritableStream implements WritableStreamInterface
{
    private string $buffer = '';
    private int $position = 0;
    private bool $isOpen = true;

    /**
     * @inheritDoc
     */
    public function write(string $data): int
    {
        if (!$this->isOpen) {
            throw new WriteFailureException('Stream is not open');
        }

        $length = strlen($data);
        $this->buffer .= $data;
        $this->position += $length;

        return $length;
    }

    /**
     * @inheritDoc
     */
    public function getBytesWritten(): int
    {
        return strlen($this->buffer);
    }

    /**
     * @inheritDoc
     */
    public function flush(): void
    {
        // Nothing to flush - all data is in memory
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->isOpen = false;
    }

    /**
     * @inheritDoc
     */
    public function finalize(): void
    {
        $this->close();
        return;
    }

    /**
     * @inheritDoc
     */
    public function abort(): void
    {
        $this->buffer = '';
        $this->position = 0;
        $this->isOpen = false;
    }


    /**
     * @inheritDoc
     */
    public function isOpen(): bool
    {
        return $this->isOpen;
    }

    /**
     * @inheritDoc
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Get the accumulated buffer contents.
     *
     * @return string Raw bytes written to the stream
     */
    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Get buffer length.
     *
     * @return int Number of bytes in buffer
     */
    public function getLength(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Clear the buffer and reset position.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->position = 0;
        $this->isOpen = true;
    }
}
