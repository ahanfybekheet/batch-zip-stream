<?php

declare(strict_types=1);

namespace BatchZipStream\Core\Crypto;

/**
 * Interface for stream-based ZIP encryption engines.
 */
interface CryptoEngineInterface
{
    /**
     * Get the encryption header to be written before the compressed data.
     * 
     * @return string Binary header data
     */
    public function getHeader(): string;

    /**
     * Encrypt a chunk of compressed data.
     * 
     * @param string $data Raw compressed data chunk
     * @return string Encrypted data chunk
     */
    public function encryptChunk(string $data): string;

    /**
     * Get the encryption footer (e.g., MAC or auth code) to be written after the compressed data.
     * 
     * @return string Binary footer data
     */
    public function getFooter(): string;
}
