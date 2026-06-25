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
 * Tests for Data Descriptor packing inside ZipFormat.
 */
final class DataDescriptorTest extends TestCase
{
    public function test_build_data_descriptor_32_bit(): void
    {
        $crc = 0x12345678;
        $comp = 5000;
        $uncomp = 10000;

        $binary = ZipFormat::buildDataDescriptor($crc, $comp, $uncomp, false);

        $this->assertEquals(16, strlen($binary));
        $data = unpack('Vsig/Vcrc/Vcomp/Vuncomp', $binary);

        $this->assertEquals(ZipFormat::SIG_DATA_DESCRIPTOR, $data['sig']);
        $this->assertEquals($crc, $data['crc']);
        $this->assertEquals($comp, $data['comp']);
        $this->assertEquals($uncomp, $data['uncomp']);
    }

    public function test_build_data_descriptor_64_bit(): void
    {
        $crc = 0x12345678;
        $comp = 0x100000000; // > 4GB
        $uncomp = 0x200000000;

        $binary = ZipFormat::buildDataDescriptor($crc, $comp, $uncomp, true);

        $this->assertEquals(24, strlen($binary));
        $data = unpack('Vsig/Vcrc/Pcomp/Puncomp', $binary);

        $this->assertEquals(ZipFormat::SIG_DATA_DESCRIPTOR, $data['sig']);
        $this->assertEquals($crc, $data['crc']);
        $this->assertEquals($comp, $data['comp']);
        $this->assertEquals($uncomp, $data['uncomp']);
    }

    public function test_get_data_descriptor_size(): void
    {
        $this->assertEquals(16, ZipFormat::getDataDescriptorSize(false));
        $this->assertEquals(24, ZipFormat::getDataDescriptorSize(true));
    }
}
