<?php

declare(strict_types=1);

namespace BatchZipStream;

use BatchZipStream\Contracts\WritableStreamInterface;
use BatchZipStream\Contracts\ReadableStreamInterface;
use BatchZipStream\Contracts\StatePersistenceInterface;
use BatchZipStream\State\ArchiveState;
use BatchZipStream\State\FileEntryStore;
use BatchZipStream\Persistence\FileStatePersistence;
use BatchZipStream\Streams\FileWritableStream;
use BatchZipStream\Streams\FileReadableStream;
use BatchZipStream\Core\ZipFormat;
use BatchZipStream\Exceptions\StatePersistenceException;
use BatchZipStream\Exceptions\InvalidOperationException;
use BatchZipStream\Exceptions\BatchZipStreamException;

/**
 * High-level session manager for batch ZIP creation - Memory-Efficient.
 * 
 * This class provides a convenient API for managing the complete lifecycle
 * of a batch ZIP operation, including:
 * - Session creation and resumption
 * - State persistence
 * - Locking for concurrent access safety
 * - Cleanup after completion
 * 
 * ## Stream Support
 * 
 * This class supports both file-based and custom stream implementations:
 * - Use constructor for file-based output (backward compatible)
 * - Use withStreamFactory() for custom streams (cloud storage, etc.)
 * - Use withStream() for pre-created stream instances
 * 
 * ## Memory Characteristics
 * 
 * - Handles archives with millions of files
 * - Memory usage stays constant (~1MB regardless of file count)
 * - State JSON stays tiny (~500 bytes)
 * - File entries stored on disk (never loaded into memory)
 * 
 * ## Usage Example
 * 
 * ```php
 * $manager = new BatchZipSession('/tmp/zip-states', '/output/archive.zip');
 * 
 * // Start or resume session
 * $sessionId = $manager->startSession('my-archive');
 * 
 * // Get writer and add files
 * $writer = $manager->getWriter();
 * $writer->addFile('file.txt', $stream);
 * 
 * // Save progress
 * $manager->saveProgress();
 * 
 * // In a later batch, finalize
 * $manager->finalize();
 * ```
 * 
 * @package BatchZipStream
 */
final class BatchZipSession
{
    private StatePersistenceInterface $persistence;
    private ?WritableStreamInterface $stream = null;
    private ?ArchiveState $state = null;
    private ?BatchZipWriter $writer = null;
    private ?FileEntryStore $entryStore = null;
    private ?string $sessionId = null;
    private ?string $outputPath = null;
    private bool $lockAcquired = false;

    /** @var callable(bool): WritableStreamInterface|null */
    private $streamFactory = null;

    /** @var int Compression level (0-9) */
    private int $compressionLevel;

    /** @var int Chunk size for reading files */
    private int $chunkSize;

    /** @var string|null Global password for encryption */
    private ?string $globalPassword;

    /**
     * Create a batch ZIP session manager with file-based persistence.
     * 
     * @param string $stateDirectory Directory for state persistence
     * @param string $outputPath Path to the output ZIP file
     * @param int $compressionLevel Deflate compression level (0-9)
     * @param int $chunkSize Chunk size for file reading
     * @param StatePersistenceInterface|null $persistence Custom persistence (optional, defaults to file-based)
     * @param string|null $password Global password for encryption
     */
    public function __construct(
        string $stateDirectory,
        string $outputPath,
        int $compressionLevel = 6,
        int $chunkSize = 65536,
        ?StatePersistenceInterface $persistence = null,
        ?string $password = null
    ) {
        $this->persistence = $persistence ?? new FileStatePersistence($stateDirectory);
        $this->outputPath = $outputPath;
        $this->compressionLevel = $compressionLevel;
        $this->chunkSize = $chunkSize;
        $this->globalPassword = $password;
    }

    /**
     * Create a session with a custom persistence implementation.
     *
     * Use this to provide alternative backends like DB, Redis, or cloud storage.
     *
     * @param StatePersistenceInterface $persistence Custom persistence implementation
     * @param string $outputPath Path to the output ZIP file
     * @param int $compressionLevel Deflate compression level (0-9)
     * @param int $chunkSize Chunk size for file reading
     * @param string|null $password Global password for encryption
     * @return self
     */
    public static function withPersistence(
        StatePersistenceInterface $persistence,
        string $outputPath,
        int $compressionLevel = 6,
        int $chunkSize = 65536,
        ?string $password = null
    ): self {
        // Pass empty string for stateDirectory since we're providing a custom persistence
        $instance = new self('', $outputPath, $compressionLevel, $chunkSize, $persistence, $password);
        return $instance;
    }

    /**
     * Create a session with a custom stream factory.
     *
     * The factory receives a boolean indicating whether to append (resume)
     * or create new (start fresh).
     *
     * @param string|StatePersistenceInterface $stateDirectoryOrPersistence Directory for session state files OR custom persistence
     * @param callable(bool): WritableStreamInterface $streamFactory
     * @param int $compressionLevel Deflate compression level (0-9)
     * @param int $chunkSize Chunk size for file reading
     * @param string|null $password Global password for encryption
     * @return self
     */
    public static function withStreamFactory(
        $stateDirectoryOrPersistence,
        callable $streamFactory,
        int $compressionLevel = 6,
        int $chunkSize = 65536,
        ?string $password = null
    ): self {
        if ($stateDirectoryOrPersistence instanceof StatePersistenceInterface) {
            $instance = new self('', '', $compressionLevel, $chunkSize, $stateDirectoryOrPersistence, $password);
        } else {
            $instance = new self($stateDirectoryOrPersistence, '', $compressionLevel, $chunkSize, null, $password);
        }
        $instance->outputPath = null;
        $instance->streamFactory = $streamFactory;
        return $instance;
    }

    /**
     * Create a session with a pre-created stream instance.
     *
     * Note: The same stream is used for both new and resumed sessions.
     * Ensure the stream is properly positioned for appending if resuming.
     *
     * @param string|StatePersistenceInterface $stateDirectoryOrPersistence Directory for session state files OR custom persistence
     * @param WritableStreamInterface $stream The writable stream to use
     * @param int $compressionLevel Deflate compression level (0-9)
     * @param int $chunkSize Chunk size for file reading
     * @param string|null $password Global password for encryption
     * @return self
     */
    public static function withStream(
        $stateDirectoryOrPersistence,
        WritableStreamInterface $stream,
        int $compressionLevel = 6,
        int $chunkSize = 65536,
        ?string $password = null
    ): self {
        if ($stateDirectoryOrPersistence instanceof StatePersistenceInterface) {
            $instance = new self('', '', $compressionLevel, $chunkSize, $stateDirectoryOrPersistence, $password);
        } else {
            $instance = new self($stateDirectoryOrPersistence, '', $compressionLevel, $chunkSize, null, $password);
        }
        $instance->outputPath = null;
        $instance->streamFactory = fn(bool $append) => $stream;
        return $instance;
    }

    /**
     * Start a new session or resume an existing one.
     *
     * @param string $sessionId Unique session identifier
     * @param int $lockTimeout Maximum time to wait for lock (seconds)
     * @return string Session ID
     * @throws StatePersistenceException On state errors
     * @throws InvalidOperationException If session cannot be started
     */
    public function startSession(string $sessionId, int $lockTimeout = 30): string
    {
        $this->sessionId = $sessionId;

        // Acquire lock
        if (!$this->persistence->acquireLock($sessionId, $lockTimeout)) {
            throw new InvalidOperationException(
                sprintf('Failed to acquire lock for session: %s', $sessionId),
                'locked',
                'startSession'
            );
        }
        $this->lockAcquired = true;

        try {
            // Try to load existing session
            $session = $this->persistence->load($sessionId);

            if ($session !== null) {
                // Resume existing session
                $this->state = $session['state'];
                $this->entryStore = $session['entryStore'];

                // Verify session integrity
                if ($this->state->isFailed()) {
                    throw new InvalidOperationException(
                        sprintf('Session is in failed state: %s', $this->state->getFailureReason()),
                        $this->state->getPhase(),
                        'startSession'
                    );
                }

                if ($this->state->isCompleted()) {
                    throw new InvalidOperationException(
                        'Session is already completed',
                        $this->state->getPhase(),
                        'startSession'
                    );
                }

                // Open stream in append mode
                $this->stream = $this->createStream(true);

            } else {
                // Create new session
                $session = $this->persistence->create($sessionId);
                $this->state = $session['state'];
                $this->entryStore = $session['entryStore'];

                // Create new stream
                $this->stream = $this->createStream(false);
            }

            // Initialize writer
            $this->writer = new BatchZipWriter(
                $this->stream,
                $this->state,
                $this->entryStore,
                $this->compressionLevel,
                $this->chunkSize,
                $this->globalPassword
            );

            return $sessionId;

        } catch (\Throwable $e) {
            $this->releaseLock();
            throw $e;
        }
    }

    /**
     * Add a file to the archive.
     * 
     * Convenience method that wraps writer->addFile().
     * 
     * @param string $archivePath Path in the archive
     * @param string $sourcePath Path to the source file
     * @param int $compressionMethod Compression method
     * @param int $encryptionMethod Encryption method (default ENC_NONE)
     * @param string|null $password Password for this file (overrides global)
     * @return self For chaining
     */
    public function addFile(
        string $archivePath,
        string $sourcePath,
        int $compressionMethod = ZipFormat::COMPRESSION_DEFLATE,
        int $encryptionMethod = ZipFormat::ENC_NONE,
        ?string $password = null
    ): self {
        $this->ensureWriterOpen();

        $source = new FileReadableStream($sourcePath);
        $modTime = @filemtime($sourcePath) ?: time();

        $this->writer->addFile($archivePath, $source, $compressionMethod, $modTime, $encryptionMethod, $password);

        return $this;
    }

    /**
     * Add a file from string content.
     *
     * @param string $archivePath Path in the archive
     * @param string $content File content
     * @param int $compressionMethod Compression method
     * @param int $encryptionMethod Encryption method (default ENC_NONE)
     * @param string|null $password Password for this file (overrides global)
     * @return self For chaining
     */
    public function addFileFromString(
        string $archivePath,
        string $content,
        int $compressionMethod = ZipFormat::COMPRESSION_DEFLATE,
        int $encryptionMethod = ZipFormat::ENC_NONE,
        ?string $password = null
    ): self {
        $this->ensureWriterOpen();

        $this->writer->addFileFromString($archivePath, $content, $compressionMethod, null, $encryptionMethod, $password);

        return $this;
    }

    /**
     * Add a file from a readable stream.
     *
     * This is the most flexible method - works with any ReadableStreamInterface
     * implementation (files, cloud storage, HTTP streams, etc.).
     *
     * @param string $archivePath Path in the archive
     * @param ReadableStreamInterface $source Source stream to read from
     * @param int $compressionMethod Compression method
     * @param int|null $modificationTime Unix timestamp (default: current time)
     * @return self For chaining
     */
    public function addFileFromStream(
        string $archivePath,
        ReadableStreamInterface $source,
        int $compressionMethod = ZipFormat::COMPRESSION_DEFLATE,
        ?int $modificationTime = null
    ): self {
        $this->ensureWriterOpen();

        $this->writer->addFile($archivePath, $source, $compressionMethod, $modificationTime);

        return $this;
    }

    /**
     * Add an empty directory to the archive.
     *
     * This creates a directory entry in the ZIP archive. Directory entries
     * are stored with a trailing slash and zero content.
     *
     * @param string $archivePath Directory path in the archive (trailing slash added if missing)
     * @param int|null $modificationTime Unix timestamp (default: current time)
     * @return self For chaining
     */
    public function addEmptyDirectory(
        string $archivePath,
        ?int $modificationTime = null
    ): self {
        $this->ensureWriterOpen();

        $modificationTime ??= time();

        $this->writer->addEmptyDirectory($archivePath, $modificationTime);

        return $this;
    }

    /**
     * Save current progress.
     * 
     * Call this periodically and at the end of each batch.
     * 
     * @throws BatchZipStreamException On save failure
     */
    public function saveProgress(): void
    {
        if ($this->state === null || $this->sessionId === null) {
            throw new InvalidOperationException(
                'Session not started',
                'not_started',
                'saveProgress'
            );
        }

        // Entry store is saved incrementally, just flush it
        if ($this->entryStore !== null) {
            $this->entryStore->flush();
        }

        // Flush the stream
        if ($this->stream !== null && $this->stream->isOpen()) {
            $this->stream->flush();
        }

        // Save state
        $this->persistence->save($this->sessionId, $this->state);
    }

    /**
     * Finalize the archive and complete the session.
     * 
     * Writes the Central Directory and EOCD.
     * 
     * @param string $comment Optional archive comment
     * @throws BatchZipStreamException On finalization failure
     */
    public function finalize(string $comment = ''): void
    {
        if ($this->writer === null) {
            throw new InvalidOperationException(
                'Session not started',
                'not_started',
                'finalize'
            );
        }

        // Finalize the archive
        $this->writer->finalize($comment);

        // Finalize the stream
        $this->stream->finalize();

        // Delete state (archive is complete)
        if ($this->sessionId !== null) {
            $this->persistence->delete($this->sessionId);
        }

        // Release lock
        $this->releaseLock();

        // Clear references
        $this->writer = null;
        $this->stream = null;
    }

    /**
     * Clean up session files.
     * 
     * Call after successful finalization to remove state files.
     */
    public function cleanup(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        // Close resources
        $this->close();

        // Delete session files
        $this->persistence->delete($this->sessionId);
    }

    /**
     * Abort the session and clean up.
     * 
     * Marks the session as failed and optionally deletes the partial archive.
     * 
     * @param string $reason Failure reason
     * @param bool $deleteArchive Whether to delete the partial archive
     */
    public function abort(string $reason = 'Aborted by user', bool $deleteArchive = true): void
    {
        // Mark state as failed if exists
        if ($this->state !== null) {
            $this->state->fail($reason);
        }

        // Close stream
        if ($this->stream !== null && $this->stream->isOpen()) {
            try {
                $this->stream->close();
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // Delete state
        if ($this->sessionId !== null) {
            try {
                $this->persistence->delete($this->sessionId);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // Release lock
        $this->releaseLock();

        // Delete partial output file
        $this->stream->abort();

        $this->writer = null;
        $this->stream = null;
        $this->state = null;
    }

    /**
     * Close all resources without cleanup.
     * 
     * Use this to end a batch without deleting state.
     */
    public function close(): void
    {
        if ($this->writer !== null) {
            $this->writer->close();
            $this->writer = null;
        }

        if ($this->entryStore !== null) {
            $this->entryStore->close();
            $this->entryStore = null;
        }

        if ($this->stream !== null && $this->stream->isOpen()) {
            try {
                $this->stream->close();
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        $this->releaseLock();
    }

    /**
     * Get current session statistics.
     * 
     * @return array{
     *   sessionId: string,
     *   phase: string,
     *   fileCount: int,
     *   bytesWritten: int,
     *   requiresZip64: bool,
     *   stateSize: array,
     *   createdAt: int,
     *   updatedAt: int
     * }|null
     */
    public function getStats(): ?array
    {
        if ($this->sessionId === null || $this->state === null) {
            return null;
        }

        $storageStats = $this->persistence->getStorageStats($this->sessionId);

        return [
            'sessionId' => $this->sessionId,
            'phase' => $this->state->getPhase(),
            'fileCount' => $this->state->getFileCount(),
            'bytesWritten' => $this->state->getFullOffset(),
            'requiresZip64' => $this->state->requiresZip64(),
            'stateSize' => $storageStats,
            'createdAt' => $this->state->getCreatedAt(),
            'updatedAt' => $this->state->getUpdatedAt(),
        ];
    }

    /**
     * Check if a session exists.
     * 
     * @param string $sessionId Session identifier
     * @return bool
     */
    public function exists(string $sessionId): bool
    {
        return $this->persistence->exists($sessionId);
    }

    /**
     * Get the ZIP writer.
     * 
     * @return BatchZipWriter The writer instance
     * @throws InvalidOperationException If session not started
     */
    public function getWriter(): BatchZipWriter
    {
        if ($this->writer === null) {
            throw new InvalidOperationException(
                'Session not started. Call startSession() first.',
                'not_started',
                'getWriter'
            );
        }

        return $this->writer;
    }

    /**
     * Get the current state.
     * 
     * @return ArchiveState|null Current state
     */
    public function getState(): ?ArchiveState
    {
        return $this->state;
    }

    /**
     * Get the output file path.
     *
     * @return string|null Null if using a custom stream factory
     */
    public function getOutputPath(): ?string
    {
        return $this->outputPath;
    }

    /**
     * Get the current output stream.
     *
     * @return WritableStreamInterface|null
     */
    public function getStream(): ?WritableStreamInterface
    {
        return $this->stream;
    }

    /**
     * Clean up old/failed sessions.
     * 
     * @param int $maxAgeSeconds Maximum age for stale sessions
     * @return int Number of sessions cleaned
     */
    public function cleanupOldSessions(int $maxAgeSeconds = 86400): int
    {
        return $this->persistence->cleanup($maxAgeSeconds);
    }

    /**
     * List all active sessions.
     * 
     * @return string[] Session IDs
     */
    public function listSessions(): array
    {
        return $this->persistence->listSessions();
    }

    // ==================== Private Methods ====================

    /**
     * Create the output stream using factory or file path.
     *
     * @param bool $append Whether to open in append mode
     * @return WritableStreamInterface
     */
    private function createStream(bool $append): WritableStreamInterface
    {
        if ($this->streamFactory !== null) {
            return ($this->streamFactory)($append);
        }

        if ($this->outputPath === null || $this->outputPath === '') {
            throw new InvalidOperationException(
                'No stream factory or output path configured',
                'misconfigured',
                'createStream'
            );
        }

        return new FileWritableStream($this->outputPath, $append);
    }

    /**
     * Ensure the writer is open.
     *
     * @throws InvalidOperationException If writer is not open
     */
    private function ensureWriterOpen(): void
    {
        if ($this->writer === null) {
            throw new InvalidOperationException(
                'Session not started. Call startSession() first.',
                'uninitialized',
                'ensureWriterOpen'
            );
        }
    }

    /**
     * Release the lock if held.
     */
    private function releaseLock(): void
    {
        if ($this->lockAcquired && $this->sessionId !== null) {
            $this->persistence->releaseLock($this->sessionId);
            $this->lockAcquired = false;
        }
    }

    /**
     * Destructor - ensure resources are released.
     */
    public function __destruct()
    {
        // Save progress if not finalized
        if ($this->state !== null && $this->sessionId !== null && !$this->state->isCompleted()) {
            try {
                $this->saveProgress();
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // Close stream
        if ($this->stream !== null && $this->stream->isOpen()) {
            try {
                $this->stream->close();
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // Release lock
        $this->releaseLock();
    }
}
