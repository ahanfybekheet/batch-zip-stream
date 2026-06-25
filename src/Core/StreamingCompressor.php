<?php

declare(strict_types=1);

namespace BatchZipStream\Core;

use BatchZipStream\Contracts\ReadableStreamInterface;
use BatchZipStream\Exceptions\CompressionException;
use BatchZipStream\Exceptions\ReadFailureException;
use BatchZipStream\Core\Crypto\CryptoEngineInterface;

/**
 * Streaming deflate compressor with incremental CRC32 calculation.
 * 
 * This class provides:
 * - Chunk-by-chunk compression using deflate
 * - Incremental CRC32 calculation (no need to read file twice)
 * - Memory-efficient processing (configurable chunk size)
 * - Proper deflate context handling
 * 
 * CRITICAL: This class MUST NOT be serialized. Create a new instance for each batch.
 */
final class StreamingCompressor
{
    /** Default chunk size for reading: 64KB */
    public const DEFAULT_CHUNK_SIZE = 65536;

    /** Minimum chunk size: 4KB */
    public const MIN_CHUNK_SIZE = 4096;

    /** Maximum chunk size: 4MB */
    public const MAX_CHUNK_SIZE = 4194304;

    private int $chunkSize;
    private int $deflateLevel;

    /** @var resource|null Deflate context */
    private $deflateContext = null;

    /** @var resource|null Hash context for CRC32 */
    private $crcContext = null;
    
    private ?CryptoEngineInterface $crypto;

    private int $bytesRead = 0;
    private int $bytesWritten = 0;
    private bool $isFinalized = false;

    /**
     * @param int $chunkSize Chunk size for reading
     * @param int $deflateLevel Deflate compression level (0-9)
     * @param CryptoEngineInterface|null $crypto Crypto engine for encryption
     */
    public function __construct(
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        int $deflateLevel = 6,
        ?CryptoEngineInterface $crypto = null
    ) {
        if ($chunkSize < self::MIN_CHUNK_SIZE || $chunkSize > self::MAX_CHUNK_SIZE) {
            throw new \InvalidArgumentException(sprintf(
                'Chunk size must be between %d and %d bytes',
                self::MIN_CHUNK_SIZE,
                self::MAX_CHUNK_SIZE
            ));
        }

        if ($deflateLevel < 0 || $deflateLevel > 9) {
            throw new \InvalidArgumentException('Deflate level must be between 0 and 9');
        }

        $this->chunkSize = $chunkSize;
        $this->deflateLevel = $deflateLevel;
        $this->crypto = $crypto;
    }

    /**
     * Initialize compression contexts.
     * 
     * Must be called before processing each file.
     */
    public function initialize(): void
    {
        $this->deflateContext = deflate_init(ZLIB_ENCODING_RAW, ['level' => $this->deflateLevel]);
        if ($this->deflateContext === false) {
            throw new CompressionException('Failed to initialize deflate context');
        }

        $this->crcContext = hash_init('crc32b');
        $this->bytesRead = 0;
        $this->bytesWritten = 0;
        $this->isFinalized = false;
    }

    /**
     * Process a chunk of data.
     * 
     * @param string $data Raw data chunk
     * @return string Compressed data chunk (may be empty for buffering)
     * @throws CompressionException On compression failure
     */
    public function processChunk(string $data): string
    {
        if ($this->deflateContext === null || $this->crcContext === null) {
            throw new CompressionException('Compressor not initialized. Call initialize() first.');
        }

        if ($this->isFinalized) {
            throw new CompressionException('Cannot process chunk after finalization');
        }

        $this->bytesRead += strlen($data);

        // Update CRC
        hash_update($this->crcContext, $data);

        // Compress
        $compressed = deflate_add($this->deflateContext, $data, ZLIB_NO_FLUSH);
        if ($compressed === false) {
            throw new CompressionException('Deflate compression failed');
        }

        $this->bytesWritten += strlen($compressed);
        return $compressed;
    }

    /**
     * Finalize compression and get remaining data.
     * 
     * @return string Final compressed data
     * @throws CompressionException On finalization failure
     */
    public function finalize(): string
    {
        if ($this->deflateContext === null) {
            throw new CompressionException('Compressor not initialized');
        }

        if ($this->isFinalized) {
            throw new CompressionException('Already finalized');
        }

        // Flush remaining data
        $final = deflate_add($this->deflateContext, '', ZLIB_FINISH);
        if ($final === false) {
            throw new CompressionException('Deflate finalization failed');
        }

        $this->bytesWritten += strlen($final);
        $this->isFinalized = true;

        return $final;
    }

    /**
     * Get the calculated CRC32.
     * 
     * @return int CRC32 as unsigned 32-bit integer
     * @throws CompressionException If not finalized or no CRC context
     */
    public function getCrc32(): int
    {
        if ($this->crcContext === null) {
            throw new CompressionException('CRC context not initialized');
        }

        // hash_final consumes the context
        $hash = hash_final($this->crcContext, true);
        $this->crcContext = null;

        // Convert to unsigned 32-bit integer (big-endian to native)
        $crc = unpack('N', $hash)[1];
        return $crc & 0xFFFFFFFF;
    }

    /**
     * Get total bytes read (uncompressed size).
     */
    public function getBytesRead(): int
    {
        return $this->bytesRead;
    }

    /**
     * Get total bytes written (compressed size).
     */
    public function getBytesWritten(): int
    {
        return $this->bytesWritten;
    }

    /**
     * Reset the compressor for reuse.
     */
    public function reset(): void
    {
        $this->deflateContext = null;
        $this->crcContext = null;
        $this->bytesRead = 0;
        $this->bytesWritten = 0;
        $this->isFinalized = false;
    }

    /**
     * Get the chunk size.
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * Compress an entire stream and return results.
     * 
     * @param ReadableStreamInterface $input Source stream
     * @param callable $outputCallback Called with each compressed chunk: fn(string $data): void
     * @return array{crc32: int, compressedSize: int, uncompressedSize: int}
     */
    public function compressStream(
        ReadableStreamInterface $input,
        callable $outputCallback
    ): array {
        $this->initialize();

        try {
            if ($this->crypto !== null) {
                $header = $this->crypto->getHeader();
                if ($header !== '') {
                    $this->bytesWritten += strlen($header);
                    $outputCallback($header);
                }
            }

            while (!$input->eof()) {
                $chunk = $input->read($this->chunkSize);
                if ($chunk === '') {
                    break;
                }

                $compressed = $this->processChunk($chunk);
                if ($compressed !== '') {
                    if ($this->crypto !== null) {
                        $compressed = $this->crypto->encryptChunk($compressed);
                    }
                    $outputCallback($compressed);
                }
            }

            // Finalize
            $final = $this->finalize();
            if ($final !== '') {
                if ($this->crypto !== null) {
                    $final = $this->crypto->encryptChunk($final);
                }
                $outputCallback($final);
            }
            
            if ($this->crypto !== null) {
                $footer = $this->crypto->getFooter();
                if ($footer !== '') {
                    $this->bytesWritten += strlen($footer);
                    $outputCallback($footer);
                }
            }

            return [
                'crc32' => $this->getCrc32(),
                'compressedSize' => $this->bytesWritten,
                'uncompressedSize' => $this->bytesRead,
            ];
        } catch (ReadFailureException $e) {
            $this->reset();
            throw $e;
        } catch (\Throwable $e) {
            $this->reset();
            throw new CompressionException(
                'Compression failed: ' . $e->getMessage(),
                $input->getIdentifier(),
                ZipFormat::COMPRESSION_DEFLATE,
                $e
            );
        }
    }

    /**
     * Calculate CRC32 and size without compression (for store method).
     * 
     * @param ReadableStreamInterface $input Source stream
     * @param callable $outputCallback Called with each chunk: fn(string $data): void
     * @return array{crc32: int, size: int}
     */
    public function storeStream(
        ReadableStreamInterface $input,
        callable $outputCallback
    ): array {
        $crcContext = hash_init('crc32b');
        $totalSize = 0;
        
        $bytesWritten = 0;

        try {
            if ($this->crypto !== null) {
                $header = $this->crypto->getHeader();
                if ($header !== '') {
                    $bytesWritten += strlen($header);
                    $outputCallback($header);
                }
            }

            while (!$input->eof()) {
                $chunk = $input->read($this->chunkSize);
                if ($chunk === '') {
                    break;
                }

                hash_update($crcContext, $chunk);
                $totalSize += strlen($chunk);
                
                if ($this->crypto !== null) {
                    $chunk = $this->crypto->encryptChunk($chunk);
                }
                $bytesWritten += strlen($chunk);
                $outputCallback($chunk);
            }

            if ($this->crypto !== null) {
                $footer = $this->crypto->getFooter();
                if ($footer !== '') {
                    $bytesWritten += strlen($footer);
                    $outputCallback($footer);
                }
            }

            $hash = hash_final($crcContext, true);
            $crc = unpack('N', $hash)[1] & 0xFFFFFFFF;

            return [
                'crc32' => $crc,
                'size' => $bytesWritten,
            ];
        } catch (ReadFailureException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CompressionException(
                'Store operation failed: ' . $e->getMessage(),
                $input->getIdentifier(),
                ZipFormat::COMPRESSION_STORE,
                $e
            );
        }
    }

    /**
     * Test if deflate is available.
     * 
     * @return bool True if deflate functions are available
     */
    public static function isDeflateAvailable(): bool
    {
        return function_exists('deflate_init')
            && function_exists('deflate_add');
    }
}
