<?php

declare(strict_types=1);

namespace BatchZipStream\State;

use BatchZipStream\Exceptions\StatePersistenceException;

/**
 * Streaming file entry store that persists entries to disk incrementally.
 * 
 * This solves the memory exhaustion problem for large archives by:
 * 1. Writing each entry to disk as it's added (append-only)
 * 2. Never loading all entries into memory at once
 * 3. Providing an iterator for reading entries during CDR generation
 * 
 * File format:
 * - Each entry is a single line of JSON followed by newline
 * - This allows streaming reads without loading entire file
 * - Line-based format is simple and robust
 * 
 * CRITICAL: This class is NOT serialized. Only the file path is stored in state.
 */
class FileEntryStore
{
    /** @var resource|null File handle for writing */
    private $writeHandle = null;

    /** @var string Path to the entries file */
    private string $filePath;

    /** @var int Number of entries written */
    private int $entryCount = 0;

    /** @var bool Whether store is open for writing */
    private bool $isOpen = false;

    /**
     * Create a new file entry store.
     * 
     * @param string $filePath Path to store entries
     */
    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * Open the store for writing (append mode).
     * 
     * @param bool $truncate Whether to truncate existing file
     * @throws StatePersistenceException On open failure
     */
    public function open(bool $truncate = false): void
    {
        if ($this->isOpen) {
            return;
        }

        $mode = $truncate ? 'wb' : 'ab';
        $this->writeHandle = @fopen($this->filePath, $mode);

        if ($this->writeHandle === false) {
            throw new StatePersistenceException(
                sprintf('Failed to open entry store: %s', $this->filePath)
            );
        }

        $this->isOpen = true;

        // Count existing entries if not truncating
        if (!$truncate && file_exists($this->filePath)) {
            $this->entryCount = $this->countEntries();
        } else {
            $this->entryCount = 0;
        }
    }

    /**
     * Append a file entry to the store.
     * 
     * @param FileEntry $entry Entry to append
     * @throws StatePersistenceException On write failure
     */
    public function append(FileEntry $entry): void
    {
        if (!$this->isOpen || $this->writeHandle === null) {
            throw new StatePersistenceException('Entry store is not open');
        }

        // Serialize entry to compact JSON (no pretty print)
        $json = json_encode($entry->toArray(), JSON_THROW_ON_ERROR);
        $line = $json . "\n";

        $written = @fwrite($this->writeHandle, $line);

        if ($written === false || $written !== strlen($line)) {
            throw new StatePersistenceException(
                sprintf('Failed to write entry to store: %s', $entry->filename)
            );
        }

        // Flush to ensure durability
        @fflush($this->writeHandle);

        $this->entryCount++;
    }

    /**
     * Close the store.
     */
    public function close(): void
    {
        if ($this->writeHandle !== null) {
            @fclose($this->writeHandle);
            $this->writeHandle = null;
        }
        $this->isOpen = false;
    }

    /**
     * Flush any buffered writes to disk.
     */
    public function flush(): void
    {
        if ($this->writeHandle !== null) {
            @fflush($this->writeHandle);
        }
    }

    /**
     * Get the number of entries in the store.
     */
    public function getEntryCount(): int
    {
        return $this->entryCount;
    }

    /**
     * Get the file path.
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Check if the store file exists.
     */
    public function exists(): bool
    {
        return file_exists($this->filePath);
    }

    /**
     * Delete the store file.
     */
    public function delete(): void
    {
        $this->close();
        if (file_exists($this->filePath)) {
            @unlink($this->filePath);
        }
    }

    /**
     * Iterate over all entries without loading all into memory.
     * 
     * @return \Generator<FileEntry>
     * @throws StatePersistenceException On read failure
     */
    public function iterate(): \Generator
    {
        if (!file_exists($this->filePath)) {
            return;
        }

        $handle = @fopen($this->filePath, 'rb');
        if ($handle === false) {
            throw new StatePersistenceException(
                sprintf('Failed to open entry store for reading: %s', $this->filePath)
            );
        }

        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                try {
                    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    yield FileEntry::fromArray($data);
                } catch (\JsonException $e) {
                    throw new StatePersistenceException(
                        sprintf('Invalid JSON at line %d in entry store: %s', $lineNumber, $e->getMessage())
                    );
                }
            }
        } finally {
            @fclose($handle);
        }
    }

    /**
     * Read entries in batches for memory-efficient processing.
     * 
     * @param int $batchSize Number of entries per batch
     * @return \Generator<array<FileEntry>>
     */
    public function iterateBatches(int $batchSize = 1000): \Generator
    {
        $batch = [];
        $count = 0;

        foreach ($this->iterate() as $entry) {
            $batch[] = $entry;
            $count++;

            if ($count >= $batchSize) {
                yield $batch;
                $batch = [];
                $count = 0;
            }
        }

        if (!empty($batch)) {
            yield $batch;
        }
    }

    /**
     * Count entries in the store without loading them.
     */
    private function countEntries(): int
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }

        $count = 0;
        $handle = @fopen($this->filePath, 'rb');

        if ($handle === false) {
            return 0;
        }

        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }

        fclose($handle);
        return $count;
    }

    /**
     * Get the last N entries (for validation).
     * 
     * @param int $count Number of entries to get
     * @return FileEntry[]
     */
    public function getLastEntries(int $count): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        // For small counts, we can read from end
        // For simplicity, iterate and keep last N
        $entries = [];

        foreach ($this->iterate() as $entry) {
            $entries[] = $entry;
            if (count($entries) > $count) {
                array_shift($entries);
            }
        }

        return $entries;
    }

    /**
     * Validate all entries in the store.
     * 
     * @return array<string> List of validation errors
     */
    public function validateAll(): array
    {
        $errors = [];
        $index = 0;
        $seenFilenames = [];

        foreach ($this->iterate() as $entry) {
            // Validate entry
            $entryErrors = $entry->validate();
            foreach ($entryErrors as $error) {
                $errors[] = sprintf('Entry %d [%s]: %s', $index, $entry->filename, $error);
            }

            // Check for duplicates
            if (isset($seenFilenames[$entry->filename])) {
                $errors[] = sprintf('Duplicate filename at entry %d: %s', $index, $entry->filename);
            }
            $seenFilenames[$entry->filename] = true;

            $index++;

            // Limit error collection to prevent memory issues
            if (count($errors) > 100) {
                $errors[] = '... (more errors truncated)';
                break;
            }
        }

        return $errors;
    }

    /**
     * Get file size of the store.
     */
    public function getFileSize(): int
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }
        return (int) filesize($this->filePath);
    }

    /**
     * Destructor - ensure file is closed.
     */
    public function __destruct()
    {
        $this->close();
    }
}
