<?php

declare(strict_types=1);

namespace BatchZipStream\Tests;

use BatchZipStream\BatchZipSession;
use BatchZipStream\BatchZipWriter;
use BatchZipStream\Contracts\ReadableStreamInterface;
use BatchZipStream\Contracts\StatePersistenceInterface;
use BatchZipStream\Contracts\WritableStreamInterface;
use BatchZipStream\Exceptions\InvalidOperationException;
use BatchZipStream\State\ArchiveState;
use BatchZipStream\State\FileEntryStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
$libraryAutoloader = __DIR__ . '/../autoload.php';
if (file_exists($libraryAutoloader)) {
    require_once $libraryAutoloader;
}


/**
 * Unit tests for BatchZipSession focusing on control flow and error handling.
 */
final class BatchZipSessionUnitTest extends TestCase
{
    /** @var BatchZipSession[] */
    private array $sessionsToCleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->sessionsToCleanup as $session) {
            $this->neutralizeSession($session);
        }
        $this->sessionsToCleanup = [];
    }

    public function test_should_throw_when_lock_not_acquired_on_start(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->expects($this->once())->method('acquireLock')->with('locked', 30)->willReturn(false);
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $persistence);
        $this->registerSession($session);

        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('Failed to acquire lock for session: locked');

        // Act
        $session->startSession('locked');

        // Assert
    }

    public function test_should_throw_when_resuming_failed_session(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $state = $this->createMock(ArchiveState::class);
        $entryStore = $this->createMock(FileEntryStore::class);
        $stream = $this->createMock(WritableStreamInterface::class);

        $persistence->method('acquireLock')->willReturn(true);
        $persistence->method('load')->willReturn([
            'state' => $state,
            'entryStore' => $entryStore,
        ]);
        $persistence->expects($this->once())->method('releaseLock')->with('failed-session');

        $state->method('isFailed')->willReturn(true);
        $state->method('getFailureReason')->willReturn('boom');
        $state->method('getPhase')->willReturn('failed');

        $session = BatchZipSession::withStreamFactory($persistence, fn(bool $append) => $stream);
        $this->registerSession($session);

        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('Session is in failed state: boom');

        // Act
        $session->startSession('failed-session');

        // Assert
    }
    public function test_should_throw_when_resuming_completed_session(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $state = $this->createMock(ArchiveState::class);
        $entryStore = $this->createMock(FileEntryStore::class);
        $stream = $this->createMock(WritableStreamInterface::class);

        $persistence->method('acquireLock')->willReturn(true);
        $persistence->method('load')->willReturn([
            'state' => $state,
            'entryStore' => $entryStore,
        ]);
        $persistence->expects($this->once())->method('releaseLock')->with('done-session');

        $state->method('isFailed')->willReturn(false);
        $state->method('isCompleted')->willReturn(true);
        $state->method('getPhase')->willReturn('completed');

        $session = BatchZipSession::withStreamFactory($persistence, fn(bool $append) => $stream);
        $this->registerSession($session);

        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('Session is already completed');

        // Act
        $session->startSession('done-session');

        // Assert
    }

    public function test_should_create_new_session_with_stream_factory(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $state = $this->createMock(ArchiveState::class);
        $entryStore = $this->createMock(FileEntryStore::class);
        $stream = $this->createMock(WritableStreamInterface::class);

        $persistence->method('acquireLock')->willReturn(true);
        $persistence->method('load')->willReturn(null);
        $persistence->method('create')->with('fresh')->willReturn([
            'state' => $state,
            'entryStore' => $entryStore,
        ]);

        $stream->method('getPosition')->willReturn(0);

        $session = BatchZipSession::withStreamFactory($persistence, fn(bool $append) => $stream);
        $this->registerSession($session);

        // Act
        $sessionId = $session->startSession('fresh');

        // Assert
        $this->assertSame('fresh', $sessionId);
        $this->assertSame($state, $session->getState());
        $this->assertSame($stream, $session->getStream());
    }

    public function test_saveProgress_should_flush_and_persist_state(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $state = $this->createMock(ArchiveState::class);
        $entryStore = $this->createMock(FileEntryStore::class);
        $stream = $this->createMock(WritableStreamInterface::class);

        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $persistence);
        $this->registerSession($session);

        $this->setPrivateProperty($session, 'sessionId', 'persist-me');
        $this->setPrivateProperty($session, 'state', $state);
        $this->setPrivateProperty($session, 'entryStore', $entryStore);
        $this->setPrivateProperty($session, 'stream', $stream);

        $entryStore->expects($this->once())->method('flush');
        $stream->expects($this->once())->method('isOpen')->willReturn(true);
        $stream->expects($this->once())->method('flush');
        $persistence->expects($this->once())->method('save')->with('persist-me', $state);

        // Act
        $session->saveProgress();

        // Assert
    }

    public function test_saveProgress_should_throw_when_session_not_started(): void
    {
        // Arrange
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $this->createMock(StatePersistenceInterface::class));
        $this->registerSession($session);

        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('Session not started');

        // Act
        $session->saveProgress();

        // Assert
    }

    public function test_finalize_should_finalize_close_and_cleanup_state(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $writer = $this->createMock(BatchZipWriter::class);
        $stream = $this->createMock(WritableStreamInterface::class);
        $state = $this->createMock(ArchiveState::class);

        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $persistence);
        $this->registerSession($session);

        $this->setPrivateProperty($session, 'sessionId', 'final-session');
        $this->setPrivateProperty($session, 'writer', $writer);
        $this->setPrivateProperty($session, 'stream', $stream);
        $this->setPrivateProperty($session, 'state', $state);
        $this->setPrivateProperty($session, 'lockAcquired', true);

        $writer->expects($this->once())->method('finalize')->with('done-comment');
        $persistence->expects($this->once())->method('delete')->with('final-session');
        $persistence->expects($this->once())->method('releaseLock')->with('final-session');

        // Ensure destructor stays quiet
        $state->method('isCompleted')->willReturn(true);

        // Act
        $session->finalize('done-comment');

        // Assert
        $this->assertNull($session->getStream());
    }

    public function test_finalize_should_throw_when_not_started(): void
    {
        // Arrange
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $this->createMock(StatePersistenceInterface::class));
        $this->registerSession($session);

        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('Session not started');

        // Act
        $session->finalize();

        // Assert
    }

    public function test_addFileFromString_should_forward_to_writer_with_same_arguments(): void
    {
        // Arrange
        $writer = $this->createMock(BatchZipWriter::class);
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $this->createMock(StatePersistenceInterface::class));
        $this->registerSession($session);

        $this->setPrivateProperty($session, 'writer', $writer);

        $writer->expects($this->once())->method('addFileFromString')->with('path/in/zip.txt', 'content', 8);

        // Act
        $session->addFileFromString('path/in/zip.txt', 'content');

        // Assert
    }

    public function test_addFileFromStream_should_forward_to_writer(): void
    {
        // Arrange
        $writer = $this->createMock(BatchZipWriter::class);
        $stream = $this->createMock(ReadableStreamInterface::class);
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $this->createMock(StatePersistenceInterface::class));
        $this->registerSession($session);

        $this->setPrivateProperty($session, 'writer', $writer);

        $writer->expects($this->once())->method('addFile')->with('a/b/c.txt', $stream, 8, null);

        // Act
        $session->addFileFromStream('a/b/c.txt', $stream);

        // Assert
    }

    public function test_addEmptyDirectory_should_forward_to_writer_with_modification_time(): void
    {
        // Arrange
        $writer = $this->createMock(BatchZipWriter::class);
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $this->createMock(StatePersistenceInterface::class));
        $this->registerSession($session);

        $this->setPrivateProperty($session, 'writer', $writer);

        $writer->expects($this->once())->method('addEmptyDirectory')->with('dir/', 1234);

        // Act
        $session->addEmptyDirectory('dir/', 1234);

        // Assert
    }

    public function test_close_should_close_all_resources_and_release_lock(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $writer = $this->createMock(BatchZipWriter::class);
        $entryStore = $this->createMock(FileEntryStore::class);
        $stream = $this->createMock(WritableStreamInterface::class);

        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $persistence);
        $this->registerSession($session);

        $this->setPrivateProperty($session, 'writer', $writer);
        $this->setPrivateProperty($session, 'entryStore', $entryStore);
        $this->setPrivateProperty($session, 'stream', $stream);
        $this->setPrivateProperty($session, 'sessionId', 'closing');
        $this->setPrivateProperty($session, 'lockAcquired', true);

        $writer->expects($this->once())->method('close');
        $entryStore->expects($this->once())->method('close');
        $stream->expects($this->once())->method('isOpen')->willReturn(true);
        $stream->expects($this->once())->method('close');
        $persistence->expects($this->once())->method('releaseLock')->with('closing');

        // Act
        $session->close();

        // Assert
    }

    public function test_cleanup_should_do_nothing_when_session_not_started(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->expects($this->never())->method('delete');

        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $persistence);
        $this->registerSession($session);

        // Act
        $session->cleanup();

        // Assert
    }

    public function test_getWriter_should_throw_when_not_started(): void
    {
        // Arrange
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $this->createMock(StatePersistenceInterface::class));
        $this->registerSession($session);

        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('Session not started. Call startSession() first.');

        // Act
        $session->getWriter();

        // Assert
    }

    public function test_getStats_should_return_null_when_session_not_initialized(): void
    {
        // Arrange
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $this->createMock(StatePersistenceInterface::class));
        $this->registerSession($session);

        // Act
        $stats = $session->getStats();

        // Assert
        $this->assertNull($stats);
    }

    public function test_getStats_should_return_values_from_state_and_persistence(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $state = $this->createMock(ArchiveState::class);
        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $persistence);
        $this->registerSession($session);

        $this->setPrivateProperty($session, 'sessionId', 'stat-session');
        $this->setPrivateProperty($session, 'state', $state);

        $persistence->expects($this->once())->method('getStorageStats')->with('stat-session')->willReturn(['stateSize' => 10]);
        $state->method('getPhase')->willReturn('adding');
        $state->method('getFileCount')->willReturn(5);
        $state->method('getFullOffset')->willReturn(1234);
        $state->method('requiresZip64')->willReturn(false);
        $state->method('getCreatedAt')->willReturn(1);
        $state->method('getUpdatedAt')->willReturn(2);

        // Act
        $stats = $session->getStats();

        // Assert
        $this->assertSame('stat-session', $stats['sessionId']);
        $this->assertSame('adding', $stats['phase']);
        $this->assertSame(5, $stats['fileCount']);
        $this->assertSame(1234, $stats['bytesWritten']);
        $this->assertFalse($stats['requiresZip64']);
        $this->assertSame(['stateSize' => 10], $stats['stateSize']);
        $this->assertSame(1, $stats['createdAt']);
        $this->assertSame(2, $stats['updatedAt']);
    }

    public function test_abort_should_mark_failed_release_lock_and_delete_state(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $state = $this->createMock(ArchiveState::class);
        $stream = $this->createMock(WritableStreamInterface::class);

        $session = new BatchZipSession('unused', 'unused.zip', 6, 65536, $persistence);
        $this->registerSession($session);

        $this->setPrivateProperty($session, 'sessionId', 'abort-me');
        $this->setPrivateProperty($session, 'state', $state);
        $this->setPrivateProperty($session, 'stream', $stream);
        $this->setPrivateProperty($session, 'lockAcquired', true);

        $state->expects($this->once())->method('fail')->with('Aborted by user');
        $stream->expects($this->once())->method('isOpen')->willReturn(true);
        $stream->expects($this->once())->method('close');
        $persistence->expects($this->once())->method('delete')->with('abort-me');
        $persistence->expects($this->once())->method('releaseLock')->with('abort-me');

        // Act
        $session->abort();

        // Assert
        $this->assertNull($session->getStream());
    }

    public function test_startSession_should_use_append_when_resuming(): void
    {
        // Arrange
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $state = $this->createMock(ArchiveState::class);
        $entryStore = $this->createMock(FileEntryStore::class);

        $appendFlag = null;
        $stream = $this->createMock(WritableStreamInterface::class);
        $stream->method('getPosition')->willReturn(0);

        $persistence->method('acquireLock')->willReturn(true);
        $persistence->method('load')->willReturn([
            'state' => $state,
            'entryStore' => $entryStore,
        ]);
        $state->method('isFailed')->willReturn(false);
        $state->method('isCompleted')->willReturn(false);

        $session = BatchZipSession::withStreamFactory(
            $persistence,
            function (bool $append) use (&$appendFlag, $stream) {
                $appendFlag = $append;
                return $stream;
            }
        );
        $this->registerSession($session);

        // Act
        $session->startSession('resume-me');

        // Assert
        $this->assertTrue($appendFlag);
    }

    public function test_with_persistence_creates_instance(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $session = BatchZipSession::withPersistence($persistence, 'out.zip', 9, 8192);
        
        $this->assertInstanceOf(BatchZipSession::class, $session);
        $this->assertEquals('out.zip', $session->getOutputPath());
    }

    public function test_with_stream_factory_using_directory_string(): void
    {
        $factory = fn(bool $append) => $this->createMock(WritableStreamInterface::class);
        $session = BatchZipSession::withStreamFactory('/tmp/state', $factory);
        
        $this->assertInstanceOf(BatchZipSession::class, $session);
        $this->assertNull($session->getOutputPath());
    }

    public function test_with_stream_using_persistence_instance(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $stream = $this->createMock(WritableStreamInterface::class);
        $session = BatchZipSession::withStream($persistence, $stream);
        
        $this->assertInstanceOf(BatchZipSession::class, $session);
        $this->assertNull($session->getOutputPath());
    }

    public function test_abort_ignores_stream_close_exception(): void
    {
        $session = new BatchZipSession('unused', 'out.zip');
        
        $stream = $this->createMock(WritableStreamInterface::class);
        $stream->method('isOpen')->willReturn(true);
        $stream->expects($this->once())->method('close')->willThrowException(new \RuntimeException('stream error'));
        
        $stream->expects($this->once())->method('abort');
        
        $this->setPrivateProperty($session, 'stream', $stream);
        
        $session->abort();
        $this->assertTrue(true);
    }

    public function test_abort_ignores_persistence_delete_exception(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->expects($this->once())->method('delete')->willThrowException(new \RuntimeException('persistence error'));
        
        $session = new BatchZipSession('unused', 'out.zip', 6, 65536, $persistence);
        $this->setPrivateProperty($session, 'sessionId', 'test-id');
        
        $stream = $this->createMock(WritableStreamInterface::class);
        $stream->method('isOpen')->willReturn(true);
        $this->setPrivateProperty($session, 'stream', $stream);
        
        $session->abort();
        $this->assertTrue(true);
    }

    public function test_close_ignores_stream_close_exception(): void
    {
        $session = new BatchZipSession('unused', 'out.zip');
        
        $stream = $this->createMock(WritableStreamInterface::class);
        $stream->method('isOpen')->willReturn(true);
        $stream->expects($this->atLeastOnce())->method('close')->willThrowException(new \RuntimeException('stream error'));
        $this->setPrivateProperty($session, 'stream', $stream);
        
        $session->close();
        $this->assertTrue(true);
    }

    public function test_exists_delegates_to_persistence(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->expects($this->once())->method('exists')->with('session-id')->willReturn(true);
        $session = new BatchZipSession('unused', 'out.zip', 6, 65536, $persistence);
        
        $this->assertTrue($session->exists('session-id'));
    }

    public function test_cleanup_old_sessions_delegates_to_persistence(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->expects($this->once())->method('cleanup')->with(3600)->willReturn(5);
        $session = new BatchZipSession('unused', 'out.zip', 6, 65536, $persistence);
        
        $this->assertEquals(5, $session->cleanupOldSessions(3600));
    }

    public function test_list_sessions_delegates_to_persistence(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->expects($this->once())->method('listSessions')->willReturn(['s1', 's2']);
        $session = new BatchZipSession('unused', 'out.zip', 6, 65536, $persistence);
        
        $this->assertEquals(['s1', 's2'], $session->listSessions());
    }

    public function test_cleanup_deletes_session_and_closes(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->expects($this->once())->method('delete')->with('test-id');
        
        $session = new BatchZipSession('unused', 'out.zip', 6, 65536, $persistence);
        $this->setPrivateProperty($session, 'sessionId', 'test-id');
        
        $session->cleanup();
        $this->assertTrue(true);
    }

    public function test_start_session_throws_when_no_output_path_or_factory(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->method('acquireLock')->willReturn(true);
        $persistence->method('exists')->willReturn(true);
        $state = $this->createMock(\BatchZipStream\State\ArchiveState::class);
        $state->method('isFailed')->willReturn(false);
        $state->method('isCompleted')->willReturn(false);
        $persistence->method('load')->willReturn([
            'state' => $state,
            'entryStore' => $this->createMock(\BatchZipStream\State\FileEntryStore::class)
        ]);
        
        $session = new BatchZipSession('unused', '', 6, 65536, $persistence);
        
        $this->expectException(InvalidOperationException::class);
        $this->expectExceptionMessage('No stream factory or output path configured');
        
        $session->startSession('test');
    }

    public function test_destruct_ignores_save_progress_exception(): void
    {
        $persistence = $this->createMock(StatePersistenceInterface::class);
        $persistence->expects($this->once())->method('save')->willThrowException(new \RuntimeException('save error'));
        
        $session = new BatchZipSession('unused', 'out.zip', 6, 65536, $persistence);
        $this->setPrivateProperty($session, 'sessionId', 'test-id');
        
        $state = $this->createMock(ArchiveState::class);
        $state->method('isCompleted')->willReturn(false);
        $this->setPrivateProperty($session, 'state', $state);
        
        $session->__destruct();
        $this->neutralizeSession($session);
        $this->assertTrue(true);
    }

    public function test_destruct_ignores_stream_close_exception(): void
    {
        $session = new BatchZipSession('unused', 'out.zip');
        $this->setPrivateProperty($session, 'sessionId', 'test-id');
        
        $stream = $this->createMock(WritableStreamInterface::class);
        $stream->method('isOpen')->willReturn(true);
        $stream->expects($this->once())->method('close')->willThrowException(new \RuntimeException('stream error'));
        $this->setPrivateProperty($session, 'stream', $stream);
        
        $session->__destruct();
        $this->neutralizeSession($session);
        $this->assertTrue(true);
    }

    // ==================== Helper methods ====================

    private function registerSession(BatchZipSession $session): void
    {
        $this->sessionsToCleanup[] = $session;
    }

    private function setPrivateProperty(object $object, string $property, $value): void
    {
        $ref = new ReflectionClass($object);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    private function neutralizeSession(BatchZipSession $session): void
    {
        $this->setPrivateProperty($session, 'writer', null);
        $this->setPrivateProperty($session, 'entryStore', null);
        $this->setPrivateProperty($session, 'stream', null);
        $this->setPrivateProperty($session, 'state', null);
        $this->setPrivateProperty($session, 'sessionId', null);
        $this->setPrivateProperty($session, 'lockAcquired', false);
    }
}
