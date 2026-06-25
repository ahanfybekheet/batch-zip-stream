<?php

declare(strict_types=1);

namespace BatchZipStream\Core\Crypto;

/**
 * WinZip AE-2 (AES-256) Zip Crypto engine.
 * 
 * Implements AES-256 in CTR mode and HMAC-SHA1 authentication.
 */
final class WinZipAesCrypto implements CryptoEngineInterface
{
    private string $encKey;
    private string $macKey;
    private string $header;

    /** @var \HashContext|resource HMAC-SHA1 context */
    private $hmacContext;

    private int $counter = 1;
    private string $keyStreamBuffer = '';

    /**
     * @param string $password The password
     * @throws \RuntimeException If openssl extension is missing
     */
    public function __construct(string $password)
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('The openssl extension is required for WinZip AES encryption');
        }

        // WinZip AES-256 uses a 16-byte salt
        $salt = random_bytes(16);

        // Derive keys using PBKDF2 HMAC-SHA1 with 1000 iterations
        // AES-256 requires 66 bytes total: 32 (enc) + 32 (mac) + 2 (pvv)
        $derived = hash_pbkdf2('sha1', $password, $salt, 1000, 66, true);

        $this->encKey = substr($derived, 0, 32);
        $this->macKey = substr($derived, 32, 32);
        $pvv = substr($derived, 64, 2);

        // Header is Salt + Password Verification Value (PVV)
        $this->header = $salt . $pvv;

        // Initialize HMAC (calculated over encrypted data stream)
        $this->hmacContext = hash_init('sha1', HASH_HMAC, $this->macKey);
    }

    public function getHeader(): string
    {
        return $this->header;
    }

    public function encryptChunk(string $data): string
    {
        $length = strlen($data);
        if ($length === 0) {
            return '';
        }

        $result = '';
        $dataOffset = 0;

        // Use up remaining keystream buffer
        $bufferLen = strlen($this->keyStreamBuffer);
        if ($bufferLen > 0) {
            $take = min($bufferLen, $length);
            $result .= $this->xorStrings(
                substr($data, 0, $take),
                substr($this->keyStreamBuffer, 0, $take)
            );
            $this->keyStreamBuffer = substr($this->keyStreamBuffer, $take);
            $dataOffset += $take;
            $length -= $take;
        }

        // Process full 16-byte blocks
        while ($length >= 16) {
            $keyStream = $this->getNextKeyStreamBlock();
            $result .= $this->xorStrings(
                substr($data, $dataOffset, 16),
                $keyStream
            );
            $dataOffset += 16;
            $length -= 16;
        }

        // Process remaining bytes (less than 16)
        if ($length > 0) {
            $this->keyStreamBuffer = $this->getNextKeyStreamBlock();
            $result .= $this->xorStrings(
                substr($data, $dataOffset, $length),
                substr($this->keyStreamBuffer, 0, $length)
            );
            $this->keyStreamBuffer = substr($this->keyStreamBuffer, $length);
        }

        // WinZip AES: HMAC is calculated over the ENCRYPTED data stream
        hash_update($this->hmacContext, $result);

        return $result;
    }

    public function getFooter(): string
    {
        // WinZip AES AE-2 requires 10-byte authentication code (MAC)
        $mac = hash_final($this->hmacContext, true);
        return substr($mac, 0, 10);
    }

    private function getNextKeyStreamBlock(): string
    {
        // Counter is 16 bytes, little endian.
        // We only use the lower 8 bytes (enough for a 147 EB stream).
        $counterBytes = pack('P', $this->counter) . str_repeat("\x00", 8);
        $this->counter++;

        return openssl_encrypt(
            $counterBytes,
            'aes-256-ecb',
            $this->encKey,
            OPENSSL_RAW_DATA | OPENSSL_NO_PADDING
        );
    }

    private function xorStrings(string $str1, string $str2): string
    {
        return $str1 ^ $str2;
    }
}
