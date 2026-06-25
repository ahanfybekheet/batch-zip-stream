<?php

declare(strict_types=1);

namespace BatchZipStream\State;

/**
 * Immutable representation of a single file's metadata in the archive.
 * 
 * This contains ALL information needed to:
 * 1. Write the Central Directory entry for this file
 * 2. Validate archive integrity
 * 3. Resume batch operations
 * 
 * This class is serializable via toArray/fromArray for state persistence.
 * 
 * @immutable Treat as immutable after construction — do not modify properties directly.
 *
 */
class FileEntry
{
    // Compression method constants
    public const METHOD_STORE = 0;
    public const METHOD_DEFLATE = 8;
    
    // Encryption method constants
    public const ENC_NONE = 0;
    public const ENC_TRADITIONAL = 1;
    public const ENC_AES_256 = 3;

    /** @var string Path/name in the ZIP archive (UTF-8, forward slashes) */
    public string $filename;
    /** @var int CRC-32 checksum of uncompressed data */
    public int $crc32;
    /** @var int Size after compression (bytes) */
    public int $compressedSize;
    /** @var int Original file size (bytes) */
    public int $uncompressedSize;
    /** @var int Byte offset where local header starts */
    public int $localHeaderOffset;
    /** @var int 0=store, 8=deflate */
    public int $compressionMethod;
    /** @var int DOS timestamp for last modification */
    public int $dosTime;
    /** @var int Bit flags (including UTF-8 flag) */
    public int $generalPurposeBitFlag;
    /** @var int Minimum version needed to extract */
    public int $versionNeeded;
    /** @var bool Whether this entry requires ZIP64 extensions */
    public bool $requiresZip64;
    /** @var int High 32 bits for ZIP64 offset */
    public int $localHeaderOffsetHigh;
    /** @var string Extra field data (for ZIP64, etc.) */
    public string $extraField;
    /** @var string File comment (optional) */
    public string $comment;
    /** @var bool Whether this entry represents a directory */
    public bool $isDirectory;
    /** @var int Encryption method used */
    public int $encryptionMethod;

    public function __construct(
        string $filename,
        int $crc32,
        int $compressedSize,
        int $uncompressedSize,
        int $localHeaderOffset,
        int $compressionMethod = self::METHOD_DEFLATE,
        int $dosTime = 0,
        int $generalPurposeBitFlag = 0,
        int $versionNeeded = 0x14,
        bool $requiresZip64 = false,
        int $localHeaderOffsetHigh = 0,
        string $extraField = '',
        string $comment = '',
        bool $isDirectory = false,
        int $encryptionMethod = self::ENC_NONE
    ) {
        $this->filename              = $filename;
        $this->crc32                 = $crc32;
        $this->compressedSize        = $compressedSize;
        $this->uncompressedSize      = $uncompressedSize;
        $this->localHeaderOffset     = $localHeaderOffset;
        $this->compressionMethod     = $compressionMethod;
        $this->dosTime               = $dosTime;
        $this->generalPurposeBitFlag = $generalPurposeBitFlag;
        $this->versionNeeded         = $versionNeeded;
        $this->requiresZip64         = $requiresZip64;
        $this->localHeaderOffsetHigh = $localHeaderOffsetHigh;
        $this->extraField            = $extraField;
        $this->comment               = $comment;
        $this->isDirectory           = $isDirectory;
        $this->encryptionMethod      = $encryptionMethod;
    }

    /**
     * Check if this entry requires ZIP64 extensions.
     */
    public function needsZip64(): bool
    {
        return $this->requiresZip64
            || $this->compressedSize > 0xFFFFFFFF
            || $this->uncompressedSize > 0xFFFFFFFF
            || $this->localHeaderOffset > 0xFFFFFFFF
            || $this->localHeaderOffsetHigh > 0;
    }

    /**
     * Get the full 64-bit local header offset.
     */
    public function getFullOffset(): int
    {
        if (PHP_INT_SIZE >= 8) {
            return ($this->localHeaderOffsetHigh << 32) | $this->localHeaderOffset;
        }
        // 32-bit PHP: return low bits only (limited support)
        return $this->localHeaderOffset;
    }

    /**
     * Serialize to array for persistence.
     * 
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'crc32' => $this->crc32,
            'compressedSize' => $this->compressedSize,
            'uncompressedSize' => $this->uncompressedSize,
            'localHeaderOffset' => $this->localHeaderOffset,
            'compressionMethod' => $this->compressionMethod,
            'dosTime' => $this->dosTime,
            'generalPurposeBitFlag' => $this->generalPurposeBitFlag,
            'versionNeeded' => $this->versionNeeded,
            'requiresZip64' => $this->requiresZip64,
            'localHeaderOffsetHigh' => $this->localHeaderOffsetHigh,
            'extraField' => base64_encode($this->extraField),
            'comment' => $this->comment,
            'isDirectory' => $this->isDirectory,
            'encryptionMethod' => $this->encryptionMethod,
        ];
    }

    /**
     * Restore from array.
     * 
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['filename'],
            (int) $data['crc32'],
            (int) $data['compressedSize'],
            (int) $data['uncompressedSize'],
            (int) $data['localHeaderOffset'],
            (int) ($data['compressionMethod'] ?? self::METHOD_DEFLATE),
            (int) ($data['dosTime'] ?? 0),
            (int) ($data['generalPurposeBitFlag'] ?? 0),
            (int) ($data['versionNeeded'] ?? 0x14),
            (bool) ($data['requiresZip64'] ?? false),
            (int) ($data['localHeaderOffsetHigh'] ?? 0),
            base64_decode($data['extraField'] ?? ''),
            $data['comment'] ?? '',
            (bool) ($data['isDirectory'] ?? false),
            (int) ($data['encryptionMethod'] ?? self::ENC_NONE)
        );
    }

    /**
     * Validate entry integrity.
     * 
     * @return array<string> List of validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->filename)) {
            $errors[] = 'Filename cannot be empty';
        }

        if (strlen($this->filename) > 65535) {
            $errors[] = 'Filename exceeds maximum length of 65535 bytes';
        }

        if ($this->compressedSize < 0) {
            $errors[] = 'Compressed size cannot be negative';
        }

        if ($this->uncompressedSize < 0) {
            $errors[] = 'Uncompressed size cannot be negative';
        }

        if ($this->localHeaderOffset < 0) {
            $errors[] = 'Local header offset cannot be negative';
        }

        if (!in_array($this->compressionMethod, [self::METHOD_STORE, self::METHOD_DEFLATE], true)) {
            $errors[] = sprintf('Unsupported compression method: %d', $this->compressionMethod);
        }

        // Stored files must have equal compressed and uncompressed sizes
        if (
            $this->compressionMethod === self::METHOD_STORE &&
            $this->compressedSize !== $this->uncompressedSize
        ) {
            $errors[] = 'Stored file sizes must match';
        }

        return $errors;
    }
}
