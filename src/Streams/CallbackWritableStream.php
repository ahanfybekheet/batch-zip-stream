<?php

declare(strict_types=1);

namespace BatchZipStream\Streams;

use BatchZipStream\Contracts\WritableStreamInterface;
use BatchZipStream\Exceptions\WriteFailureException;

/**
 * Callback-based writable stream.
 * 
 * Passes each write to a callback function. Useful for:
 * - Multipart upload systems
 * - Custom buffering strategies
 * - Integration with cloud SDKs
 * - Testing and debugging
 * 
 * The callback receives each chunk of data and is responsible
 * for actually persisting it.
 */
final class CallbackWritableStream implements WritableStreamInterface
{
    /** @var callable(string): int */
    private $writeCallback;

    /** @var callable(): void|null */
    private $flushCallback;

    /** @var callable(): void|null */
    private $closeCallback;

    /** @var callable(): void|null */
    private $abortCallback;

    /** @var callable(): void|null */
    private $finalizeCallback;

    private int $bytesWritten = 0;
    private bool $isOpen = true;
    private string $identifier;

    /**
     * Create a callback writable stream.
     * 
     * @param callable(string): int $writeCallback Called for each write, must return bytes written
     * @param callable(): void|null $flushCallback Called on flush
     * @param callable(): void|null $closeCallback Called on close
     * @param callable(): void|null $finalizeCallback Called on finalize
     * @param callable(): void|null $abortCallback Called on abort
     * @param string $identifier Identifier for logging
     */
    public function __construct(
        callable $writeCallback,
        ?callable $flushCallback = null,
        ?callable $closeCallback = null,
        ?callable $finalizeCallback = null,
        ?callable $abortCallback = null,
        string $identifier = 'callback://stream'
    ) {
        $this->writeCallback = $writeCallback;
        $this->flushCallback = $flushCallback;
        $this->closeCallback = $closeCallback;
        $this->identifier = $identifier;
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
        if ($length === 0) {
            return 0;
        }

        try {
            $written = ($this->writeCallback)($data);
        } catch (\Throwable $e) {
            throw new WriteFailureException(
                'Write callback failed: ' . $e->getMessage(),
                $length,
                0,
                $this->bytesWritten,
                $e
            );
        }

        if ($written !== $length) {
            throw new WriteFailureException(
                sprintf('Incomplete write: expected %d bytes, wrote %d', $length, $written),
                $length,
                $written,
                $this->bytesWritten
            );
        }

        $this->bytesWritten += $written;
        return $written;
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

        if ($this->flushCallback !== null) {
            try {
                ($this->flushCallback)();
            } catch (\Throwable $e) {
                throw new WriteFailureException(
                    'Flush callback failed: ' . $e->getMessage(),
                    0,
                    0,
                    $this->bytesWritten,
                    $e
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if (!$this->isOpen) {
            return;
        }

        $this->isOpen = false;

        if ($this->closeCallback !== null) {
            try {
                ($this->closeCallback)();
            } catch (\Throwable $e) {
                throw new WriteFailureException(
                    'Close callback failed: ' . $e->getMessage(),
                    0,
                    0,
                    $this->bytesWritten,
                    $e
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function finalize(): void
    {
        $this->close();

        if ($this->finalizeCallback !== null) {
            try {
                ($this->finalizeCallback)();
            } catch (\Throwable $e) {
                throw new WriteFailureException(
                    'Finalize callback failed: ' . $e->getMessage(),
                    0,
                    0,
                    $this->bytesWritten,
                    $e
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function abort(): void
    {
        $this->isOpen = false;
        if ($this->abortCallback !== null) {
            try {
                ($this->abortCallback)();
            } catch (\Throwable $e) {
                throw new WriteFailureException(
                    'Abort callback failed: ' . $e->getMessage(),
                    0,
                    0,
                    $this->bytesWritten,
                    $e
                );
            }
        }
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
        return $this->bytesWritten;
    }

    /**
     * Get the stream identifier.
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }
}
