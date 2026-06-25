<?php

declare(strict_types=1);

namespace BatchZipStream\Streams;

use BatchZipStream\Contracts\ReadableStreamInterface;
use BatchZipStream\Exceptions\ReadFailureException;

/**
 * File-based readable stream implementation.
 * 
 * Reads from a local file in chunks. This is a reference implementation
 * for local file operations.
 */
final class FileReadableStream implements ReadableStreamInterface
{
    /** @var resource|null File handle */
    private $handle = null;

    private string $filePath;
    private ?int $size;
    private bool $eof = false;

    /**
     * Create a new file readable stream.
     * 
     * @param string $filePath Path to the file
     * @throws ReadFailureException If file cannot be opened
     */
    public function __construct(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new ReadFailureException(
                sprintf('File does not exist: %s', $filePath),
                $filePath
            );
        }

        if (!is_readable($filePath)) {
            throw new ReadFailureException(
                sprintf('File is not readable: %s', $filePath),
                $filePath
            );
        }

        $this->filePath = $filePath;
        $this->size = filesize($filePath) ?: null;

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new ReadFailureException(
                sprintf('Failed to open file for reading: %s', $filePath),
                $filePath
            );
        }

        $this->handle = $handle;
    }

    /**
     * @inheritDoc
     */
    public function read(int $length): string
    {
        if ($this->handle === null) {
            throw new ReadFailureException('Stream is closed', $this->filePath);
        }

        if ($this->eof) {
            return '';
        }

        $data = @fread($this->handle, $length);

        if ($data === false) {
            throw new ReadFailureException(
                sprintf('Read failed at position %d', ftell($this->handle)),
                $this->filePath
            );
        }

        if (feof($this->handle)) {
            $this->eof = true;
        }

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function eof(): bool
    {
        if ($this->handle === null) {
            return true;
        }

        return $this->eof || feof($this->handle);
    }

    /**
     * @inheritDoc
     */
    public function getSize(): ?int
    {
        return $this->size;
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
        $this->eof = true;
    }

    /**
     * @inheritDoc
     */
    public function getIdentifier(): string
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
