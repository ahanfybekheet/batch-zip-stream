<?php

declare(strict_types=1);

namespace BatchZipStream\State;

/**
 * Complete archive state for batch persistence - Memory-Efficient.
 * 
 * This class holds all information needed to:
 * 1. Resume ZIP creation from any batch
 * 2. Generate the Central Directory (via FileEntryStore)
 * 3. Validate archive integrity
 * 
 * ## Key Design Characteristics
 * 
 * - File entries are stored in a separate FileEntryStore (not in memory)
 * - This state file remains small (<1KB) regardless of archive size
 * - Memory usage is O(1) instead of O(n)
 * 
 * CRITICAL: This is the ONLY class that should be persisted between batches.
 * ZIP writers, streams, and deflate contexts MUST NOT be serialized.
 */
class ArchiveState
{
    // Archive phases
    public const PHASE_INITIALIZING = 'initializing';
    public const PHASE_ADDING_FILES = 'adding_files';
    public const PHASE_FINALIZING = 'finalizing';
    public const PHASE_COMPLETED = 'completed';
    public const PHASE_FAILED = 'failed';

    // State format version for migration support
    public const FORMAT_VERSION = 2;

    /** @var string Session ID */
    private string $sessionId;

    /** @var string Current phase */
    private string $phase = self::PHASE_INITIALIZING;

    /** @var int Current write offset (low 32 bits) */
    private int $currentOffset = 0;

    /** @var int Current write offset (high 32 bits for ZIP64) */
    private int $currentOffsetHigh = 0;

    /** @var int Total files added */
    private int $fileCount = 0;

    /** @var int Total uncompressed bytes */
    private int $totalUncompressedBytes = 0;

    /** @var int Total compressed bytes */
    private int $totalCompressedBytes = 0;

    /** @var bool Whether ZIP64 is required */
    private bool $requiresZip64Flag = false;

    /** @var int Creation timestamp */
    private int $createdAt;

    /** @var int Last update timestamp */
    private int $updatedAt;

    /** @var string|null Failure reason if phase is FAILED */
    private ?string $failureReason = null;

    /** @var int Batch number (1-indexed) */
    private int $batchNumber = 0;

    /** @var string Path to the entry store file (relative to state directory) */
    private string $entryStoreFilename;

    /** @var array<string, mixed> Custom metadata */
    private array $metadata = [];

    public function __construct(string $sessionId)
    {
        $this->sessionId = $sessionId;
        $this->createdAt = time();
        $this->updatedAt = time();
        $this->entryStoreFilename = $sessionId . '.entries';
    }

    // ==================== State Accessors ====================

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getCurrentOffset(): int
    {
        return $this->currentOffset;
    }

    public function getCurrentOffsetHigh(): int
    {
        return $this->currentOffsetHigh;
    }

    /**
     * Get full 64-bit offset (PHP 64-bit only).
     */
    public function getFullOffset(): int
    {
        if (PHP_INT_SIZE >= 8) {
            return ($this->currentOffsetHigh << 32) | $this->currentOffset;
        }
        return $this->currentOffset;
    }

    public function getFileCount(): int
    {
        return $this->fileCount;
    }

    public function getTotalUncompressedBytes(): int
    {
        return $this->totalUncompressedBytes;
    }

    public function getTotalCompressedBytes(): int
    {
        return $this->totalCompressedBytes;
    }

    public function requiresZip64(): bool
    {
        return $this->requiresZip64Flag;
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): int
    {
        return $this->updatedAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getBatchNumber(): int
    {
        return $this->batchNumber;
    }

    public function getEntryStoreFilename(): string
    {
        return $this->entryStoreFilename;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    // ==================== State Mutators ====================

    /**
     * Update the current write offset.
     */
    public function updateOffset(int $bytesWritten): void
    {
        $newOffset = $this->currentOffset + $bytesWritten;

        // Handle 32-bit overflow
        if ($newOffset < $this->currentOffset) {
            $this->currentOffsetHigh++;
            $this->requiresZip64Flag = true;
        }

        $this->currentOffset = $newOffset;

        // Check if we've crossed the 4GB boundary
        if ($this->currentOffsetHigh > 0 || $this->currentOffset > 0xFFFFFFFF) {
            $this->requiresZip64Flag = true;
        }

        $this->updatedAt = time();
    }

    /**
     * Record a file addition (updates counts, not entries).
     */
    public function recordFileAdded(
        int $compressedSize,
        int $uncompressedSize,
        bool $entryRequiresZip64
    ): void {
        $this->fileCount++;
        $this->totalCompressedBytes += $compressedSize;
        $this->totalUncompressedBytes += $uncompressedSize;

        if ($entryRequiresZip64) {
            $this->requiresZip64Flag = true;
        }

        // Check if we need ZIP64 due to file count
        if ($this->fileCount >= 65535) {
            $this->requiresZip64Flag = true;
        }

        $this->phase = self::PHASE_ADDING_FILES;
        $this->updatedAt = time();
    }

    /**
     * Increment file count only (when sizes are tracked separately).
     */
    public function incrementFileCount(): void
    {
        $this->fileCount++;

        // Check if we need ZIP64 due to file count
        if ($this->fileCount >= 65535) {
            $this->requiresZip64Flag = true;
        }

        $this->phase = self::PHASE_ADDING_FILES;
        $this->updatedAt = time();
    }

    /**
     * Mark ZIP64 as required.
     */
    public function markZip64Required(): void
    {
        $this->requiresZip64Flag = true;
        $this->updatedAt = time();
    }

    /**
     * Transition to finalizing phase.
     */
    public function startFinalization(): void
    {
        $this->assertPhase([self::PHASE_ADDING_FILES], 'startFinalization');
        $this->phase = self::PHASE_FINALIZING;
        $this->updatedAt = time();
    }

    /**
     * Mark archive as completed successfully.
     */
    public function complete(): void
    {
        $this->assertPhase([self::PHASE_FINALIZING], 'complete');
        $this->phase = self::PHASE_COMPLETED;
        $this->updatedAt = time();
    }

    /**
     * Mark archive as failed.
     */
    public function fail(string $reason): void
    {
        $this->phase = self::PHASE_FAILED;
        $this->failureReason = $reason;
        $this->updatedAt = time();
    }

    /**
     * Increment batch number.
     */
    public function incrementBatch(): void
    {
        $this->batchNumber++;
        $this->updatedAt = time();
    }

    /**
     * Check if archive can accept more files.
     */
    public function canAddFiles(): bool
    {
        return in_array($this->phase, [self::PHASE_INITIALIZING, self::PHASE_ADDING_FILES], true);
    }

    /**
     * Check if archive can be finalized.
     */
    public function canFinalize(): bool
    {
        return $this->phase === self::PHASE_ADDING_FILES && $this->fileCount > 0;
    }

    /**
     * Check if archive is in failed state.
     */
    public function isFailed(): bool
    {
        return $this->phase === self::PHASE_FAILED;
    }

    /**
     * Check if archive is completed.
     */
    public function isCompleted(): bool
    {
        return $this->phase === self::PHASE_COMPLETED;
    }

    /**
     * Serialize to array for persistence.
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => self::FORMAT_VERSION,
            'sessionId' => $this->sessionId,
            'phase' => $this->phase,
            'currentOffset' => $this->currentOffset,
            'currentOffsetHigh' => $this->currentOffsetHigh,
            'fileCount' => $this->fileCount,
            'totalUncompressedBytes' => $this->totalUncompressedBytes,
            'totalCompressedBytes' => $this->totalCompressedBytes,
            'requiresZip64' => $this->requiresZip64Flag,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'failureReason' => $this->failureReason,
            'batchNumber' => $this->batchNumber,
            'entryStoreFilename' => $this->entryStoreFilename,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Serialize to JSON.
     */
    public function toJson(bool $pretty = false): string
    {
        $flags = JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($this->toArray(), $flags);
    }

    /**
     * Restore from array.
     */
    public static function fromArray(array $data): self
    {
        $state = new self($data['sessionId']);
        $state->phase = $data['phase'];
        $state->currentOffset = (int) $data['currentOffset'];
        $state->currentOffsetHigh = (int) ($data['currentOffsetHigh'] ?? 0);
        $state->fileCount = (int) $data['fileCount'];
        $state->totalUncompressedBytes = (int) $data['totalUncompressedBytes'];
        $state->totalCompressedBytes = (int) $data['totalCompressedBytes'];
        $state->requiresZip64Flag = (bool) $data['requiresZip64'];
        $state->createdAt = (int) $data['createdAt'];
        $state->updatedAt = (int) $data['updatedAt'];
        $state->failureReason = $data['failureReason'] ?? null;
        $state->batchNumber = (int) ($data['batchNumber'] ?? 0);
        $state->entryStoreFilename = $data['entryStoreFilename'] ?? ($data['sessionId'] . '.entries');
        $state->metadata = $data['metadata'] ?? [];

        return $state;
    }

    /**
     * Restore from JSON.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return self::fromArray($data);
    }

    /**
     * Assert that the archive is in one of the expected phases.
     */
    private function assertPhase(array $allowedPhases, string $operation): void
    {
        if (!in_array($this->phase, $allowedPhases, true)) {
            throw new \LogicException(sprintf(
                'Cannot perform %s in phase "%s". Allowed phases: %s',
                $operation,
                $this->phase,
                implode(', ', $allowedPhases)
            ));
        }
    }
}
