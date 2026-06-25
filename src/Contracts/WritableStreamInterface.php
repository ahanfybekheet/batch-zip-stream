<?php

declare(strict_types=1);

namespace BatchZipStream\Contracts;

/**
 * Abstract writable stream interface for ZIP output.
 * 
 * Implementations may write to:
 * - Local filesystem
 * - Cloud storage (S3, GCS, Azure Blob)
 * - Multipart upload systems
 * - In-memory buffers (testing only)
 * 
 * All implementations MUST:
 * - Throw WriteFailureException on any write error
 * - Never silently drop or truncate data
 * - Support append semantics (sequential writes)
 * - Track total bytes written accurately
 */
interface WritableStreamInterface
{
    /**
     * Write data to the stream.
     * 
     * @param string $data Binary data to write
     * @return int Number of bytes written (must equal strlen($data))
     * @throws \BatchZipStream\Exceptions\WriteFailureException On any write error
     */
    public function write(string $data): int;

    /**
     * Get total bytes written to this stream.
     * 
     * @return int Total bytes written since stream creation/open
     */
    public function getBytesWritten(): int;

    /**
     * Flush any buffered data to the underlying storage.
     * 
     * @throws \BatchZipStream\Exceptions\WriteFailureException On flush failure
     */
    public function flush(): void;

    /**
     * Close the stream.
     * Subsequent writes MUST throw.
     * 
     * @throws \BatchZipStream\Exceptions\WriteFailureException On close failure
     */
    public function close(): void;

    /**
     * Finalize the stream (for these APIs that require completion steps).
     * 
     * @throws \BatchZipStream\Exceptions\WriteFailureException On finalize failure // TODO: the exception should be replaced with a more specific exception
     */
    public function finalize(): void;

    /**
     * Abort the stream, discarding any partial data.
     * 
     * @throws \BatchZipStream\Exceptions\WriteFailureException On abort failure // TODO: the exception should be replaced with a more specific exception
     */
    public function abort(): void;

    /**
     * Check if the stream is open and writable.
     * 
     * @return bool True if writes are possible
     */
    public function isOpen(): bool;

    /**
     * Get the current write position (offset from start).
     * 
     * @return int Current position in bytes
     */
    public function getPosition(): int;
}
