<?php

declare(strict_types=1);

namespace BatchZipStream\Contracts;

use BatchZipStream\State\ArchiveState;
use BatchZipStream\State\FileEntryStore;

/**
 * Interface for persisting and loading archive state between batches.
 * 
 * This interface is designed for memory-efficient state management:
 * 1. Lightweight ArchiveState (~500 bytes) for session metadata
 * 2. FileEntryStore for memory-efficient entry storage (append-only)
 * 3. Session creation and loading as compound operations
 * 
 * Implementations may use:
 * - File-based storage (FileStatePersistence)
 * - Database storage with a separate entries table
 * - Redis with streaming entry storage
 * - Cloud object storage (S3, GCS, Azure Blob)
 * 
 * All implementations MUST:
 * - Guarantee atomic state saves (no partial writes)
 * - Throw on save/load failures (never return corrupt state)
 * - Support concurrent access safely (or explicitly reject it)
 * - Provide memory-efficient entry iteration (generator-based)
 */
interface StatePersistenceInterface
{
    /**
     * Create a new session with fresh state and entry store.
     * 
     * @param string $sessionId Unique session identifier
     * @return array{state: ArchiveState, entryStore: FileEntryStore}
     * @throws \BatchZipStream\Exceptions\StatePersistenceException On creation failure
     */
    public function create(string $sessionId): array;

    /**
     * Load an existing session with its state and entry store.
     * 
     * The entry store should be opened in append mode for resumed sessions.
     * 
     * @param string $sessionId Unique session identifier
     * @return array{state: ArchiveState, entryStore: FileEntryStore}|null Session data if exists, null if not found
     * @throws \BatchZipStream\Exceptions\StatePersistenceException On load failure (corrupt data)
     */
    public function load(string $sessionId): ?array;

    /**
     * Save state only (entries are saved incrementally via FileEntryStore).
     * 
     * @param string $sessionId Unique session identifier
     * @param ArchiveState $state State to persist
     * @throws \BatchZipStream\Exceptions\StatePersistenceException On save failure
     */
    public function save(string $sessionId, ArchiveState $state): void;

    /**
     * Get the entry store for a session.
     * 
     * @param string $sessionId Unique session identifier
     * @return FileEntryStore|null The entry store if session is loaded
     */
    public function getEntryStore(string $sessionId): ?FileEntryStore;

    /**
     * Delete session and all its files/data (cleanup after completion or failure).
     * 
     * @param string $sessionId Unique session identifier
     * @throws \BatchZipStream\Exceptions\StatePersistenceException On delete failure
     */
    public function delete(string $sessionId): void;

    /**
     * Check if a session exists.
     * 
     * @param string $sessionId Unique session identifier
     * @return bool True if session state exists
     */
    public function exists(string $sessionId): bool;

    /**
     * Acquire a lock for the session to prevent concurrent modifications.
     * 
     * @param string $sessionId Unique session identifier
     * @param int $timeoutSeconds Maximum time to wait for lock
     * @return bool True if lock acquired
     */
    public function acquireLock(string $sessionId, int $timeoutSeconds = 30): bool;

    /**
     * Release the lock for the session.
     * 
     * @param string $sessionId Unique session identifier
     */
    public function releaseLock(string $sessionId): void;

    /**
     * List all session IDs.
     * 
     * @return string[] Array of session identifiers
     */
    public function listSessions(): array;

    /**
     * Clean up old or failed sessions.
     * 
     * @param int $maxAgeSeconds Maximum age in seconds for stale sessions
     * @return int Number of sessions cleaned up
     */
    public function cleanup(int $maxAgeSeconds): int;

    /**
     * Get storage statistics for a session.
     * 
     * @param string $sessionId Unique session identifier
     * @return array{stateSize: int, entryStoreSize: int, totalSize: int}
     */
    public function getStorageStats(string $sessionId): array;
}
