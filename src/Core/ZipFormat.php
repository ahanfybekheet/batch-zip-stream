<?php

declare(strict_types=1);

namespace BatchZipStream\Core;

use BatchZipStream\State\FileEntry;
use BatchZipStream\State\ArchiveState;

/**
 * ZIP format constants and binary structure utilities.
 * 
 * This class provides:
 * - All ZIP format signature constants
 * - Binary packing methods for headers
 * - DOS timestamp conversion
 * - ZIP64 handling
 * 
 * References:
 * - APPNOTE.TXT 6.3.10 (ZIP File Format Specification)
 * - https://pkware.cachefly.net/webdocs/casestudies/APPNOTE.TXT
 */
final class ZipFormat
{
    // ==================== Signature Constants ====================

    /** Local file header signature */
    public const SIG_LOCAL_FILE_HEADER = 0x04034b50;

    /** Central directory file header signature */
    public const SIG_CENTRAL_DIR_HEADER = 0x02014b50;

    /** End of central directory signature */
    public const SIG_END_OF_CENTRAL_DIR = 0x06054b50;

    /** ZIP64 end of central directory record signature */
    public const SIG_ZIP64_END_OF_CENTRAL_DIR = 0x06064b50;

    /** ZIP64 end of central directory locator signature */
    public const SIG_ZIP64_END_OF_CENTRAL_DIR_LOCATOR = 0x07064b50;

    /** Data descriptor signature */
    public const SIG_DATA_DESCRIPTOR = 0x08074b50;

    // ==================== Version Constants ====================

    /** Version made by: OS/2 (or UNIX-like), version 6.3 */
    public const VERSION_MADE_BY = 0x0603;

    /** Version made by for ZIP64: UNIX, version 4.5 */
    public const VERSION_MADE_BY_ZIP64 = 0x002D;

    /** Version needed: 1.0 (store) */
    public const VERSION_NEEDED_STORE = 0x000A;

    /** Version needed: 2.0 (deflate) */
    public const VERSION_NEEDED_DEFLATE = 0x0014;

    /** Version needed: 4.5 (ZIP64) */
    public const VERSION_NEEDED_ZIP64 = 0x002D;

    // ==================== Compression Method Constants ====================

    /** Store (no compression) */
    public const COMPRESSION_STORE = 0;

    /** Deflate compression */
    public const COMPRESSION_DEFLATE = 8;

    /** WinZip AES encryption (Actual compression method is in extra field) */
    public const COMPRESSION_WINZIP_AES = 99;

    // ==================== Encryption Method Constants ====================

    public const ENC_NONE = 0;
    public const ENC_TRADITIONAL = 1;
    public const ENC_AES_256 = 3;

    // ==================== Bit Flag Constants ====================

    /** Encrypted file */
    public const FLAG_ENCRYPTED = 0x0001;

    /** Data descriptor present after file data */
    public const FLAG_DATA_DESCRIPTOR = 0x0008;

    /** Language encoding flag (UTF-8) */
    public const FLAG_UTF8 = 0x0800;

    // ==================== ZIP64 Constants ====================

    /** ZIP64 extra field header ID */
    public const ZIP64_EXTRA_FIELD_ID = 0x0001;

    /** Maximum value before ZIP64 is required (32-bit) */
    public const ZIP64_THRESHOLD = 0xFFFFFFFF;

    /** Maximum file count before ZIP64 is required */
    public const ZIP64_MAX_FILES = 0xFFFF;

    // ==================== External Attributes ====================

    /** External attributes: regular file, archive bit */
    public const EXT_ATTR_FILE = 0x00000020;

    /** External attributes: directory */
    public const EXT_ATTR_DIRECTORY = 0x00000010;

    // ==================== Header Sizes ====================

    /** Size of local file header (without variable fields) */
    public const LOCAL_HEADER_FIXED_SIZE = 30;

    /** Size of central directory header (without variable fields) */
    public const CENTRAL_DIR_HEADER_FIXED_SIZE = 46;

    /** Size of end of central directory record (without comment) */
    public const EOCD_FIXED_SIZE = 22;

    /** Size of ZIP64 end of central directory record */
    public const ZIP64_EOCD_FIXED_SIZE = 56;

    /** Size of ZIP64 end of central directory locator */
    public const ZIP64_EOCD_LOCATOR_SIZE = 20;

    /**
     * Build a local file header.
     * 
     * @param FileEntry $entry File entry metadata
     * @param string $localExtraField Extra field for local header (ZIP64 sizes)
     * @return string Binary local file header
     */
    public static function buildLocalFileHeader(FileEntry $entry, string $localExtraField = ''): string
    {
        $filenameLength = strlen($entry->filename);
        $extraFieldLength = strlen($localExtraField);

        // Determine values (use 0xFFFFFFFF placeholders for ZIP64)
        $compressedSize = $entry->needsZip64() && $entry->compressedSize > self::ZIP64_THRESHOLD
            ? self::ZIP64_THRESHOLD
            : $entry->compressedSize;
        $uncompressedSize = $entry->needsZip64() && $entry->uncompressedSize > self::ZIP64_THRESHOLD
            ? self::ZIP64_THRESHOLD
            : $entry->uncompressedSize;

        $header = pack(
            'VvvvVVVVvv',
            self::SIG_LOCAL_FILE_HEADER,    // 4 bytes: signature
            $entry->versionNeeded,           // 2 bytes: version needed
            $entry->generalPurposeBitFlag,   // 2 bytes: general purpose bit flag
            $entry->compressionMethod,       // 2 bytes: compression method
            $entry->dosTime,                 // 4 bytes: last mod time/date
            $entry->crc32,                   // 4 bytes: CRC-32
            $compressedSize,                 // 4 bytes: compressed size
            $uncompressedSize,               // 4 bytes: uncompressed size
            $filenameLength,                 // 2 bytes: filename length
            $extraFieldLength                // 2 bytes: extra field length
        );

        return $header . $entry->filename . $localExtraField;
    }

    /**
     * Build a central directory file header.
     * 
     * @param FileEntry $entry File entry metadata
     * @return string Binary central directory header
     */
    public static function buildCentralDirectoryHeader(FileEntry $entry): string
    {
        $filenameLength = strlen($entry->filename);
        $commentLength = strlen($entry->comment);

        // Build extra field for ZIP64 if needed
        $extraField = '';
        if ($entry->needsZip64()) {
            $extraField = self::buildZip64ExtraField(
                $entry->uncompressedSize,
                $entry->compressedSize,
                $entry->getFullOffset()
            );
        }
        $extraFieldLength = strlen($extraField);

        // Determine values (use 0xFFFFFFFF placeholders for ZIP64)
        $compressedSize = $entry->compressedSize > self::ZIP64_THRESHOLD
            ? self::ZIP64_THRESHOLD
            : $entry->compressedSize;
        $uncompressedSize = $entry->uncompressedSize > self::ZIP64_THRESHOLD
            ? self::ZIP64_THRESHOLD
            : $entry->uncompressedSize;
        $localOffset = $entry->localHeaderOffset > self::ZIP64_THRESHOLD || $entry->localHeaderOffsetHigh > 0
            ? self::ZIP64_THRESHOLD
            : $entry->localHeaderOffset;

        // Version needed
        $versionNeeded = $entry->needsZip64()
            ? self::VERSION_NEEDED_ZIP64
            : $entry->versionNeeded;
        
        $actualCompressionMethod = $entry->compressionMethod;
        $headerCompressionMethod = $actualCompressionMethod;
        
        if ($entry->encryptionMethod === self::ENC_AES_256) {
            $headerCompressionMethod = self::COMPRESSION_WINZIP_AES;
            // AES extra field MUST be the first extra field for some tools
            $extraField = self::buildWinZipAesExtraField($actualCompressionMethod, 3) . $extraField;
        }

        // Determine external attributes based on whether this is a directory
        $externalAttributes = $entry->isDirectory
            ? self::EXT_ATTR_DIRECTORY
            : self::EXT_ATTR_FILE;

        $extraFieldLength = strlen($extraField);

        $header = pack(
            'VvvvvVVVVvvvvvVV',
            self::SIG_CENTRAL_DIR_HEADER,    // 4 bytes: signature
            self::VERSION_MADE_BY,           // 2 bytes: version made by
            $versionNeeded,                  // 2 bytes: version needed
            $entry->generalPurposeBitFlag,   // 2 bytes: general purpose bit flag
            $headerCompressionMethod,        // 2 bytes: compression method
            $entry->dosTime,                 // 4 bytes: last mod time/date
            $entry->crc32,                   // 4 bytes: CRC-32
            $compressedSize,                 // 4 bytes: compressed size
            $uncompressedSize,               // 4 bytes: uncompressed size
            $filenameLength,                 // 2 bytes: filename length
            $extraFieldLength,               // 2 bytes: extra field length
            $commentLength,                  // 2 bytes: file comment length
            0,                               // 2 bytes: disk number start
            0,                               // 2 bytes: internal file attributes
            $externalAttributes,             // 4 bytes: external file attributes
            $localOffset                     // 4 bytes: relative offset of local header
        );

        return $header . $entry->filename . $extraField . $entry->comment;
    }

    /**
     * Build ZIP64 extra field.
     * 
     * @param int $uncompressedSize Original size
     * @param int $compressedSize Compressed size
     * @param int $offset Local header offset
     * @return string Binary extra field
     */
    public static function buildZip64ExtraField(
        int $uncompressedSize,
        int $compressedSize,
        int $offset
    ): string {
        // Determine which fields to include
        $dataSize = 0;
        $data = '';

        // Always include uncompressed size if > threshold
        if ($uncompressedSize > self::ZIP64_THRESHOLD) {
            $data .= pack('P', $uncompressedSize);
            $dataSize += 8;
        }

        // Always include compressed size if > threshold
        if ($compressedSize > self::ZIP64_THRESHOLD) {
            $data .= pack('P', $compressedSize);
            $dataSize += 8;
        }

        // Always include offset if > threshold
        if ($offset > self::ZIP64_THRESHOLD) {
            $data .= pack('P', $offset);
            $dataSize += 8;
        }

        // If nothing exceeded threshold but ZIP64 is required, include all
        if ($dataSize === 0) {
            $data = pack('PPP', $uncompressedSize, $compressedSize, $offset);
            $dataSize = 24;
        }

        return pack('vv', self::ZIP64_EXTRA_FIELD_ID, $dataSize) . $data;
    }

    /**
     * Build ZIP64 extra field for local file header (sizes only, no offset).
     */
    public static function buildZip64LocalExtraField(
        int $uncompressedSize,
        int $compressedSize
    ): string {
        return pack(
            'vvPP',
            self::ZIP64_EXTRA_FIELD_ID,
            16,  // data size: 8 + 8 bytes
            $uncompressedSize,
            $compressedSize
        );
    }

    /**
     * Build WinZip AES Extra Field (0x9901).
     * 
     * @param int $actualCompressionMethod
     * @param int $encryptionStrength 1 for 128-bit, 2 for 192-bit, 3 for 256-bit
     * @return string Binary extra field
     */
    public static function buildWinZipAesExtraField(int $actualCompressionMethod, int $encryptionStrength = 3): string
    {
        return pack(
            'vvvvCv',
            0x9901,                      // 2 bytes: Extra field ID
            7,                           // 2 bytes: Data size
            2,                           // 2 bytes: Vendor version (AE-2)
            0x4541,                      // 2 bytes: Vendor ID ('AE')
            $encryptionStrength,         // 1 byte: Encryption strength
            $actualCompressionMethod     // 2 bytes: Actual compression method
        );
    }

    /**
     * Build End of Central Directory Record.
     * 
     * @param int $fileCount Total number of files
     * @param int $cdrSize Central directory size in bytes
     * @param int $cdrOffset Offset where central directory starts
     * @param string $comment Archive comment
     * @return string Binary EOCD record
     */
    public static function buildEndOfCentralDirectory(
        int $fileCount,
        int $cdrSize,
        int $cdrOffset,
        string $comment = ''
    ): string {
        $commentLength = strlen($comment);

        // Use 0xFFFF/0xFFFFFFFF if values exceed limits (ZIP64 required)
        $entryCount = $fileCount >= self::ZIP64_MAX_FILES ? 0xFFFF : $fileCount;
        $size = $cdrSize > self::ZIP64_THRESHOLD ? self::ZIP64_THRESHOLD : $cdrSize;
        $offset = $cdrOffset > self::ZIP64_THRESHOLD ? self::ZIP64_THRESHOLD : $cdrOffset;

        $eocd = pack(
            'VvvvvVVv',
            self::SIG_END_OF_CENTRAL_DIR,    // 4 bytes: signature
            0,                                // 2 bytes: disk number
            0,                                // 2 bytes: disk with CDR start
            $entryCount,                      // 2 bytes: entries on this disk
            $entryCount,                      // 2 bytes: total entries
            $size,                            // 4 bytes: CDR size
            $offset,                          // 4 bytes: CDR offset
            $commentLength                    // 2 bytes: comment length
        );

        return $eocd . $comment;
    }

    /**
     * Build ZIP64 End of Central Directory Record.
     * 
     * @param int $fileCount Total number of files
     * @param int $cdrSize Central directory size in bytes
     * @param int $cdrOffset Offset where central directory starts
     * @return string Binary ZIP64 EOCD record
     */
    public static function buildZip64EndOfCentralDirectory(
        int $fileCount,
        int $cdrSize,
        int $cdrOffset
    ): string {
        // Size of remaining record (56 - 12 = 44 bytes)
        $recordSize = 44;

        return pack(
            'VPvvVVPPPP',
            self::SIG_ZIP64_END_OF_CENTRAL_DIR,  // 4 bytes: signature
            $recordSize,                          // 8 bytes: size of remaining record
            self::VERSION_MADE_BY_ZIP64,          // 2 bytes: version made by
            self::VERSION_NEEDED_ZIP64,           // 2 bytes: version needed
            0,                                    // 4 bytes: disk number
            0,                                    // 4 bytes: disk with CDR start
            $fileCount,                           // 8 bytes: entries on this disk
            $fileCount,                           // 8 bytes: total entries
            $cdrSize,                             // 8 bytes: CDR size
            $cdrOffset                            // 8 bytes: CDR offset
        );
    }

    /**
     * Build ZIP64 End of Central Directory Locator.
     * 
     * @param int $zip64EocdOffset Offset where ZIP64 EOCD record starts
     * @return string Binary ZIP64 EOCD locator
     */
    public static function buildZip64EndOfCentralDirectoryLocator(int $zip64EocdOffset): string
    {
        return pack(
            'VVPV',
            self::SIG_ZIP64_END_OF_CENTRAL_DIR_LOCATOR,  // 4 bytes: signature
            0,                                            // 4 bytes: disk with ZIP64 EOCD
            $zip64EocdOffset,                             // 8 bytes: ZIP64 EOCD offset
            1                                             // 4 bytes: total disks
        );
    }

    /**
     * Build a data descriptor (written after file data when using streaming).
     * 
     * When bit 3 (FLAG_DATA_DESCRIPTOR) is set in the general purpose bit flag,
     * the CRC-32, compressed size, and uncompressed size are set to zero in the
     * local file header and a data descriptor is written after the file data.
     * 
     * @param int $crc32 CRC-32 checksum
     * @param int $compressedSize Compressed size
     * @param int $uncompressedSize Uncompressed size  
     * @param bool $useZip64 Whether to use ZIP64 format (8-byte sizes instead of 4-byte)
     * @return string Binary data descriptor
     */
    public static function buildDataDescriptor(
        int $crc32,
        int $compressedSize,
        int $uncompressedSize,
        bool $useZip64 = false
    ): string {
        if ($useZip64) {
            // ZIP64 data descriptor: signature + crc32 + 8-byte compressed + 8-byte uncompressed
            return pack(
                'VVPP',
                self::SIG_DATA_DESCRIPTOR,    // 4 bytes: signature
                $crc32,                        // 4 bytes: CRC-32
                $compressedSize,               // 8 bytes: compressed size
                $uncompressedSize              // 8 bytes: uncompressed size
            );
        }

        // Standard data descriptor: signature + crc32 + 4-byte sizes
        return pack(
            'VVVV',
            self::SIG_DATA_DESCRIPTOR,    // 4 bytes: signature
            $crc32,                        // 4 bytes: CRC-32
            $compressedSize,               // 4 bytes: compressed size
            $uncompressedSize              // 4 bytes: uncompressed size
        );
    }

    /**
     * Build a local file header for streaming with data descriptor.
     * 
     * This sets CRC-32, compressed size, and uncompressed size to zero,
     * and sets bit 3 in the general purpose flag. The actual values
     * will be written in a data descriptor after the file data.
     * 
     * @param string $filename Sanitized filename
     * @param int $compressionMethod Compression method
     * @param int $dosTime DOS timestamp
     * @param bool $useZip64 Whether ZIP64 extra field should be included
     * @param int $encryptionMethod Encryption method (default ENC_NONE)
     * @return string Binary local file header
     */
    public static function buildLocalFileHeaderWithDataDescriptor(
        string $filename,
        int $compressionMethod,
        int $dosTime,
        bool $useZip64 = false,
        int $encryptionMethod = self::ENC_NONE
    ): string {
        $filenameLength = strlen($filename);
        
        $isEncrypted = $encryptionMethod !== self::ENC_NONE;

        // Build bit flags with data descriptor flag (bit 3) set
        $bitFlags = self::getGeneralPurposeBitFlag($filename, true, $isEncrypted);

        $actualCompressionMethod = $compressionMethod;
        $headerCompressionMethod = $compressionMethod;

        if ($encryptionMethod === self::ENC_AES_256) {
            $headerCompressionMethod = self::COMPRESSION_WINZIP_AES;
        }

        // Version needed
        $versionNeeded = $useZip64
            ? self::VERSION_NEEDED_ZIP64
            : self::getVersionNeeded($encryptionMethod === self::ENC_AES_256 ? self::COMPRESSION_WINZIP_AES : $compressionMethod, false);

        // Build extra field for ZIP64 and/or AES if needed
        $extraField = '';
        if ($encryptionMethod === self::ENC_AES_256) {
            $extraField .= self::buildWinZipAesExtraField($actualCompressionMethod, 3);
        }
        if ($useZip64) {
            // ZIP64 local extra field with zero placeholders
            $extraField .= pack(
                'vvPP',
                self::ZIP64_EXTRA_FIELD_ID,
                16,  // data size: 8 + 8 bytes
                0,   // uncompressed size placeholder
                0    // compressed size placeholder
            );
        }
        $extraFieldLength = strlen($extraField);

        // CRC and sizes are zero when using data descriptor
        $header = pack(
            'VvvvVVVVvv',
            self::SIG_LOCAL_FILE_HEADER,    // 4 bytes: signature
            $versionNeeded,                  // 2 bytes: version needed
            $bitFlags,                       // 2 bytes: general purpose bit flag (with bit 3 set)
            $headerCompressionMethod,        // 2 bytes: compression method
            $dosTime,                        // 4 bytes: last mod time/date
            0,                               // 4 bytes: CRC-32 (zero for data descriptor)
            0,                               // 4 bytes: compressed size (zero for data descriptor)
            0,                               // 4 bytes: uncompressed size (zero for data descriptor)
            $filenameLength,                 // 2 bytes: filename length
            $extraFieldLength                // 2 bytes: extra field length
        );

        return $header . $filename . $extraField;
    }

    /**
     * Build a local file header with known CRC32 and sizes (no data descriptor).
     * 
     * Use this for files/directories where sizes are known upfront (e.g., empty directories,
     * small files already in memory).
     * 
     * @param string $filename Sanitized filename
     * @param int $compressionMethod Compression method
     * @param int $dosTime DOS timestamp
     * @param int $crc32 CRC-32 checksum
     * @param int $compressedSize Compressed size in bytes
     * @param int $uncompressedSize Uncompressed size in bytes
     * @param int $bitFlags General purpose bit flags
     * @param int $versionNeeded Minimum version needed to extract
     * @param string $extraField Extra field data (for ZIP64, etc.)
     * @param int $encryptionMethod Encryption method (default ENC_NONE)
     * @return string Binary local file header
     */
    public static function buildLocalFileHeaderDirect(
        string $filename,
        int $compressionMethod,
        int $dosTime,
        int $crc32,
        int $compressedSize,
        int $uncompressedSize,
        int $bitFlags,
        int $versionNeeded,
        string $extraField = '',
        int $encryptionMethod = self::ENC_NONE
    ): string {
        $filenameLength = strlen($filename);

        $actualCompressionMethod = $compressionMethod;
        $headerCompressionMethod = $compressionMethod;

        if ($encryptionMethod === self::ENC_AES_256) {
            $headerCompressionMethod = self::COMPRESSION_WINZIP_AES;
            $extraField = self::buildWinZipAesExtraField($actualCompressionMethod, 3) . $extraField;
            $versionNeeded = 51; // Override version needed for AES
        }

        $extraFieldLength = strlen($extraField);

        // Use 0xFFFFFFFF placeholders for ZIP64
        $packedCompressedSize = $compressedSize > self::ZIP64_THRESHOLD
            ? self::ZIP64_THRESHOLD
            : $compressedSize;
        $packedUncompressedSize = $uncompressedSize > self::ZIP64_THRESHOLD
            ? self::ZIP64_THRESHOLD
            : $uncompressedSize;

        $header = pack(
            'VvvvVVVVvv',
            self::SIG_LOCAL_FILE_HEADER,    // 4 bytes: signature
            $versionNeeded,                  // 2 bytes: version needed
            $bitFlags,                       // 2 bytes: general purpose bit flag
            $headerCompressionMethod,        // 2 bytes: compression method
            $dosTime,                        // 4 bytes: last mod time/date
            $crc32,                          // 4 bytes: CRC-32
            $packedCompressedSize,           // 4 bytes: compressed size
            $packedUncompressedSize,         // 4 bytes: uncompressed size
            $filenameLength,                 // 2 bytes: filename length
            $extraFieldLength                // 2 bytes: extra field length
        );

        return $header . $filename . $extraField;
    }

    /**
     * Calculate the size of a data descriptor.
     * 
     * @param bool $useZip64 Whether ZIP64 format is used
     * @return int Size in bytes
     */
    public static function getDataDescriptorSize(bool $useZip64): int
    {
        // Signature (4) + CRC32 (4) + sizes (4+4 or 8+8)
        return $useZip64 ? 24 : 16;
    }

    /**
     * Convert UNIX timestamp to DOS date/time format.
     * 
     * DOS date/time format:
     * - Bits 0-4:   Second divided by 2 (0-29)
     * - Bits 5-10:  Minute (0-59)
     * - Bits 11-15: Hour (0-23)
     * - Bits 16-20: Day (1-31)
     * - Bits 21-24: Month (1-12)
     * - Bits 25-31: Year from 1980 (0-127)
     * 
     * @param int $timestamp UNIX timestamp
     * @return int DOS timestamp (4 bytes)
     */
    public static function toDosTime(int $timestamp): int
    {
        $d = getdate($timestamp);

        // DOS epoch starts at 1980-01-01
        if ($d['year'] < 1980) {
            $d = [
                'year' => 1980,
                'mon' => 1,
                'mday' => 1,
                'hours' => 0,
                'minutes' => 0,
                'seconds' => 0
            ];
        }

        // Cap year at 2107 (1980 + 127)
        if ($d['year'] > 2107) {
            $d['year'] = 2107;
        }

        $year = $d['year'] - 1980;

        return ($year << 25)
            | ($d['mon'] << 21)
            | ($d['mday'] << 16)
            | ($d['hours'] << 11)
            | ($d['minutes'] << 5)
            | ($d['seconds'] >> 1);
    }

    /**
     * Sanitize filename for ZIP compatibility.
     * 
     * - Converts backslashes to forward slashes
     * - Removes leading slashes
     * - Replaces invalid characters
     * - Ensures valid encoding
     * 
     * @param string $filename Original filename
     * @return string Sanitized filename
     */
    public static function sanitizeFilename(string $filename): string
    {
        // Convert backslashes to forward slashes
        $filename = str_replace('\\', '/', $filename);

        // Remove leading slashes
        $filename = ltrim($filename, '/');

        // Replace invalid Windows characters
        $filename = str_replace([':', '*', '?', '"', '<', '>', '|'], '_', $filename);

        // Ensure UTF-8 encoding
        if (!mb_check_encoding($filename, 'UTF-8')) {
            $filename = mb_convert_encoding($filename, 'UTF-8', 'ISO-8859-1');
        }

        // Limit length
        if (strlen($filename) > 65535) {
            $filename = substr($filename, 0, 65535);
        }

        return $filename;
    }

    /**
     * Get general purpose bit flags for a file.
     * 
     * @param string $filename Filename to check for UTF-8
     * @param bool $useDataDescriptor Whether data descriptor is used
     * @param bool $encrypted Whether the file is encrypted
     * @return int Bit flags
     */
    public static function getGeneralPurposeBitFlag(
        string $filename,
        bool $useDataDescriptor = false,
        bool $encrypted = false
    ): int {
        $flags = 0;

        if ($encrypted) {
            $flags |= self::FLAG_ENCRYPTED;
        }

        // UTF-8 encoding flag (bit 11)
        if (!mb_check_encoding($filename, 'ASCII')) {
            $flags |= self::FLAG_UTF8;
        }

        // Data descriptor flag (bit 3)
        if ($useDataDescriptor) {
            $flags |= self::FLAG_DATA_DESCRIPTOR;
        }

        return $flags;
    }

    /**
     * Get version needed based on compression method and ZIP64 requirement.
     * 
     * @param int $compressionMethod Compression method
     * @param bool $needsZip64 Whether ZIP64 is needed
     * @return int Version needed to extract
     */
    public static function getVersionNeeded(int $compressionMethod, bool $needsZip64): int
    {
        if ($needsZip64) {
            return self::VERSION_NEEDED_ZIP64;
        }

        if ($compressionMethod === self::COMPRESSION_WINZIP_AES) {
            return 51; // Version 5.1 is required for AES
        }

        return $compressionMethod === self::COMPRESSION_STORE
            ? self::VERSION_NEEDED_STORE
            : self::VERSION_NEEDED_DEFLATE;
    }

    /**
     * Check if a file entry requires ZIP64 extensions.
     * 
     * @param int $uncompressedSize Uncompressed size
     * @param int $compressedSize Compressed size
     * @param int $localHeaderOffset Local header offset
     * @return bool True if ZIP64 is required
     */
    public static function requiresZip64(
        int $uncompressedSize,
        int $compressedSize,
        int $localHeaderOffset
    ): bool {
        return $uncompressedSize > self::ZIP64_THRESHOLD
            || $compressedSize > self::ZIP64_THRESHOLD
            || $localHeaderOffset > self::ZIP64_THRESHOLD;
    }

    /**
     * Calculate the local file header size.
     * 
     * @param string $filename Filename
     * @param string $extraField Extra field
     * @return int Header size in bytes
     */
    public static function calculateLocalHeaderSize(string $filename, string $extraField = ''): int
    {
        return self::LOCAL_HEADER_FIXED_SIZE + strlen($filename) + strlen($extraField);
    }

    /**
     * Calculate the central directory header size.
     * 
     * @param FileEntry $entry File entry
     * @return int Header size in bytes
     */
    public static function calculateCentralDirectoryHeaderSize(FileEntry $entry): int
    {
        $extraFieldSize = $entry->needsZip64() ? 28 : 0; // Approximate ZIP64 extra field
        return self::CENTRAL_DIR_HEADER_FIXED_SIZE
            + strlen($entry->filename)
            + $extraFieldSize
            + strlen($entry->comment);
    }
}
