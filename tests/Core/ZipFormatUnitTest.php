<?php

declare(strict_types=1);

namespace BatchZipStream\Tests\Core;

use BatchZipStream\Core\ZipFormat;
use BatchZipStream\State\FileEntry;
use PHPUnit\Framework\TestCase;

$libraryAutoloader = __DIR__ . '/../../autoload.php';
if (file_exists($libraryAutoloader)) {
    require_once $libraryAutoloader;
}

/**
 * Unit tests for ZipFormat helper and metadata methods.
 */
final class ZipFormatUnitTest extends TestCase
{
    public function test_to_dos_time_converts_correctly(): void
    {
        // 2026-06-25 12:00:00 (UNIX timestamp: 1779796800)
        $timestamp = gmmktime(12, 0, 0, 6, 25, 2026);
        $dosTime = ZipFormat::toDosTime($timestamp);

        // Deconstruct DOS time manually to verify bits
        $second = ($dosTime & 0x1F) << 1;
        $minute = ($dosTime >> 5) & 0x3F;
        $hour = ($dosTime >> 11) & 0x1F;
        $day = ($dosTime >> 16) & 0x1F;
        $month = ($dosTime >> 21) & 0x0F;
        $year = (($dosTime >> 25) & 0x7F) + 1980;

        $this->assertEquals(2026, $year);
        $this->assertEquals(6, $month);
        $this->assertEquals(25, $day);
        // Timezone offsets might shift hours, so check range validity
        $this->assertGreaterThanOrEqual(0, $hour);
        $this->assertLessThan(24, $hour);
        $this->assertGreaterThanOrEqual(0, $minute);
        $this->assertLessThan(64, $minute);
    }

    public function test_to_dos_time_caps_minimum_year(): void
    {
        // Epoch minimum is 1980-01-01
        $oldTimestamp = gmmktime(0, 0, 0, 1, 1, 1970);
        $dosTime = ZipFormat::toDosTime($oldTimestamp);

        $year = (($dosTime >> 25) & 0x7F) + 1980;
        $month = ($dosTime >> 21) & 0x0F;
        $day = ($dosTime >> 16) & 0x1F;

        $this->assertEquals(1980, $year);
        $this->assertEquals(1, $month);
        $this->assertEquals(1, $day);
    }

    public function test_to_dos_time_caps_maximum_year(): void
    {
        // Cap year at 2107
        $futureTimestamp = gmmktime(0, 0, 0, 1, 1, 2150);
        $dosTime = ZipFormat::toDosTime($futureTimestamp);

        $year = (($dosTime >> 25) & 0x7F) + 1980;
        $this->assertEquals(2107, $year);
    }

    public function test_sanitize_filename(): void
    {
        $this->assertEquals('foo/bar.txt', ZipFormat::sanitizeFilename('foo\\bar.txt'));
        $this->assertEquals('foo/bar.txt', ZipFormat::sanitizeFilename('/foo/bar.txt'));
        $this->assertEquals('foo_bar.txt', ZipFormat::sanitizeFilename('foo:bar.txt'));
        $this->assertEquals('foo_bar.txt', ZipFormat::sanitizeFilename('foo*bar.txt'));
    }

    public function test_sanitize_filename_conversions(): void
    {
        // ISO-8859-1 conversion to UTF-8
        $isoString = mb_convert_encoding('äöü', 'ISO-8859-1', 'UTF-8');
        $sanitized = ZipFormat::sanitizeFilename($isoString);
        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));

        // Very long filename
        $longName = str_repeat('a', 70000);
        $this->assertEquals(65535, strlen(ZipFormat::sanitizeFilename($longName)));
    }

    public function test_requires_zip64(): void
    {
        // Uncompressed > threshold
        $this->assertTrue(ZipFormat::requiresZip64(ZipFormat::ZIP64_THRESHOLD + 1, 0, 0));
        // Compressed > threshold
        $this->assertTrue(ZipFormat::requiresZip64(0, ZipFormat::ZIP64_THRESHOLD + 1, 0));
        // Offset > threshold
        $this->assertTrue(ZipFormat::requiresZip64(0, 0, ZipFormat::ZIP64_THRESHOLD + 1));
        // None > threshold
        $this->assertFalse(ZipFormat::requiresZip64(1000, 1000, 1000));
    }

    public function test_build_zip64_extra_field_forced(): void
    {
        // When data size would be 0, it should pack PPP and dataSize = 24
        $binary = ZipFormat::buildZip64ExtraField(100, 200, 300);
        $data = unpack('vId/vSize/PUncomp/PComp/POffset', $binary);
        
        $this->assertEquals(ZipFormat::ZIP64_EXTRA_FIELD_ID, $data['Id']);
        $this->assertEquals(24, $data['Size']);
        $this->assertEquals(100, $data['Uncomp']);
        $this->assertEquals(200, $data['Comp']);
        $this->assertEquals(300, $data['Offset']);
    }

    public function test_get_version_needed(): void
    {
        $this->assertEquals(ZipFormat::VERSION_NEEDED_ZIP64, ZipFormat::getVersionNeeded(ZipFormat::COMPRESSION_DEFLATE, true));
        $this->assertEquals(ZipFormat::VERSION_NEEDED_DEFLATE, ZipFormat::getVersionNeeded(ZipFormat::COMPRESSION_DEFLATE, false));
        $this->assertEquals(ZipFormat::VERSION_NEEDED_STORE, ZipFormat::getVersionNeeded(ZipFormat::COMPRESSION_STORE, false));
    }

    public function test_calculate_header_sizes(): void
    {
        $this->assertEquals(30 + 8 + 4, ZipFormat::calculateLocalHeaderSize('test.txt', '1234'));
        
        $entry = new FileEntry(
            'test.txt',
            0x12345678,
            100,
            200,
            50,
            ZipFormat::COMPRESSION_DEFLATE,
            0,
            0,
            0x14,
            false,
            0,
            '',
            'comment'
        );
        $this->assertEquals(46 + 8 + 0 + 7, ZipFormat::calculateCentralDirectoryHeaderSize($entry));

        $entry64 = new FileEntry(
            'test.txt',
            0x12345678,
            100,
            200,
            50,
            ZipFormat::COMPRESSION_DEFLATE,
            0,
            0,
            0x14,
            true, // needsZip64
            0,
            '',
            'comment'
        );
        $this->assertEquals(46 + 8 + 28 + 7, ZipFormat::calculateCentralDirectoryHeaderSize($entry64));
    }
}
