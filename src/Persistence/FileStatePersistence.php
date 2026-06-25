<?php

declare(strict_types=1);

namespace BatchZipStream\Persistence;

use BatchZipStream\Contracts\StatePersistenceInterface;
use BatchZipStream\State\ArchiveState;
use BatchZipStream\State\FileEntryStore;
use BatchZipStream\Exceptions\StatePersistenceException;

/**
 * File-based state persistence implementation - Memory-Efficient.
 * 
 * This solves the memory exhaustion problem by:
 * 1. Keeping state in a small JSON file (<1KB)
 * 2. Storing file entries in a separate append-only file
 * 3. Never loading all entries into memory
 * 
 * Uses atomic writes (write to temp file, then rename) to prevent corruption.
 * Uses flock() for single-machine deployments.
 * 
 * File structure per session:
 * - {sessionId}.json     - Small state file
 * - {sessionId}.entries  - Append-only file entries (line-delimited JSON)
 * - {sessionId}.lock     - Lock file for concurrency
 */
final class FileStatePersistence implements StatePersistenceInterface
{
    private string $stateDirectory;

    /** @var array<string, resource> Active lock file handles */
    private array $lockHandles = [];

    /** @var array<string, FileEntryStore> Open entry stores */
    private array $entryStores = [];

    /**
     * Create file-based state persistence.
     * 
     * @param string $stateDirectory Directory to store state files
     * @throws StatePersistenceException If directory cannot be created
     */
    public function __construct(string $stateDirectory)
    {
        $this->stateDirectory = rtrim($stateDirectory, '/\\');

        // Ensure directory exists
        if (!is_dir($this->stateDirectory)) {
            if (!@mkdir($this->stateDirectory, 0755, true)) {
                throw new StatePersistenceException(
                    sprintf('Failed to create state directory: %s', $this->stateDirectory)
                );
            }
        }

        if (!is_writable($this->stateDirectory)) {
            throw new StatePersistenceException(
                sprintf('State directory is not writable: %s', $this->stateDirectory)
            );
        }
    }

    /**
     * Create a new session.
     * 
     * @param string $sessionId Session identifier
     * @return array{state: ArchiveState, entryStore: FileEntryStore}
     */
    public function create(string $sessionId): array
    {
        $this->validateSessionId($sessionId);

        $state = new ArchiveState($sessionId);
        $entryStore = new FileEntryStore($this->getEntryStorePath($sessionId));

        // Open entry store for writing (truncate any existing)
        $entryStore->open(true);

        // Save initial state
        $this->save($sessionId, $state);

        $this->entryStores[$sessionId] = $entryStore;

        return [
            'state' => $state,
            'entryStore' => $entryStore,
        ];
    }

    /**
     * Load an existing session.
     * 
     * @param string $sessionId Session identifier
     * @return array{state: ArchiveState, entryStore: FileEntryStore}|null
     */
    public function load(string $sessionId): ?array
    {
        $this->validateSessionId($sessionId);

        $statePath = $this->getStatePath($sessionId);
        if (!file_exists($statePath)) {
            return null;
        }

        try {
            $json = @file_get_contents($statePath);
            if ($json === false) {
                throw new StatePersistenceException(
                    sprintf('Failed to read state file: %s', $statePath),
                    $sessionId
                );
            }

            $state = ArchiveState::fromJson($json);
            $entryStore = new FileEntryStore($this->getEntryStorePath($sessionId));

            // Open entry store for appending
            $entryStore->open(false);

            $this->entryStores[$sessionId] = $entryStore;

            return [
                'state' => $state,
                'entryStore' => $entryStore,
            ];
        } catch (StatePersistenceException $e) {
            throw $e;
        } catch (\JsonException $e) {
            throw new StatePersistenceException(
                'Failed to parse state JSON: ' . $e->getMessage(),
                $sessionId,
                $e
            );
        }
    }

    /**
     * Save state (entries are saved incrementally).
     * 
     * @param string $sessionId Session identifier
     * @param ArchiveState $state State to save
     */
    public function save(string $sessionId, ArchiveState $state): void
    {
        $this->validateSessionId($sessionId);

        $filePath = $this->getStatePath($sessionId);
        $tempPath = $filePath . '.tmp.' . uniqid();

        try {
            $json = $state->toJson(true);
            $written = @file_put_contents($tempPath, $json, LOCK_EX);

            if ($written === false) {
                throw new StatePersistenceException(
                    sprintf('Failed to write state file: %s', $tempPath),
                    $sessionId
                );
            }

            // Verify written data
            $readBack = @file_get_contents($tempPath);
            if ($readBack !== $json) {
                throw new StatePersistenceException(
                    'State file verification failed: data mismatch',
                    $sessionId
                );
            }

            // Atomic rename
            if (!@rename($tempPath, $filePath)) {
                throw new StatePersistenceException(
                    sprintf('Failed to rename state file: %s -> %s', $tempPath, $filePath),
                    $sessionId
                );
            }
        } catch (StatePersistenceException $e) {
            @unlink($tempPath);
            throw $e;
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw new StatePersistenceException(
                'Failed to save state: ' . $e->getMessage(),
                $sessionId,
                $e
            );
        }
    }

    /**
     * Get the entry store for a session.
     * 
     * @param string $sessionId Session identifier
     * @return FileEntryStore|null
     */
    public function getEntryStore(string $sessionId): ?FileEntryStore
    {
        return $this->entryStores[$sessionId] ?? null;
    }

    /**
     * Delete a session and all its files.
     * 
     * @param string $sessionId Session identifier
     */
    public function delete(string $sessionId): void
    {
        $this->validateSessionId($sessionId);

        // Close and remove entry store
        if (isset($this->entryStores[$sessionId])) {
            $this->entryStores[$sessionId]->delete();
            unset($this->entryStores[$sessionId]);
        }

        // Release locks
        $this->releaseLock($sessionId);

        // Delete files
        $files = [
            $this->getStatePath($sessionId),
            $this->getEntryStorePath($sessionId),
            $this->getLockPath($sessionId),
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Check if a session exists.
     * 
     * @param string $sessionId Session identifier
     * @return bool
     */
    public function exists(string $sessionId): bool
    {
        $this->validateSessionId($sessionId);
        return file_exists($this->getStatePath($sessionId));
    }

    /**
     * Acquire a lock for the session.
     * 
     * @param string $sessionId Session identifier
     * @param int $timeoutSeconds Maximum time to wait for lock
     * @return bool True if lock acquired
     */
    public function acquireLock(string $sessionId, int $timeoutSeconds = 30): bool
    {
        $this->validateSessionId($sessionId);

        $lockPath = $this->getLockPath($sessionId);
        $handle = @fopen($lockPath, 'c');

        if ($handle === false) {
            return false;
        }

        $startTime = time();
        while (true) {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                ftruncate($handle, 0);
                fwrite($handle, json_encode([
                    'pid' => function_exists('getmypid') ? getmypid() : 0,
                    'time' => time(),
                    'session' => $sessionId
                ]));
                fflush($handle);

                $this->lockHandles[$sessionId] = $handle;
                return true;
            }

            if (time() - $startTime >= $timeoutSeconds) {
                fclose($handle);
                return false;
            }

            usleep(100000); // 100ms
        }
    }

    /**
     * Release the lock for the session.
     * 
     * @param string $sessionId Session identifier
     */
    public function releaseLock(string $sessionId): void
    {
        if (!isset($this->lockHandles[$sessionId])) {
            return;
        }

        $handle = $this->lockHandles[$sessionId];
        @flock($handle, LOCK_UN);
        @fclose($handle);
        unset($this->lockHandles[$sessionId]);
    }

    /**
     * Close all open entry stores (call at end of batch).
     */
    public function closeAll(): void
    {
        foreach ($this->entryStores as $store) {
            $store->close();
        }
        $this->entryStores = [];
    }

    /**
     * List all session IDs.
     * 
     * @return string[] Session IDs
     */
    public function listSessions(): array
    {
        $sessions = [];
        $pattern = $this->stateDirectory . '/*.json';

        foreach (glob($pattern) as $file) {
            $sessions[] = basename($file, '.json');
        }

        return $sessions;
    }

    /**
     * Clean up old or failed sessions.
     * 
     * @param int $maxAgeSeconds Maximum age in seconds
     * @return int Number of sessions cleaned
     */
    public function cleanup(int $maxAgeSeconds): int
    {
        $cleaned = 0;
        $threshold = time() - $maxAgeSeconds;

        foreach ($this->listSessions() as $sessionId) {
            try {
                $session = $this->load($sessionId);
                if ($session === null) {
                    continue;
                }

                $state = $session['state'];
                $session['entryStore']->close();

                if ($state->getUpdatedAt() < $threshold || $state->isFailed()) {
                    $this->delete($sessionId);
                    $cleaned++;
                }
            } catch (\Throwable $e) {
                // Try to delete corrupted session
                $this->delete($sessionId);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Get storage statistics.
     * 
     * @param string $sessionId Session identifier
     * @return array{stateSize: int, entryStoreSize: int, totalSize: int}
     */
    public function getStorageStats(string $sessionId): array
    {
        $stateSize = 0;
        $entryStoreSize = 0;

        $statePath = $this->getStatePath($sessionId);
        if (file_exists($statePath)) {
            $stateSize = (int) filesize($statePath);
        }

        $entryStorePath = $this->getEntryStorePath($sessionId);
        if (file_exists($entryStorePath)) {
            $entryStoreSize = (int) filesize($entryStorePath);
        }

        return [
            'stateSize' => $stateSize,
            'entryStoreSize' => $entryStoreSize,
            'totalSize' => $stateSize + $entryStoreSize,
        ];
    }

    // ==================== Path Helpers ====================

    private function getStatePath(string $sessionId): string
    {
        return $this->stateDirectory . '/' . $sessionId . '.json';
    }

    private function getEntryStorePath(string $sessionId): string
    {
        return $this->stateDirectory . '/' . $sessionId . '.entries';
    }

    private function getLockPath(string $sessionId): string
    {
        return $this->stateDirectory . '/' . $sessionId . '.lock';
    }

    /**
     * Validate session ID format.
     */
    private function validateSessionId(string $sessionId): void
    {
        if (empty($sessionId)) {
            throw new StatePersistenceException('Session ID cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $sessionId)) {
            throw new StatePersistenceException(
                'Session ID contains invalid characters',
                $sessionId
            );
        }

        if (strpos($sessionId, '..') !== false) {
            throw new StatePersistenceException(
                'Session ID contains path traversal characters',
                $sessionId
            );
        }

        if (strlen($sessionId) > 128) {
            throw new StatePersistenceException(
                'Session ID exceeds maximum length',
                $sessionId
            );
        }
    }

    /**
     * Destructor - close all resources.
     */
    public function __destruct()
    {
        $this->closeAll();

        foreach (array_keys($this->lockHandles) as $sessionId) {
            $this->releaseLock($sessionId);
        }
    }
}
