# Batch ZIP Stream Engine

A **production-grade batch ZIP streaming implementation** in PHP that creates ZIP archives incrementally across multiple executions while streaming output to abstract writable streams.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Installation](#installation)
4. [Quick Start](#quick-start)
5. [Stream-Agnostic API](#stream-agnostic-api)
6. [Core Concepts](#core-concepts)
7. [API Reference](#api-reference)
8. [State Persistence](#state-persistence)
9. [Error Handling](#error-handling)
10. [ZIP64 Support](#zip64-support)
11. [Encryption Support](#encryption-support)
12. [Extensibility](#extensibility)
13. [Security Considerations](#security-considerations)
14. [Performance](#performance)
15. [File Structure](#file-structure)

---

## Overview

### Problem Statement

Creating large ZIP archives in web environments presents several challenges:

- **Execution time limits**: PHP scripts have maximum execution times
- **Memory limits**: Large files cannot be loaded entirely into memory
- **Resumability**: Interrupted operations should be resumable
- **Cloud storage**: Output may go to cloud storage, not local disk
- **Scale**: Archives may contain millions of files

### Solution

This library provides a **batch-based ZIP creation engine** that:

- ✅ Splits ZIP creation across multiple independent executions
- ✅ Streams files incrementally (no memory exhaustion)
- ✅ Persists state externally (fully resumable)
- ✅ Writes to abstract streams (cloud-compatible)
- ✅ Full ZIP64 support (>4GB archives, >65535 files)
- ✅ Explicit failure handling (no silent corruption)
- ✅ **Memory-efficient architecture** for 1M+ files without memory issues
- ✅ **Stream-agnostic API** for cloud storage, HTTP uploads, and more

### Memory Characteristics

| File Count | State File Size | Memory Usage |
|------------|-----------------|--------------|
| 1,000      | ~500 bytes      | ~1 MB        |
| 10,000     | ~500 bytes      | ~1 MB        |
| 100,000    | ~500 bytes      | ~1 MB        |
| 1,000,000  | ~500 bytes      | ~1 MB        |

State file size remains constant regardless of archive size because file entries are stored in a separate append-only file that is streamed, never fully loaded into memory.

---

## Architecture

### Class Responsibilities

```
┌──────────────────────────────────────────────────────────────────┐
│                        BatchZipSession                            │
│  High-level session manager for batch operations                  │
└──────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌──────────────────────────────────────────────────────────────────┐
│                         BatchZipWriter                            │
│  Main ZIP writer engine that coordinates file addition            │
│  and finalization                                                 │
└──────────────────────────────────────────────────────────────────┘
        │                   │                        │
        ▼                   ▼                        ▼
┌──────────────┐  ┌──────────────────┐  ┌───────────────────────┐
│ArchiveState  │  │StreamingCompressor│  │  FileEntryStore       │
│(~500 bytes)  │  │Incremental deflate│  │  Append-only entries  │
│Offsets only  │  │& CRC32 calculation│  │  (never in memory)    │
└──────────────┘  └──────────────────┘  └───────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                       Stream Abstractions                        │
├────────────────────┬───────────────────┬────────────────────────┤
│WritableStreamInterface│ReadableStreamInterface│StatePersistenceInterface│
└────────────────────┴───────────────────┴────────────────────────┘
```

### Data Flow

```
Batch 1:
┌────────┐    ┌────────────┐    ┌──────────────┐    ┌────────────┐
│ Source │───►│ Compressor │───►│  ZIP Writer  │───►│   Stream   │
│ Files  │    │ (chunked)  │    │(local header)│    │  (output)  │
└────────┘    └────────────┘    └──────────────┘    └────────────┘
                                        │
                                        ├──► State (~500 bytes)
                                        └──► Entry Store (append-only)

Batch 2..N:
┌────────────┐    ┌──────────────┐    ┌────────────┐
│ Load State │───►│  ZIP Writer  │───►│   Stream   │
│  (resume)  │    │ (continue)   │    │  (append)  │
└────────────┘    └──────────────┘    └────────────┘

Final Batch:
┌──────────────┐    ┌─────────┐    ┌────────────┐
│  ZIP Writer  │───►│  CDR +  │───►│   Stream   │
│ (finalize)   │    │  EOCD   │    │  (close)   │
└──────────────┘    └─────────┘    └────────────┘
       │
       └──► Entry Store iterated via generator (never loaded fully)
```

---

## Installation

### Requirements

- PHP 7.4+
- `zlib` extension (for deflate compression)
- `hash` extension (for CRC32 calculation)
- `openssl` extension (required for WinZip AES-256 encryption)

### Via Composer (Recommended)

You can install the library via Composer:

```bash
composer require hanfy/batch-zip-stream
```

Then include the Composer autoloader in your script:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

### Manual Installation (No Composer)

If you are not using Composer, download/clone this repository and include the built-in autoloader:

```php
require_once __DIR__ . '/path/to/batch-zip-stream/autoload.php';
```

---

## Quick Start

### Simple Single-Batch Usage

```php
use BatchZipStream\BatchZipSession;

$session = new BatchZipSession('/path/to/state', '/path/to/output.zip');

$session->startSession('my-archive');
$session->addFileFromString('hello.txt', 'Hello, World!');
$session->addFileFromString('data/config.json', '{"key": "value"}');
$session->finalize();
```

### Multi-Batch Usage with Persistence

```php
use BatchZipStream\BatchZipSession;

// Batch 1: Start session and add files
$session = new BatchZipSession('/path/to/state', '/path/to/output.zip');
$session->startSession('unique-session-id');
$writer = $session->getWriter();
$writer->addFileFromString('file1.txt', 'Content 1');
$session->saveProgress();
$session->close();

// ... execution ends ...

// Batch 2: Resume and add more files
$session = new BatchZipSession('/path/to/state', '/path/to/output.zip');
$session->startSession('unique-session-id'); // Resumes existing session
$writer = $session->getWriter();
$writer->addFileFromString('file2.txt', 'Content 2');
$session->saveProgress();
$session->close();

// ... execution ends ...

// Final Batch: Finalize
$session = new BatchZipSession('/path/to/state', '/path/to/output.zip');
$session->startSession('unique-session-id');
$session->finalize();
```

### Streaming Large Files

```php
use BatchZipStream\BatchZipSession;
use BatchZipStream\Streams\FileReadableStream;

$session = new BatchZipSession('/path/to/state', '/path/to/output.zip');
$session->startSession('large-archive');

// Stream a large file (never fully loaded into memory)
$source = new FileReadableStream('/path/to/large-file.bin');
$session->addFileFromStream('large-file.bin', $source);

$session->finalize();
```

### Large Archive (1M+ Files)

```php
use BatchZipStream\BatchZipSession;

$session = new BatchZipSession('/path/to/state', '/path/to/output.zip');
$session->startSession('backup-session-12345');

// Add files (can be 1 million+ files)
foreach ($filesToBackup as $file) {
    $session->addFile(
        $file->getArchivePath(),
        $file->getSourcePath()
    );
}

// Save progress (entries are already persisted, just saves state)
$session->saveProgress();

// In the final batch, finalize
$session->finalize('Backup completed');
```

---

## Stream-Agnostic API

The architecture supports **any output stream**, not just files. This enables:
- Cloud storage (S3, GCS, Azure Blob)
- HTTP chunked uploads
- In-memory buffers (for testing)
- Custom stream implementations

### Creating Sessions with Custom Streams

#### Using a Stream Factory

```php
use BatchZipStream\BatchZipSession;

// Stream factory receives bool $append parameter
$session = BatchZipSession::withStreamFactory(
    '/path/to/state',
    fn(bool $append) => new S3MultipartStream('bucket', 'key', $append)
);

$session->startSession('cloud-backup');
$writer = $session->getWriter();
$writer->addFileFromString('hello.txt', 'Hello from cloud!');
$session->finalize();
```

#### Using a Pre-Created Stream

```php
$stream = new MyCloudStream(...);

$session = BatchZipSession::withStream(
    '/path/to/state',
    $stream
);

$session->startSession('my-session');
$writer = $session->getWriter();
// ... add files ...
$session->finalize();
```

#### In-Memory ZIP Creation

```php
use BatchZipStream\BatchZipSession;
use BatchZipStream\Streams\MemoryWritableStream;

$memory = new MemoryWritableStream();

$session = BatchZipSession::withStream('/tmp/state', $memory);
$session->startSession('memory-test');
$writer = $session->getWriter();
$writer->addFileFromString('test.txt', 'In-memory content');
$session->finalize();

// Get raw ZIP bytes
$zipBytes = $memory->getBuffer();

// Send directly over HTTP
header('Content-Type: application/zip');
header('Content-Length: ' . strlen($zipBytes));
echo $zipBytes;
```

### Adding Files from Streams

The `addFileFromStream()` method accepts any `ReadableStreamInterface`:

```php
use BatchZipStream\Streams\StringReadableStream;

$session = new BatchZipSession($stateDir, $archivePath);
$session->startSession('stream-input');

// From a string stream
$content = new StringReadableStream('file content', 'virtual.txt');
$session->addFileFromStream('output.txt', $content);

// From an HTTP stream (custom implementation)
$httpSource = new HttpReadableStream('https://example.com/data.json');
$session->addFileFromStream('remote/data.json', $httpSource);

$session->finalize();
```

### Available Session Methods

| Method | Description |
|--------|-------------|
| `startSession($sessionId)` | Start or resume a session, returns session ID |
| `getWriter()` | Get the `BatchZipWriter` instance |
| `addFile($path, $sourcePath, $compressionMethod, $encryptionMethod, $password)` | Add file from local filesystem with optional compression, encryption, and custom password |
| `addFileFromString($path, $content, $compressionMethod, $encryptionMethod, $password)` | Add file from string content with optional compression, encryption, and custom password |
| `addFileFromStream($path, $stream, $compressionMethod, $modificationTime)` | Add file from any `ReadableStreamInterface` with optional compression and modification time |
| `addEmptyDirectory($path, $modificationTime)` | Add an empty directory with optional modification time |
| `saveProgress()` | Persist current session progress and state |
| `finalize($comment)` | Write CDR, EOCD, and complete the archive |
| `cleanup()` | Remove state files and close resources for the current session |
| `abort($reason, $deleteArchive)` | Mark session as failed, close resources, and optionally delete the partial archive |
| `close()` | Close session streams and locks without deleting state (useful to pause a batch) |
| `getStream()` | Get the current output stream |
| `getState()` | Get the `ArchiveState` instance |
| `getStats()` | Get session statistics (count, sizes, path, etc.) |
| `exists($sessionId)` | Check if a session's state files exist |
| `cleanupOldSessions($maxAgeSeconds)` | Clean up stale or orphaned sessions older than the specified seconds |
| `listSessions()` | List all active session IDs |

---

## Core Concepts

### State Management

The library uses a split-state architecture for memory efficiency:

- **`ArchiveState`** (~500 bytes): Session ID, phase, offsets, counters
- **`FileEntryStore`** (streamed): Line-delimited JSON, append-only

**Critical Rule**: Only `ArchiveState` is persisted between batches. ZIP writers, streams, and compression contexts are NEVER serialized.

### File Entries

Each file in the archive is represented by a `FileEntry`:

```php
new FileEntry(
    filename: 'path/to/file.txt',     // Path in ZIP (UTF-8, forward slashes)
    crc32: 0x12345678,                // CRC-32 of uncompressed data
    compressedSize: 1000,             // Size after compression
    uncompressedSize: 2000,           // Original file size
    localHeaderOffset: 0,             // Byte offset of local header
    compressionMethod: 8,             // 0=store, 8=deflate
    dosTime: 0x5678ABCD,              // DOS timestamp
    generalPurposeBitFlag: 0x0800,    // Flags (e.g., UTF-8)
    versionNeeded: 0x14,              // Version needed to extract
    requiresZip64: false,             // ZIP64 requirement
    localHeaderOffsetHigh: 0,         // High 32 bits for ZIP64
);
```

### Phases

Archives progress through phases:

1. **INITIALIZING**: Fresh archive, no files added
2. **ADDING_FILES**: Files being added
3. **FINALIZING**: Writing Central Directory
4. **COMPLETED**: Successfully finished
5. **FAILED**: Error occurred

---

## API Reference

### BatchZipSession

High-level session manager.

#### Constructors

```php
// File-based output
$session = new BatchZipSession($stateDir, $archivePath);

// Custom stream factory
$session = BatchZipSession::withStreamFactory(
    $stateDir,
    fn(bool $append) => new MyStream($append)
);

// Pre-created stream
$session = BatchZipSession::withStream($stateDir, $stream);

// Custom persistence
$session = BatchZipSession::withPersistence($persistence, $archivePath);
```

#### Methods

```php
$session->startSession($sessionId);       // Start or resume session
$writer = $session->getWriter();          // Get the BatchZipWriter instance
$session->addFile($path, $sourcePath, $compressionMethod, $encryptionMethod, $password); // Add file from filesystem
$session->addFileFromString($path, $content, $compressionMethod, $encryptionMethod, $password); // Add file from string
$session->addFileFromStream($path, $stream, $compressionMethod, $modificationTime); // Add file from stream
$session->addEmptyDirectory($path, $modificationTime); // Add empty directory
$session->saveProgress();                 // Persist state
$session->finalize($comment);             // Complete archive
$session->cleanup();                      // Remove state files and close
$session->abort($reason, $deleteArchive); // Mark as failed and close
$session->getStream();                    // Get output stream
$session->getState();                     // Get ArchiveState instance
$session->getStats();                     // Get session statistics
$session->exists($sessionId);             // Check if session exists
$session->cleanupOldSessions($maxAgeSeconds); // Clean up stale sessions
$session->listSessions();                 // List active session IDs
$session->close();                        // Close streams/locks without deleting state
```

### BatchZipWriter

Low-level memory-efficient writer.

```php
use BatchZipStream\BatchZipWriter;
use BatchZipStream\State\ArchiveState;
use BatchZipStream\State\FileEntryStore;

$state = new ArchiveState('session-id');
$entryStore = new FileEntryStore('/path/to/session.entries');

$writer = new BatchZipWriter($stream, $state, $entryStore);

$writer->addFileFromString('file.txt', 'content');
$writer->addFile('data.bin', $sourceStream);

$writer->finalize('Archive comment');
$writer->close();
```

#### Constructor

```php
new BatchZipWriter(
    WritableStreamInterface $stream,    // Output stream
    ArchiveState $state,                // Archive state
    FileEntryStore $entryStore,         // Entry store
    int $compressionLevel = 6,          // Deflate level (0-9)
    int $chunkSize = 65536              // Read chunk size
);
```

#### Methods

| Method | Description |
|--------|-------------|
| `addFile(string $filename, ReadableStreamInterface $source, int $method = DEFLATE, ?int $mtime = null, int $enc = ENC_NONE, ?string $password = null)` | Add a file from a stream |
| `addFileFromString(string $filename, string $data, int $method = DEFLATE, ?int $mtime = null, int $enc = ENC_NONE, ?string $password = null)` | Add a file from string data |
| `finalize(string $comment = '')` | Write Central Directory and EOCD |
| `close()` | Close the output stream |
| `getState()` | Get the current archive state |
| `getEntryStore()` | Get the entry store |
| `canAddFiles()` | Check if files can be added |
| `canFinalize()` | Check if archive can be finalized |

---

## State Persistence

### File-Based Persistence

```php
use BatchZipStream\Persistence\FileStatePersistence;

$persistence = new FileStatePersistence('/path/to/states');

// Create session
$session = $persistence->create('session-id');
$state = $session['state'];
$entryStore = $session['entryStore'];

// Save state (tiny ~500 bytes)
$persistence->save('session-id', $state);

// Load session
$session = $persistence->load('session-id');

// Clean up all session files
$persistence->delete('session-id');
```

#### Files per Session

Each session creates:
- `{sessionId}.json` - State (~500 bytes)
- `{sessionId}.entries` - Entry store (append-only)
- `{sessionId}.lock` - Lock file

### Custom Persistence

Implement `StatePersistenceInterface` for custom backends (Redis, database, etc.):

```php
class RedisStatePersistence implements StatePersistenceInterface
{
    public function create(string $sessionId): array
    {
        $state = new ArchiveState($sessionId);
        $entryStore = new FileEntryStore($this->getEntryPath($sessionId));
        $entryStore->open(true);
        return ['state' => $state, 'entryStore' => $entryStore];
    }

    public function load(string $sessionId): ?array
    {
        $json = $this->redis->get("zip:state:$sessionId");
        if ($json === null) return null;
        
        $state = ArchiveState::fromJson($json);
        $entryStore = new FileEntryStore($this->getEntryPath($sessionId));
        $entryStore->open(false);
        return ['state' => $state, 'entryStore' => $entryStore];
    }
    
    public function save(string $sessionId, ArchiveState $state): void
    {
        $this->redis->set("zip:state:$sessionId", $state->toJson());
    }
    
    // ... other methods
}
```

---

## Error Handling

### Exception Hierarchy

```
BatchZipStreamException (base)
├── WriteFailureException     - Output stream write failed
├── ReadFailureException      - Source stream read failed
├── CompressionException      - Compression operation failed
├── ValidationException       - Archive validation failed
├── InvalidOperationException - Invalid operation for current state
└── StatePersistenceException - State save/load failed
```

### Error Handling Strategy

1. **Any write failure invalidates the entire archive**
2. **State is marked as FAILED on any exception**
3. **No silent fallbacks** - all errors throw exceptions
4. **Central Directory is NEVER written if any file failed**

```php
try {
    $writer->addFile('file.txt', $source);
} catch (WriteFailureException $e) {
    // Archive is now invalid
    $state = $writer->getState();
    assert($state->isFailed());
    
    // Cleanup
    unlink($zipPath);
}
```

---

## ZIP64 Support

Full ZIP64 support for:

- Archives larger than 4GB
- Individual files larger than 4GB
- More than 65,535 files

ZIP64 is automatically enabled when needed:

```php
$session = new BatchZipSession($stateDir, $archivePath);
$session->startSession('large-archive');

// Add many files
for ($i = 0; $i < 70000; $i++) {
    $session->addFileFromString("file-$i.txt", "content");
}

// ZIP64 is automatically enabled
$state = $session->getState();
assert($state->requiresZip64());

$session->finalize();
```

### ZIP64 Structures

When ZIP64 is required, the following structures are added:

1. **ZIP64 Extra Field** in Central Directory entries
2. **ZIP64 End of Central Directory Record**
3. **ZIP64 End of Central Directory Locator**

---

## Encryption Support

The library supports securing ZIP archives using standard zip encryption methods:

1. **Traditional PKWARE Encryption (`ZipFormat::ENC_TRADITIONAL`)**: Highly compatible across older extraction tools but cryptographically weak.
2. **WinZip AES-256 Strong Encryption (`ZipFormat::ENC_AES_256`)**: Industry-standard strong encryption. Requires the `openssl` PHP extension enabled in PHP.

### Setting a Global Password

You can configure a global password during `BatchZipSession` construction:

```php
use BatchZipStream\BatchZipSession;
use BatchZipStream\Core\ZipFormat;

$globalPassword = 'my-global-secret';
$session = new BatchZipSession(
    $stateDir,
    $archivePath,
    6,            // Compression level
    65536,        // Chunk size
    null,         // Custom state persistence
    $globalPassword
);
```

### Encrypting Files

To encrypt files, pass the encryption method to the `addFile` or `addFileFromString` methods.

```php
$session->startSession('encrypted-archive');

// 1. Encrypt with WinZip AES-256 (recommended) using the global password
$session->addFileFromString(
    'secret.txt',
    'Top Secret Content',
    ZipFormat::COMPRESSION_DEFLATE,
    ZipFormat::ENC_AES_256
);

// 2. Encrypt with Traditional PKWARE encryption using the global password
$session->addFile(
    'legacy.txt',
    '/path/to/local-file.txt',
    ZipFormat::COMPRESSION_DEFLATE,
    ZipFormat::ENC_TRADITIONAL
);

// 3. Keep a file public (unencrypted) in the same archive
$session->addFileFromString(
    'public.txt',
    'This is readable by anyone',
    ZipFormat::COMPRESSION_DEFLATE,
    ZipFormat::ENC_NONE
);

$session->finalize();
```

### Overriding Passwords per File

You can override the global password for specific files by passing a custom password as the final parameter.

```php
// Encrypt using traditional PKWARE with a custom password instead of the global one
$session->addFileFromString(
    'override.txt',
    'Custom Password Protected Content',
    ZipFormat::COMPRESSION_DEFLATE,
    ZipFormat::ENC_TRADITIONAL,
    'file-specific-password'
);

// When using the writer directly:
$writer = $session->getWriter();
$writer->addFileFromString(
    'custom-aes.txt',
    'AES-256 with custom password',
    ZipFormat::COMPRESSION_DEFLATE,
    null, // modificationTime
    ZipFormat::ENC_AES_256,
    'custom-aes-password'
);
```

---

## Extensibility

### Custom Output Streams

Implement `WritableStreamInterface` for cloud storage:

```php
class S3MultipartStream implements WritableStreamInterface
{
    public function write(string $data): int
    {
        // Buffer and upload parts
        $this->buffer .= $data;
        
        if (strlen($this->buffer) >= 5 * 1024 * 1024) {
            $this->uploadPart($this->buffer);
            $this->buffer = '';
        }
        
        return strlen($data);
    }
    
    public function close(): void
    {
        if ($this->buffer !== '') {
            $this->uploadPart($this->buffer);
        }
        $this->completeMultipartUpload();
    }
}
```

### Built-in Streams

The library provides several built-in stream implementations:

#### MemoryWritableStream

An in-memory buffer for testing or small archives:

```php
use BatchZipStream\Streams\MemoryWritableStream;

$memory = new MemoryWritableStream();

$session = BatchZipSession::withStream('/state', $memory);
$session->startSession('test');
$session->addFileFromString('test.txt', 'Hello!');
$session->finalize();

// Access the buffer
$zipBytes = $memory->getBuffer();  // Raw ZIP bytes
$length = $memory->getLength();     // Total bytes written

// Reset for reuse
$memory->reset();
```

#### FileWritableStream

Standard file output stream:

```php
use BatchZipStream\Streams\FileWritableStream;

$stream = new FileWritableStream('/path/to/archive.zip');
$stream->write($data);
$stream->flush();
$stream->close();
```

#### BufferedWritableStream

Wraps another stream with buffering:

```php
use BatchZipStream\Streams\BufferedWritableStream;

$buffered = new BufferedWritableStream($innerStream, 65536);
```

#### CallbackWritableStream

Invokes a callback on each write:

```php
use BatchZipStream\Streams\CallbackWritableStream;

$stream = new CallbackWritableStream(function(string $data) use ($s3) {
    $s3->appendToObject($data);
    return strlen($data);
});
```

### Custom Input Streams

Implement `ReadableStreamInterface` for custom sources:

```php
class HttpReadableStream implements ReadableStreamInterface
{
    public function __construct(string $url)
    {
        $this->stream = fopen($url, 'rb');
    }
    
    public function read(int $length): string
    {
        return fread($this->stream, $length);
    }
    
    public function eof(): bool
    {
        return feof($this->stream);
    }
}
```

---

## Security Considerations

### Path Traversal

Filenames are automatically sanitized:

- Leading slashes removed
- Backslashes converted to forward slashes
- Invalid characters replaced
- `../` sequences are preserved but validated

### Validation

The `FileEntryStore` provides entry-level validation:

- JSON integrity per entry
- Duplicate filename detection
- Entry metadata validation via `FileEntry.validate()`

---

## Performance

### Memory Usage

- Files are read in configurable chunks (default 64KB)
- Compression uses streaming deflate context
- CRC32 is calculated incrementally
- No file is ever fully loaded into memory
- State stays ~500 bytes regardless of file count
- Entry store is streamed via generator during finalization

### Tuning Options

```php
$session = new BatchZipSession(
    $stateDir,
    $archivePath,
    compressionLevel: 6,     // 0=fastest, 9=best compression
    chunkSize: 131072        // 128KB chunks for faster I/O
);
```

### Buffered Output

For better I/O performance with small writes:

```php
use BatchZipStream\Streams\BufferedWritableStream;

$inner = new FileWritableStream($path);
$buffered = new BufferedWritableStream($inner, 1048576); // 1MB buffer

$session = BatchZipSession::withStream($stateDir, $buffered);
```

---

## File Structure

```
batch-zip-stream/
├── autoload.php                      # PSR-4 compatible autoloader
├── composer.json                     # Composer configuration
├── phpunit.xml.dist                  # PHPUnit configuration
├── README.md                         # Documentation
├── src/                              # Source code root
│   ├── BatchZipSession.php           # High-level session manager
│   ├── BatchZipWriter.php            # Core ZIP writer engine
│   ├── Contracts/
│   │   ├── ReadableStreamInterface.php  # Abstract input stream interface
│   │   ├── WritableStreamInterface.php  # Abstract output stream interface
│   │   └── StatePersistenceInterface.php # State & entry store persistence interface
│   ├── Core/
│   │   ├── Crypto/
│   │   │   ├── CryptoEngineInterface.php # Stream-based encryption interface
│   │   │   ├── TraditionalZipCrypto.php # PKWARE traditional ZIP encryption engine
│   │   │   └── WinZipAesCrypto.php      # WinZip AES-256 strong encryption engine
│   │   ├── ZipFormat.php             # Low-level ZIP binary structure utilities
│   │   └── StreamingCompressor.php   # Deflate compressor and CRC32 calculator
│   ├── Exceptions/
│   │   ├── BatchZipStreamException.php   # Base exception class
│   │   ├── CompressionException.php      # Compression failure exception
│   │   ├── InvalidOperationException.php # Operation invalid for current phase exception
│   │   ├── ReadFailureException.php      # Input stream read failure exception
│   │   ├── StatePersistenceException.php # State load/save/lock failure exception
│   │   └── WriteFailureException.php     # Output stream write failure exception
│   ├── Persistence/
│   │   └── FileStatePersistence.php  # Filesystem state persistence implementation
│   ├── State/
│   │   ├── ArchiveState.php          # Archive state metadata
│   │   ├── FileEntry.php             # Immutable single file metadata entry
│   │   └── FileEntryStore.php        # Disk-backed append-only entries store
│   └── Streams/
│       ├── BufferedWritableStream.php # Memory buffered writable stream wrapper
│       ├── CallbackWritableStream.php # Callback-delegated writable stream
│       ├── FileReadableStream.php    # Chunked local file reader stream
│       ├── FileWritableStream.php    # Local file writer stream
│       ├── MemoryWritableStream.php  # In-memory output stream buffer
│       └── StringReadableStream.php  # Chunked string reader stream
└── tests/                            # PHPUnit tests root
    ├── BatchZipIntegrationTest.php   # Integration tests for ZIP formats and resumption
    ├── BatchZipSessionUnitTest.php   # Unit tests for session lifecycle and locking
    ├── Core/                         # Unit tests for core ZIP formatting components
    │   ├── CentralDirectoryFileHeaderTest.php
    │   ├── DataDescriptorTest.php
    │   ├── EndOfCentralDirectoryTest.php
    │   ├── LocalFileHeaderTest.php
    │   └── ZipFormatUnitTest.php
    ├── Crypto/                       # Unit tests for encryption engines
    │   ├── TraditionalZipCryptoTest.php
    │   └── WinZipAesCryptoTest.php
    └── BatchZipEncryptionIntegrationTest.php # Integration tests for encrypted ZIPs
```

---
