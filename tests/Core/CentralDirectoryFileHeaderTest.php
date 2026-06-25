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
 * Tests for Central Directory File Header packing inside ZipFormat.
 */
final class CentralDirectoryFileHeaderTest extends TestCase
{
    public function test_build_central_directory_file_header(): void
    {
        // Standard
        $entry = new FileEntry(
            'test.txt',
            0x12345678,
            100,
            200,
            50,
            ZipFormat::COMPRESSION_DEFLATE,
            0x1234,
            0,
            ZipFormat::VERSION_NEEDED_DEFLATE
        );

        $binary = ZipFormat::buildCentralDirectoryHeader($entry);
        $fixedHeader = substr($binary, 0, ZipFormat::CENTRAL_DIR_HEADER_FIXED_SIZE);
        $data = unpack('Vsig/vmadeBy/vversion/vflag/vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen/vcommentLen/vdisk/vattrInt/VattrExt/Voffset', $fixedHeader);

        $this->assertEquals(ZipFormat::SIG_CENTRAL_DIR_HEADER, $data['sig']);
        $this->assertEquals(100, $data['comp']);
        $this->assertEquals(200, $data['uncomp']);
        $this->assertEquals(50, $data['offset']);
        $this->assertEquals(0, $data['extraLen']);

        // ZIP64 (all values above threshold)
        $entry64 = new FileEntry(
            'test.txt',
            0x12345678,
            ZipFormat::ZIP64_THRESHOLD + 10,
            ZipFormat::ZIP64_THRESHOLD + 20,
            ZipFormat::ZIP64_THRESHOLD + 30,
            ZipFormat::COMPRESSION_DEFLATE,
            0x1234,
            0,
            ZipFormat::VERSION_NEEDED_ZIP64,
            true
        );

        $binary64 = ZipFormat::buildCentralDirectoryHeader($entry64);
        $fixedHeader64 = substr($binary64, 0, ZipFormat::CENTRAL_DIR_HEADER_FIXED_SIZE);
        $data64 = unpack('Vsig/vmadeBy/vversion/vflag/vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen/vcommentLen/vdisk/vattrInt/VattrExt/Voffset', $fixedHeader64);

        $this->assertEquals(ZipFormat::ZIP64_THRESHOLD, $data64['comp']);
        $this->assertEquals(ZipFormat::ZIP64_THRESHOLD, $data64['uncomp']);
        $this->assertEquals(ZipFormat::ZIP64_THRESHOLD, $data64['offset']);
        $this->assertTrue($data64['extraLen'] > 0);
    }
}
