<?php

declare(strict_types=1);

namespace BatchZipStream\Core\Crypto;

/**
 * Traditional PKWARE ZipCrypto engine.
 * 
 * Note: This encryption is considered cryptographically weak.
 */
final class TraditionalZipCrypto implements CryptoEngineInterface
{
    private int $key0 = 0x12345678;
    private int $key1 = 0x23456789;
    private int $key2 = 0x34567890;

    /** @var int[] CRC32 table */
    private static array $crcTable = [];

    private string $header = '';

    /**
     * @param string $password The password
     * @param int $dosTime DOS timestamp (for the 12th byte of the header)
     */
    public function __construct(string $password, int $dosTime)
    {
        if (empty(self::$crcTable)) {
            self::initCrcTable();
        }

        $this->initKeys($password);

        // Generate 12-byte random header
        $header = random_bytes(11);
        
        // 12th byte must be the high byte of the DOS modification time (when bit 3 is set)
        $timeHighByte = ($dosTime >> 8) & 0xff;
        $header .= chr($timeHighByte);

        // Encrypt the header
        $this->header = $this->encryptChunk($header);
    }

    private static function initCrcTable(): void
    {
        for ($i = 0; $i < 256; $i++) {
            $c = $i;
            for ($j = 0; $j < 8; $j++) {
                if (($c & 1) !== 0) {
                    $c = 0xEDB88320 ^ (($c >> 1) & 0x7FFFFFFF);
                } else {
                    $c = ($c >> 1) & 0x7FFFFFFF;
                }
            }
            self::$crcTable[$i] = $c;
        }
    }

    private function crc32(int $crc, int $byte): int
    {
        return self::$crcTable[($crc ^ $byte) & 0xff] ^ (($crc >> 8) & 0xFFFFFF);
    }

    private function initKeys(string $password): void
    {
        $this->key0 = 0x12345678;
        $this->key1 = 0x23456789;
        $this->key2 = 0x34567890;

        $length = strlen($password);
        for ($i = 0; $i < $length; $i++) {
            $this->updateKeys(ord($password[$i]));
        }
    }

    private function updateKeys(int $byte): void
    {
        $this->key0 = $this->crc32($this->key0, $byte);
        
        // Use 32-bit arithmetic for key1
        $this->key1 = ($this->key1 + ($this->key0 & 0xff)) & 0xffffffff;
        $this->key1 = ($this->key1 * 134775813 + 1) & 0xffffffff;
        
        $this->key2 = $this->crc32($this->key2, ($this->key1 >> 24) & 0xff);
    }

    private function decryptByte(): int
    {
        $temp = ($this->key2 | 2) & 0xffff;
        return (($temp * ($temp ^ 1)) >> 8) & 0xff;
    }

    public function getHeader(): string
    {
        return $this->header;
    }

    public function encryptChunk(string $data): string
    {
        $result = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $plainByte = ord($data[$i]);
            $cipherByte = $plainByte ^ $this->decryptByte();
            $this->updateKeys($plainByte);
            $result .= chr($cipherByte);
        }
        return $result;
    }

    public function getFooter(): string
    {
        return '';
    }
}
