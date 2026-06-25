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
 * Tests for Local File Header packing inside ZipFormat.
 */
final class LocalFileHeaderTest extends TestCase
{
    public function test_build_local_file_header(): void
    {
        // Standard non-ZIP64
        $entry = new FileEntry(
            'test.txt',
            0x12345678,
            100,
            200,
            10,
            ZipFormat::COMPRESSION_DEFLATE,
            0x1234,
            0,
            ZipFormat::VERSION_NEEDED_DEFLATE
        );

        $binary = ZipFormat::buildLocalFileHeader($entry);
        $fixedHeader = substr($binary, 0, ZipFormat::LOCAL_HEADER_FIXED_SIZE);
        $data = unpack('Vsig/vversion/vflag/vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen', $fixedHeader);

        $this->assertEquals(ZipFormat::SIG_LOCAL_FILE_HEADER, $data['sig']);
        $this->assertEquals(100, $data['comp']);
        $this->assertEquals(200, $data['uncomp']);
        $this->assertEquals(0, $data['extraLen']);

        // ZIP64
        $entry64 = new FileEntry(
            'test64.txt',
            0x12345678,
            ZipFormat::ZIP64_THRESHOLD + 10,
            ZipFormat::ZIP64_THRESHOLD + 20,
            10,
            ZipFormat::COMPRESSION_DEFLATE,
            0x1234,
            0,
            ZipFormat::VERSION_NEEDED_ZIP64,
            true
        );

        $extraField = ZipFormat::buildZip64LocalExtraField(ZipFormat::ZIP64_THRESHOLD + 20, ZipFormat::ZIP64_THRESHOLD + 10);
        $binary64 = ZipFormat::buildLocalFileHeader($entry64, $extraField);
        $fixedHeader64 = substr($binary64, 0, ZipFormat::LOCAL_HEADER_FIXED_SIZE);
        $data64 = unpack('Vsig/vversion/vflag/vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen', $fixedHeader64);

        $this->assertEquals(ZipFormat::ZIP64_THRESHOLD, $data64['comp']);
        $this->assertEquals(ZipFormat::ZIP64_THRESHOLD, $data64['uncomp']);
        $this->assertEquals(strlen($extraField), $data64['extraLen']);
    }

    public function test_build_local_file_header_direct(): void
    {
        $filename = 'test.txt';
        $binary = ZipFormat::buildLocalFileHeaderDirect(
            $filename,
            ZipFormat::COMPRESSION_DEFLATE,
            0x1234,
            0xABCDEF,
            100,
            200,
            0,
            ZipFormat::VERSION_NEEDED_DEFLATE
        );

        $fixedHeader = substr($binary, 0, ZipFormat::LOCAL_HEADER_FIXED_SIZE);
        $data = unpack('Vsig/vversion/vflag/vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen', $fixedHeader);

        $this->assertEquals(ZipFormat::SIG_LOCAL_FILE_HEADER, $data['sig']);
        $this->assertEquals(ZipFormat::VERSION_NEEDED_DEFLATE, $data['version']);
        $this->assertEquals(ZipFormat::COMPRESSION_DEFLATE, $data['method']);
        $this->assertEquals(0x1234, $data['time']);
        $this->assertEquals(0xABCDEF, $data['crc']);
        $this->assertEquals(100, $data['comp']);
        $this->assertEquals(200, $data['uncomp']);
        $this->assertEquals(strlen($filename), $data['nameLen']);
        $this->assertEquals(0, $data['extraLen']);

        $this->assertEquals($filename, substr($binary, ZipFormat::LOCAL_HEADER_FIXED_SIZE));
    }

    public function test_build_local_file_header_direct_zip64(): void
    {
        $binary = ZipFormat::buildLocalFileHeaderDirect(
            'test.txt',
            ZipFormat::COMPRESSION_DEFLATE,
            0x1234,
            0xABCDEF,
            ZipFormat::ZIP64_THRESHOLD + 1,
            ZipFormat::ZIP64_THRESHOLD + 2,
            0,
            ZipFormat::VERSION_NEEDED_ZIP64
        );

        $fixedHeader = substr($binary, 0, ZipFormat::LOCAL_HEADER_FIXED_SIZE);
        $data = unpack('Vsig/vversion/vflag/vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen', $fixedHeader);

        $this->assertEquals(ZipFormat::ZIP64_THRESHOLD, $data['comp']);
        $this->assertEquals(ZipFormat::ZIP64_THRESHOLD, $data['uncomp']);
    }

    public function test_build_local_file_header_with_data_descriptor(): void
    {
        // Standard
        $binary = ZipFormat::buildLocalFileHeaderWithDataDescriptor(
            'test.txt',
            ZipFormat::COMPRESSION_DEFLATE,
            0x1234,
            false
        );
        $fixedHeader = substr($binary, 0, ZipFormat::LOCAL_HEADER_FIXED_SIZE);
        $data = unpack('Vsig/vversion/vflag/vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen', $fixedHeader);

        $this->assertEquals(ZipFormat::SIG_LOCAL_FILE_HEADER, $data['sig']);
        $this->assertEquals(0, $data['crc']);
        $this->assertEquals(0, $data['comp']);
        $this->assertEquals(0, $data['uncomp']);
        $this->assertTrue(($data['flag'] & ZipFormat::FLAG_DATA_DESCRIPTOR) !== 0);

        // ZIP64
        $binary64 = ZipFormat::buildLocalFileHeaderWithDataDescriptor(
            'test.txt',
            ZipFormat::COMPRESSION_DEFLATE,
            0x1234,
            true
        );
        $fixedHeader64 = substr($binary64, 0, ZipFormat::LOCAL_HEADER_FIXED_SIZE);
        $data64 = unpack('Vsig/vversion/vflag/vmethod/Vtime/Vcrc/Vcomp/Vuncomp/vnameLen/vextraLen', $fixedHeader64);

        $this->assertEquals(ZipFormat::VERSION_NEEDED_ZIP64, $data64['version']);
        $this->assertEquals(20, $data64['extraLen']);
    }
}
