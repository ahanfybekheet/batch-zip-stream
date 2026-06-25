<?php

declare(strict_types=1);

namespace BatchZipStream\Streams;

use BatchZipStream\Contracts\WritableStreamInterface;
use BatchZipStream\Exceptions\WriteFailureException;

/**
 * Buffered writable stream that batches writes.
 * 
 * Collects data into a buffer and flushes to the underlying stream
 * when the buffer reaches a threshold. This is useful for:
 * - Reducing the number of system calls
 * - Optimizing network I/O for cloud storage
 * - Aligning writes to specific chunk sizes (e.g., multipart upload parts)
 * 
 * IMPORTANT: Always call flush() before close() or persisting state
 * to ensure all data is written.
 */
final class BufferedWritableStream implements WritableStreamInterface
{
    private WritableStreamInterface $inner;
    private string $buffer = '';
    private int $bufferSize;
    private int $bytesWritten = 0;
    private int $bytesFlushed = 0;
    private bool $isOpen = true;

    /**
     * Create a buffered writable stream.
     * 
     * @param WritableStreamInterface $inner Underlying stream
     * @param int $bufferSize Buffer threshold in bytes (default 1MB)
     */
    public function __construct(WritableStreamInterface $inner, int $bufferSize = 1048576)
    {
        if ($bufferSize < 4096) {
            throw new \InvalidArgumentException('Buffer size must be at least 4096 bytes');
        }

        $this->inner = $inner;
        $this->bufferSize = $bufferSize;
    }

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
        $this->bytesWritten += $length;

        // Flush if buffer exceeds threshold
        while (strlen($this->buffer) >= $this->bufferSize) {
            $this->flushBuffer($this->bufferSize);
        }

        return $length;
    }

    /**
     * @inheritDoc
     */
    public function getBytesWritten(): int
    {
        return $this->bytesWritten;
    }

    /**
     * @inheritDoc
     */
    public function flush(): void
    {
        if (!$this->isOpen) {
            throw new WriteFailureException('Stream is not open');
        }

        // Flush remaining buffer
        if (strlen($this->buffer) > 0) {
            $this->flushBuffer(strlen($this->buffer));
        }

        $this->inner->flush();
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if (!$this->isOpen) {
            return;
        }

        // Flush any remaining data
        if (strlen($this->buffer) > 0) {
            $this->flushBuffer(strlen($this->buffer));
        }

        $this->isOpen = false;
        $this->inner->close();
    }

    /**
     * @inheritDoc
     */
    public function finalize(): void
    {
        $this->close();
        $this->inner->finalize();
        return;
    }

    /**
     * @inheritDoc
     */
    public function abort(): void
    {
        $this->isOpen = false;
        $this->buffer = '';
        $this->inner->abort();
    }

    /**
     * @inheritDoc
     */
    public function isOpen(): bool
    {
        return $this->isOpen && $this->inner->isOpen();
    }

    /**
     * @inheritDoc
     */
    public function getPosition(): int
    {
        // Position includes buffered but not yet flushed data
        return $this->bytesFlushed + strlen($this->buffer);
    }

    /**
     * Get the number of bytes currently buffered.
     */
    public function getBufferedBytes(): int
    {
        return strlen($this->buffer);
    }

    /**
     * Get the number of bytes flushed to the inner stream.
     */
    public function getBytesFlushed(): int
    {
        return $this->bytesFlushed;
    }

    /**
     * Flush a specific number of bytes from the buffer.
     * 
     * @param int $length Bytes to flush
     * @throws WriteFailureException On write failure
     */
    private function flushBuffer(int $length): void
    {
        $chunk = substr($this->buffer, 0, $length);
        $written = $this->inner->write($chunk);

        if ($written !== strlen($chunk)) {
            throw new WriteFailureException(
                sprintf('Buffer flush failed: expected %d bytes, wrote %d', strlen($chunk), $written),
                strlen($chunk),
                $written,
                $this->bytesFlushed
            );
        }

        $this->buffer = substr($this->buffer, $length);
        $this->bytesFlushed += $written;
    }

    /**
     * Destructor - ensure stream is closed.
     */
    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable $e) {
            // Ignore exceptions in destructor
        }
    }
}
