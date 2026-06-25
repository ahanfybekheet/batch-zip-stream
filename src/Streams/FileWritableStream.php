<?php

declare(strict_types=1);

namespace BatchZipStream\Streams;

use BatchZipStream\Contracts\WritableStreamInterface;
use BatchZipStream\Exceptions\WriteFailureException;

/**
 * File-based writable stream implementation.
 * 
 * Writes to a local file. Supports both new files and appending to existing files.
 * 
 * This is a reference implementation. For production cloud deployments,
 * implement WritableStreamInterface for your specific storage backend.
 */
final class FileWritableStream implements WritableStreamInterface
{
    /** @var resource|null File handle */
    private $handle = null;

    private string $filePath;
    private int $bytesWritten = 0;
    private int $startPosition = 0;
    private bool $isOpen = false;

    /**
     * Create a new file writable stream.
     * 
     * @param string $filePath Path to the file
     * @param bool $append Whether to append to existing file
     * @throws WriteFailureException If file cannot be opened
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;

        // For append mode, use r+b and seek to end to get correct position
        if (file_exists($filePath)) {
            $handle = @fopen($filePath, 'r+b');
            if ($handle !== false) {
                fseek($handle, 0, SEEK_END);
            }
        } else {
            $handle = @fopen($filePath, 'wb');
        }

        if ($handle === false) {
            throw new WriteFailureException(
                sprintf('Failed to open file for writing: %s', $filePath)
            );
        }

        $this->handle = $handle;
        $this->isOpen = true;

        // Record start position for append mode
        $this->startPosition = (int) ftell($this->handle);
    }

    /**
     * @inheritDoc
     */
    public function write(string $data): int
    {
        if (!$this->isOpen || $this->handle === null) {
            throw new WriteFailureException('Stream is not open');
        }

        $length = strlen($data);
        if ($length === 0) {
            return 0;
        }

        $written = @fwrite($this->handle, $data);

        if ($written === false) {
            throw new WriteFailureException(
                sprintf('Write failed at position %d', $this->getPosition()),
                $length,
                0,
                $this->getPosition()
            );
        }

        if ($written !== $length) {
            throw new WriteFailureException(
                sprintf(
                    'Incomplete write at position %d: expected %d bytes, wrote %d',
                    $this->getPosition(),
                    $length,
                    $written
                ),
                $length,
                $written,
                $this->getPosition()
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
        if (!$this->isOpen || $this->handle === null) {
            throw new WriteFailureException('Stream is not open');
        }

        if (!@fflush($this->handle)) {
            throw new WriteFailureException(
                'Failed to flush stream',
                0,
                0,
                $this->getPosition()
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }
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
        $this->close();
        @unlink($this->filePath);
        return;
    }


    /**
     * @inheritDoc
     */
    public function isOpen(): bool
    {
        return $this->isOpen && $this->handle !== null;
    }

    /**
     * @inheritDoc
     */
    public function getPosition(): int
    {
        if ($this->handle === null) {
            return $this->startPosition + $this->bytesWritten;
        }

        $position = ftell($this->handle);
        return $position !== false ? $position : ($this->startPosition + $this->bytesWritten);
    }

    /**
     * Get the file path.
     * 
     * @return string File path
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Destructor - ensure file is closed.
     */
    public function __destruct()
    {
        $this->close();
    }
}
