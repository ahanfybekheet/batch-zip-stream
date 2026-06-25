<?php

declare(strict_types=1);

namespace BatchZipStream;

use BatchZipStream\Contracts\WritableStreamInterface;
use BatchZipStream\Contracts\ReadableStreamInterface;
use BatchZipStream\State\ArchiveState;
use BatchZipStream\State\FileEntry;
use BatchZipStream\State\FileEntryStore;
use BatchZipStream\Core\ZipFormat;
use BatchZipStream\Core\StreamingCompressor;
use BatchZipStream\Exceptions\BatchZipStreamException;
use BatchZipStream\Exceptions\WriteFailureException;
use BatchZipStream\Exceptions\InvalidOperationException;
use BatchZipStream\Exceptions\CompressionException;

/**
 * Production-grade Batch ZIP Streaming Engine - Memory-Efficient.
 * 
 * This is the memory-efficient version of BatchZipWriter that can handle
 * archives with millions of files without memory exhaustion.
 * 
 * ## Design Principles
 * 
 * 1. **No In-Memory State Between Batches**: All state is persisted externally.
 *    The writer object MUST NOT be serialized.
 * 
 * 2. **Streaming I/O**: Files are read and compressed in chunks. No file is
 *    ever fully loaded into memory.
 * 
 * 3. **Explicit Failure**: Any error throws an exception. No silent failures.
 *    A failed write invalidates the entire archive.
 * 
 * 4. **Storage Agnostic**: Output is written to an abstract stream interface.
 *    Implementations can target local disk, cloud storage, or multipart uploads.
 * 
 * 5. **ZIP64 Support**: Full support for archives >4GB and >65535 files.
 * 
 * ## Memory Characteristics
 * 
 * - State JSON: ~500 bytes (constant)
 * - Entry store: ~200 bytes per file (on disk, not in memory)
 * - Memory per file: O(1) - only current file in memory
 * - CDR writing: O(1) - entries streamed via generator
 * 
 * @package BatchZipStream
 */
class BatchZipWriter
{
    private WritableStreamInterface $stream;
    private ArchiveState $state;
    private FileEntryStore $entryStore;
    /** @var int Chunk size for reading files */
    private int $chunkSize;

    /** @var string|null Global password for encryption */
    private ?string $globalPassword;

    /**
     * Create a new Batch ZIP Writer.
     * 
     * @param WritableStreamInterface $stream Output stream (must support append)
     * @param ArchiveState $state Archive state (new or restored from persistence)
     * @param FileEntryStore $entryStore File entry store (new or restored)
     * @param int $compressionLevel Deflate compression level (0-9, default 6)
     * @param int $chunkSize Chunk size for file reading (default 64KB)
     * @param string|null $password Global password for encryption
     */
    public function __construct(
        WritableStreamInterface $stream,
        ArchiveState $state,
        FileEntryStore $entryStore,
        int $compressionLevel = 6,
        int $chunkSize = StreamingCompressor::DEFAULT_CHUNK_SIZE,
        ?string $password = null
    ) {
        $this->stream = $stream;
        $this->state = $state;
        $this->entryStore = $entryStore;
        $this->compressionLevel = $compressionLevel;
        $this->chunkSize = $chunkSize;
        $this->globalPassword = $password;

        // Verify stream position matches state
        $this->validateStreamPosition();
    }

    /**
     * Add a file to the archive from a readable stream.
     * 
     * Uses streaming with data descriptor to avoid buffering the entire file
     * in memory. The CRC32 and sizes are written AFTER the file data in a
     * data descriptor record.
     * 
     * @param string $filename Path in the ZIP archive (will be sanitized)
     * @param ReadableStreamInterface $source Source stream to read from
     * @param int $compressionMethod Compression method (DEFLATE or STORE)
     * @param int|null $modificationTime Unix timestamp (default: current time)
     * @param int $encryptionMethod Encryption method (default ENC_NONE)
     * @param string|null $password Password for this specific file (overrides global)
     * @throws InvalidOperationException If archive cannot accept files
     * @throws WriteFailureException On write failure
     * @throws CompressionException On compression failure
     */
    public function addFile(
        string $filename,
        ReadableStreamInterface $source,
        int $compressionMethod = ZipFormat::COMPRESSION_DEFLATE,
        ?int $modificationTime = null,
        int $encryptionMethod = ZipFormat::ENC_NONE,
        ?string $password = null
    ): void {
        // Validate state
        $this->assertCanAddFiles();

        // Sanitize filename
        $sanitizedFilename = ZipFormat::sanitizeFilename($filename);
        if (empty($sanitizedFilename)) {
            throw new InvalidOperationException('Filename cannot be empty after sanitization');
        }

        $modificationTime ??= time();
        $dosTime = ZipFormat::toDosTime($modificationTime);

        // Get current offset for this file's local header
        $localHeaderOffset = $this->state->getFullOffset();
        $localHeaderOffsetHigh = $this->state->getCurrentOffsetHigh();

        // Determine if we need ZIP64 for the local header offset
        $needsZip64ForOffset = $localHeaderOffset > ZipFormat::ZIP64_THRESHOLD
            || $localHeaderOffsetHigh > 0;

        $filePassword = $password ?? $this->globalPassword;
        if ($encryptionMethod !== ZipFormat::ENC_NONE && $filePassword === null) {
            throw new InvalidOperationException('Password is required for encrypted files');
        }

        $cryptoEngine = null;
        if ($encryptionMethod === ZipFormat::ENC_TRADITIONAL) {
            $cryptoEngine = new \BatchZipStream\Core\Crypto\TraditionalZipCrypto($filePassword, $dosTime);
        } elseif ($encryptionMethod === ZipFormat::ENC_AES_256) {
            $cryptoEngine = new \BatchZipStream\Core\Crypto\WinZipAesCrypto($filePassword);
        }

        try {
            // Write local file header with data descriptor flag (CRC/sizes = 0)
            // This allows us to stream data without knowing sizes upfront
            $localHeader = ZipFormat::buildLocalFileHeaderWithDataDescriptor(
                $sanitizedFilename,
                $compressionMethod,
                $dosTime,
                $needsZip64ForOffset,
                $encryptionMethod
            );
            $this->writeToStream($localHeader);

            $compressor = new StreamingCompressor($this->chunkSize, $this->compressionLevel, $cryptoEngine);

            // Stream compress the file, writing chunks directly to output
            // No buffering - each compressed chunk goes straight to the stream
            if ($compressionMethod === ZipFormat::COMPRESSION_DEFLATE) {
                $result = $compressor->compressStream(
                    $source,
                    function (string $chunk): void {
                        $this->writeToStream($chunk);
                    }
                );
                $crc32 = $result['crc32'];
                $compressedSize = $result['compressedSize'];
                $uncompressedSize = $result['uncompressedSize'];
            } else {
                // Store method - no compression
                $result = $compressor->storeStream(
                    $source,
                    function (string $chunk): void {
                        $this->writeToStream($chunk);
                    }
                );
                $crc32 = $result['crc32'];
                $compressedSize = $result['size'];
                $uncompressedSize = $result['size'];
            }

            // Determine if this entry needs ZIP64 based on actual sizes
            $needsZip64 = $needsZip64ForOffset
                || $compressedSize > ZipFormat::ZIP64_THRESHOLD
                || $uncompressedSize > ZipFormat::ZIP64_THRESHOLD;

            // Write data descriptor with actual CRC32 and sizes
            $dataDescriptor = ZipFormat::buildDataDescriptor(
                $crc32,
                $compressedSize,
                $uncompressedSize,
                $needsZip64
            );
            $this->writeToStream($dataDescriptor);

            // Build bit flags (with data descriptor flag set for entry metadata)
            $isEncrypted = $encryptionMethod !== ZipFormat::ENC_NONE;
            $bitFlags = ZipFormat::getGeneralPurposeBitFlag($sanitizedFilename, true, $isEncrypted);

            // Version needed
            $versionNeeded = $needsZip64
                ? ZipFormat::VERSION_NEEDED_ZIP64
                : ZipFormat::getVersionNeeded($compressionMethod, false);

            // Build local extra field for ZIP64 if needed (for CDR entry)
            $localExtraField = '';
            if ($needsZip64) {
                $localExtraField = ZipFormat::buildZip64LocalExtraField(
                    $uncompressedSize,
                    $compressedSize
                );
            }

            // Create file entry with all metadata for Central Directory
            $entry = new FileEntry(
                $sanitizedFilename,
                $crc32,
                $compressedSize,
                $uncompressedSize,
                $localHeaderOffset,
                $compressionMethod,
                $dosTime,
                $bitFlags,
                $versionNeeded,
                $needsZip64,
                $localHeaderOffsetHigh,
                $localExtraField,
                '',
                false,
                $encryptionMethod
            );

            // Update state (small, stays in memory)
            $bytesWritten = strlen($localHeader) + $compressedSize + strlen($dataDescriptor);
            $this->state->updateOffset($bytesWritten);
            $this->state->incrementFileCount();

            if ($needsZip64) {
                $this->state->markZip64Required();
            }

            // Append entry to store (writes to disk immediately)
            $this->entryStore->append($entry);

        } catch (BatchZipStreamException $e) {
            // Mark archive as failed
            $this->state->fail($e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            // Mark archive as failed
            $this->state->fail($e->getMessage());
            throw new CompressionException(
                'Failed to add file: ' . $e->getMessage(),
                $sanitizedFilename,
                $compressionMethod,
                $e
            );
        } finally {
            // Always close the source stream
            try {
                $source->close();
            } catch (\Throwable $e) {
                // Log but don't throw
            }
        }
    }

    /**
     * Add a file from raw data (for small files only).
     * 
     * WARNING: This loads the entire file into memory.
     * 
     * @param string $filename Path in the ZIP archive
     * @param string $data File content
     * @param int $compressionMethod Compression method
     * @param int|null $modificationTime Unix timestamp
     * @param int $encryptionMethod Encryption method (default ENC_NONE)
     * @param string|null $password Password for this specific file (overrides global)
     */
    public function addFileFromString(
        string $filename,
        string $data,
        int $compressionMethod = ZipFormat::COMPRESSION_DEFLATE,
        ?int $modificationTime = null,
        int $encryptionMethod = ZipFormat::ENC_NONE,
        ?string $password = null
    ): void {
        $stream = new Streams\StringReadableStream($data, $filename);
        $this->addFile($filename, $stream, $compressionMethod, $modificationTime, $encryptionMethod, $password);
    }

    /**
     * Add an empty directory to the archive.
     * 
     * Directories in ZIP archives are stored as entries with:
     * - Trailing slash in the filename
     * - Zero compressed/uncompressed size
     * - CRC32 of 0
     * - STORE compression method (no compression needed for empty content)
     * 
     * @param string $dirname Directory path in the archive (trailing slash added if missing)
     * @param int|null $modificationTime Unix timestamp (default: current time)
     * @throws InvalidOperationException If archive cannot accept files
     * @throws WriteFailureException On write failure
     */
    public function addEmptyDirectory(
        string $dirname,
        ?int $modificationTime = null
    ): void {
        // Validate state
        $this->assertCanAddFiles();

        // Sanitize and ensure trailing slash
        $sanitizedDirname = ZipFormat::sanitizeFilename($dirname);
        if (empty($sanitizedDirname)) {
            throw new InvalidOperationException('Directory name cannot be empty after sanitization');
        }

        // Ensure trailing slash for directory entries
        if (substr($sanitizedDirname, -1) !== '/') {
            $sanitizedDirname .= '/';
        }

        $modificationTime ??= time();
        $dosTime = ZipFormat::toDosTime($modificationTime);

        // Get current offset for this directory's local header
        $localHeaderOffset = $this->state->getFullOffset();
        $localHeaderOffsetHigh = $this->state->getCurrentOffsetHigh();

        // Determine if we need ZIP64 for the local header offset
        $needsZip64ForOffset = $localHeaderOffset > ZipFormat::ZIP64_THRESHOLD
            || $localHeaderOffsetHigh > 0;

        try {
            // Directory entries use STORE compression (no data to compress)
            $compressionMethod = ZipFormat::COMPRESSION_STORE;

            // Build bit flags (UTF-8 flag, no data descriptor needed for empty content)
            $bitFlags = ZipFormat::getGeneralPurposeBitFlag($sanitizedDirname, false);

            // Version needed
            $versionNeeded = $needsZip64ForOffset
                ? ZipFormat::VERSION_NEEDED_ZIP64
                : ZipFormat::VERSION_NEEDED_STORE;

            // Empty directory: CRC32 = 0, sizes = 0
            $crc32 = 0;
            $compressedSize = 0;
            $uncompressedSize = 0;

            // Build extra field for ZIP64 if needed
            $localExtraField = '';
            if ($needsZip64ForOffset) {
                $localExtraField = ZipFormat::buildZip64LocalExtraField(0, 0);
            }

            // Build and write local file header (without data descriptor)
            $localHeader = ZipFormat::buildLocalFileHeaderDirect(
                $sanitizedDirname,
                $compressionMethod,
                $dosTime,
                $crc32,
                $compressedSize,
                $uncompressedSize,
                $bitFlags,
                $versionNeeded,
                $localExtraField
            );
            $this->writeToStream($localHeader);

            // No file data to write for empty directory

            // Create file entry with directory flag for Central Directory
            $entry = new FileEntry(
                $sanitizedDirname,
                $crc32,
                $compressedSize,
                $uncompressedSize,
                $localHeaderOffset,
                $compressionMethod,
                $dosTime,
                $bitFlags,
                $versionNeeded,
                $needsZip64ForOffset,
                $localHeaderOffsetHigh,
                $localExtraField,
                '',
                true
            );

            // Update state
            $bytesWritten = strlen($localHeader);
            $this->state->updateOffset($bytesWritten);
            $this->state->incrementFileCount();

            if ($needsZip64ForOffset) {
                $this->state->markZip64Required();
            }

            // Append entry to store
            $this->entryStore->append($entry);

        } catch (BatchZipStreamException $e) {
            $this->state->fail($e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->state->fail($e->getMessage());
            throw new WriteFailureException(
                'Failed to add empty directory: ' . $e->getMessage(),
                $this->state->getFullOffset(),
                0,
                $this->stream->getPosition(),
                $e
            );
        }
    }

    /**
     * Finalize the archive by writing the Central Directory and EOCD.
     * 
     * Uses streaming iteration to avoid loading all entries into memory.
     * 
     * @param string $comment Optional archive comment
     * @throws InvalidOperationException If archive cannot be finalized
     * @throws WriteFailureException On write failure
     */
    public function finalize(string $comment = ''): void
    {
        // Validate can finalize
        if (!$this->state->canFinalize()) {
            throw new InvalidOperationException(
                'Cannot finalize archive in current state',
                $this->state->getPhase(),
                'finalize'
            );
        }

        // Transition to finalizing phase
        $this->state->startFinalization();

        try {
            // Record CDR start offset
            $cdrOffset = $this->state->getFullOffset();

            // Write Central Directory headers by streaming through entry store
            $cdrSize = 0;
            foreach ($this->entryStore->iterate() as $entry) {
                $header = ZipFormat::buildCentralDirectoryHeader($entry);
                $this->writeToStream($header);
                $cdrSize += strlen($header);
            }

            // Get file count
            $fileCount = $this->state->getFileCount();

            // Write ZIP64 structures if needed
            if ($this->state->requiresZip64()) {
                // Record ZIP64 EOCD offset (after CDR)
                $zip64EocdOffset = $cdrOffset + $cdrSize;

                // ZIP64 End of Central Directory Record
                $zip64Eocd = ZipFormat::buildZip64EndOfCentralDirectory(
                    $fileCount,
                    $cdrSize,
                    $cdrOffset
                );
                $this->writeToStream($zip64Eocd);

                // ZIP64 End of Central Directory Locator
                $zip64Locator = ZipFormat::buildZip64EndOfCentralDirectoryLocator($zip64EocdOffset);
                $this->writeToStream($zip64Locator);
            }

            // End of Central Directory Record (always written)
            $eocd = ZipFormat::buildEndOfCentralDirectory(
                $fileCount,
                $cdrSize,
                $cdrOffset,
                $comment
            );
            $this->writeToStream($eocd);

            // Flush the stream
            $this->stream->flush();

            // Mark as completed
            $this->state->complete();

        } catch (BatchZipStreamException $e) {
            $this->state->fail($e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->state->fail($e->getMessage());
            throw new WriteFailureException(
                'Failed to finalize archive: ' . $e->getMessage(),
                0,
                0,
                $this->stream->getPosition(),
                $e
            );
        }
    }

    /**
     * Get the current archive state.
     * 
     * @return ArchiveState Current state (for persistence)
     */
    public function getState(): ArchiveState
    {
        return $this->state;
    }

    /**
     * Get the entry store.
     * 
     * @return FileEntryStore
     */
    public function getEntryStore(): FileEntryStore
    {
        return $this->entryStore;
    }

    /**
     * Get the current position in the output stream.
     * 
     * @return int Bytes written so far
     */
    public function getPosition(): int
    {
        return $this->stream->getPosition();
    }

    /**
     * Check if the archive can accept more files.
     * 
     * @return bool True if more files can be added
     */
    public function canAddFiles(): bool
    {
        return $this->state->canAddFiles();
    }

    /**
     * Check if the archive is ready for finalization.
     * 
     * @return bool True if archive can be finalized
     */
    public function canFinalize(): bool
    {
        return $this->state->canFinalize();
    }

    /**
     * Close all resources.
     */
    public function close(): void
    {
        if ($this->stream->isOpen()) {
            $this->stream->close();
        }
        $this->entryStore->close();
    }

    // ==================== Private Methods ====================

    /**
     * Assert that files can be added to the archive.
     * 
     * @throws InvalidOperationException If files cannot be added
     */
    private function assertCanAddFiles(): void
    {
        if (!$this->state->canAddFiles()) {
            throw new InvalidOperationException(
                sprintf('Cannot add files in phase "%s"', $this->state->getPhase()),
                $this->state->getPhase(),
                'addFile'
            );
        }
    }

    /**
     * Write data to the output stream.
     */
    private function writeToStream(string $data): void
    {
        $length = strlen($data);
        $written = $this->stream->write($data);

        if ($written !== $length) {
            throw new WriteFailureException(
                sprintf('Write failed: expected %d bytes, wrote %d', $length, $written),
                $length,
                $written,
                $this->stream->getPosition()
            );
        }
    }

    /**
     * Validate that stream position matches state.
     * 
     * This is a safety check for resuming batches.
     */
    private function validateStreamPosition(): void
    {
        $streamPosition = $this->stream->getPosition();
        $stateOffset = $this->state->getFullOffset();

        // For new archives, both should be 0
        // For resumed archives, stream should be at or beyond state offset
        if ($stateOffset > 0 && $streamPosition < $stateOffset) {
            throw new InvalidOperationException(
                sprintf(
                    'Stream position (%d) is less than state offset (%d). ' .
                    'The stream may not be properly positioned for resuming.',
                    $streamPosition,
                    $stateOffset
                ),
                $this->state->getPhase(),
                'construct'
            );
        }
    }
}
