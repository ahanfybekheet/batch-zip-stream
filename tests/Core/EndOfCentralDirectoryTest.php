<?php

declare(strict_types=1);

namespace BatchZipStream\Tests\Core;

use BatchZipStream\Core\ZipFormat;
use PHPUnit\Framework\TestCase;

$libraryAutoloader = __DIR__ . '/../../autoload.php';
if (file_exists($libraryAutoloader)) {
    require_once $libraryAutoloader;
}

/**
 * Tests for End of Central Directory packing inside ZipFormat.
 */
final class EndOfCentralDirectoryTest extends TestCase
{
    public function test_build_end_of_central_directory(): void
    {
        $comment = 'Hello Archive';
        $binary = ZipFormat::buildEndOfCentralDirectory(5, 500, 1000, $comment);

        $fixedPart = substr($binary, 0, ZipFormat::EOCD_FIXED_SIZE);
        $data = unpack('Vsig/vdisk/vdiskCdr/ventriesDisk/ventries/Vsize/Voffset/vcommentLen', $fixedPart);

        $this->assertEquals(ZipFormat::SIG_END_OF_CENTRAL_DIR, $data['sig']);
        $this->assertEquals(5, $data['entriesDisk']);
        $this->assertEquals(5, $data['entries']);
        $this->assertEquals(500, $data['size']);
        $this->assertEquals(1000, $data['offset']);
        $this->assertEquals(strlen($comment), $data['commentLen']);
        $this->assertEquals($comment, substr($binary, ZipFormat::EOCD_FIXED_SIZE));
    }

    public function test_build_zip64_end_of_central_directory_and_locator(): void
    {
        $binaryEocd = ZipFormat::buildZip64EndOfCentralDirectory(100, 10000, 20000);
        $this->assertEquals(ZipFormat::ZIP64_EOCD_FIXED_SIZE, strlen($binaryEocd));

        $dataEocd = unpack('Vsig/Psize/vmadeBy/vneeded/Vdisk/VdiskCdr/PentriesDisk/Pentries/PsizeCdr/PoffsetCdr', $binaryEocd);
        $this->assertEquals(ZipFormat::SIG_ZIP64_END_OF_CENTRAL_DIR, $dataEocd['sig']);
        $this->assertEquals(44, $dataEocd['size']);
        $this->assertEquals(100, $dataEocd['entries']);
        $this->assertEquals(10000, $dataEocd['sizeCdr']);
        $this->assertEquals(20000, $dataEocd['offsetCdr']);

        $binaryLocator = ZipFormat::buildZip64EndOfCentralDirectoryLocator(50000);
        $this->assertEquals(ZipFormat::ZIP64_EOCD_LOCATOR_SIZE, strlen($binaryLocator));

        $dataLocator = unpack('Vsig/Vdisk/PeocdOffset/Vdisks', $binaryLocator);
        $this->assertEquals(ZipFormat::SIG_ZIP64_END_OF_CENTRAL_DIR_LOCATOR, $dataLocator['sig']);
        $this->assertEquals(50000, $dataLocator['eocdOffset']);
    }
}
