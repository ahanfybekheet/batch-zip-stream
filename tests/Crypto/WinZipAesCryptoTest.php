<?php

declare(strict_types=1);

namespace BatchZipStream\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use BatchZipStream\Core\Crypto\WinZipAesCrypto;

class WinZipAesCryptoTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('The openssl extension is not available.');
        }
    }

    public function testEncryptionGeneratesHeader(): void
    {
        $crypto = new WinZipAesCrypto('secret');
        $header = $crypto->getHeader();
        
        // Salt (16) + PVV (2) = 18 bytes
        $this->assertEquals(18, strlen($header));
    }

    public function testEncryptionModifiesChunk(): void
    {
        $crypto = new WinZipAesCrypto('secret');
        
        $plaintext = 'Hello, World!';
        $encrypted = $crypto->encryptChunk($plaintext);
        
        $this->assertEquals(strlen($plaintext), strlen($encrypted));
        $this->assertNotEquals($plaintext, $encrypted);
    }

    public function testGetFooterGeneratesMac(): void
    {
        $crypto = new WinZipAesCrypto('secret');
        
        $plaintext = 'Hello, World!';
        $crypto->encryptChunk($plaintext);
        
        $footer = $crypto->getFooter();
        // WinZip AES AE-2 uses 10-byte MAC
        $this->assertEquals(10, strlen($footer));
    }
}
