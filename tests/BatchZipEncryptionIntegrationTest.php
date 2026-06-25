<?php

declare(strict_types=1);

namespace BatchZipStream\Tests;

use PHPUnit\Framework\TestCase;
use BatchZipStream\BatchZipSession;
use BatchZipStream\Core\ZipFormat;
use ZipArchive;

class BatchZipEncryptionIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/batch-zip-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    public function testTraditionalEncryption(): void
    {
        $zipPath = $this->tempDir . '/traditional.zip';
        $stateDir = $this->tempDir . '/state-trad';
        mkdir($stateDir);

        $password = 'secret123';
        $session = new BatchZipSession($stateDir, $zipPath, 6, 65536, null, $password);
        $session->startSession('trad-test');

        // Test encrypted file
        $session->addFileFromString('hello.txt', 'Hello, World!', ZipFormat::COMPRESSION_DEFLATE, ZipFormat::ENC_TRADITIONAL);
        
        // Test non-encrypted file in the same archive
        $session->addFileFromString('public.txt', 'Public Data', ZipFormat::COMPRESSION_DEFLATE, ZipFormat::ENC_NONE);

        // Test encrypted file with different password
        $session->getWriter()->addFileFromString(
            'override.txt', 
            'Overridden Password', 
            ZipFormat::COMPRESSION_DEFLATE, 
            null, 
            ZipFormat::ENC_TRADITIONAL, 
            'override456'
        );

        $session->finalize();

        // Verify with native ZipArchive
        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        $this->assertTrue($res === true, 'Failed to open zip archive');

        // Test public file
        $this->assertEquals('Public Data', $zip->getFromName('public.txt'));

        // Test encrypted file with global password
        $zip->setPassword($password);
        $this->assertEquals('Hello, World!', $zip->getFromName('hello.txt'));

        // Test encrypted file with overridden password
        $zip->setPassword('override456');
        $this->assertEquals('Overridden Password', $zip->getFromName('override.txt'));

        $zip->close();
    }

    public function testWinZipAesEncryption(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('The openssl extension is not available.');
        }

        // WinZip AES requires libzip >= 1.2.0 for AES support in ZipArchive
        // and PHP >= 7.2
        $zipPath = $this->tempDir . '/aes.zip';
        $stateDir = $this->tempDir . '/state-aes';
        mkdir($stateDir);

        $password = 'strong_password';
        $session = new BatchZipSession($stateDir, $zipPath, 6, 65536, null, $password);
        $session->startSession('aes-test');

        // Test encrypted file
        $session->addFileFromString('secret.txt', 'Top Secret Data', ZipFormat::COMPRESSION_DEFLATE, ZipFormat::ENC_AES_256);

        // Test encrypted STORE file (no compression)
        $session->addFileFromString('store.txt', 'Uncompressed Secret', ZipFormat::COMPRESSION_STORE, ZipFormat::ENC_AES_256);

        $session->finalize();

        // Verify with native ZipArchive
        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        $this->assertTrue($res === true, 'Failed to open zip archive');

        $zip->setPassword($password);
        $this->assertEquals('Top Secret Data', $zip->getFromName('secret.txt'));
        $this->assertEquals('Uncompressed Secret', $zip->getFromName('store.txt'));

        $zip->close();
    }
}
