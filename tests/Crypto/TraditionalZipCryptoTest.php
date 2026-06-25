<?php

declare(strict_types=1);

namespace BatchZipStream\Tests\Crypto;

use PHPUnit\Framework\TestCase;
use BatchZipStream\Core\Crypto\TraditionalZipCrypto;

class TraditionalZipCryptoTest extends TestCase
{
    public function testEncryptionGeneratesHeader(): void
    {
        $crypto = new TraditionalZipCrypto('secret', 0);
        $header = $crypto->getHeader();
        
        $this->assertEquals(12, strlen($header));
    }

    public function testEncryptionModifiesChunk(): void
    {
        $crypto = new TraditionalZipCrypto('secret', 0);
        
        $plaintext = 'Hello, World!';
        $encrypted = $crypto->encryptChunk($plaintext);
        
        $this->assertEquals(strlen($plaintext), strlen($encrypted));
        $this->assertNotEquals($plaintext, $encrypted);
    }

    public function testGetFooterIsEmpty(): void
    {
        $crypto = new TraditionalZipCrypto('secret', 0);
        $this->assertEquals('', $crypto->getFooter());
    }

    public function testSamePasswordDifferentStateProducesDifferentCiphertext(): void
    {
        $crypto1 = new TraditionalZipCrypto('secret', 0);
        $crypto2 = new TraditionalZipCrypto('secret', 0);
        
        $plaintext = 'test data';
        
        $enc1 = $crypto1->encryptChunk($plaintext);
        $enc2 = $crypto2->encryptChunk($plaintext);
        
        // Due to different random headers, the internal state will be different,
        // producing different ciphertexts for the same plaintext.
        $this->assertNotEquals($enc1, $enc2);
    }
}
