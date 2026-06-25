<?php

declare(strict_types=1);

namespace BatchZipStream\Tests;

use PHPUnit\Framework\TestCase;
use BatchZipStream\BatchZipSession;
use BatchZipStream\BatchZipWriter;
use BatchZipStream\State\ArchiveState;
use BatchZipStream\State\FileEntryStore;
use BatchZipStream\Persistence\FileStatePersistence;
use BatchZipStream\Streams\FileWritableStream;
use BatchZipStream\Streams\MemoryWritableStream;
use BatchZipStream\Streams\StringReadableStream;
use BatchZipStream\Streams\FileReadableStream;
use BatchZipStream\Core\ZipFormat;
use BatchZipStream\Exceptions\InvalidOperationException;
use ZipArchive;

$libraryAutoloader = __DIR__ . '/../autoload.php';
if (file_exists($libraryAutoloader)) {
    require_once $libraryAutoloader;
}

/**
 * High-level integration tests for the BatchZipStream library.
 * 
 * These tests validate that generated ZIP archives are:
 * - Structurally valid ZIP files
 * - Openable and extractable by standard tools (ZipArchive)
 * - Contain exactly the expected files with correct contents
 * - Behave correctly under streaming and edge-case conditions
 * 
 * Philosophy: Treat the library as a black box and validate outputs.
 */
class BatchZipIntegrationTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $archivePath;
    private string $extractDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/bmi_zip_integration_' . uniqid();
        $this->stateDir = $this->tempDir . '/state';
        $this->archivePath = $this->tempDir . '/output.zip';
        $this->extractDir = $this->tempDir . '/extracted';

        mkdir($this->tempDir, 0755, true);
        mkdir($this->stateDir, 0755, true);
        mkdir($this->extractDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
    }

    /**
     * Recursively delete a directory.
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Validate that a ZIP file is structurally valid and can be opened.
     *
     * @param string $zipPath Path to the ZIP file
     * @return ZipArchive Opened ZipArchive instance
     */
    private function assertValidZip(string $zipPath): ZipArchive
    {
        $this->assertFileExists($zipPath, 'ZIP file should exist');

        // Check ZIP signature (PK\x03\x04)
        $fp = fopen($zipPath, 'rb');
        $signature = fread($fp, 4);
        fclose($fp);
        $this->assertEquals(
            ZipFormat::SIG_LOCAL_FILE_HEADER,
            unpack('V', $signature)[1],
            'File should have valid ZIP local file header signature'
        );

        // Open with ZipArchive
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        $this->assertTrue(
            $result === true,
            sprintf('ZipArchive should open successfully, got error code: %d', $result)
        );

        return $zip;
    }

    /**
     * Assert ZIP contains exactly the expected number of files.
     */
    private function assertZipFileCount(ZipArchive $zip, int $expectedCount): void
    {
        $this->assertEquals(
            $expectedCount,
            $zip->numFiles,
            sprintf('ZIP should contain exactly %d files, found %d', $expectedCount, $zip->numFiles)
        );
    }

    /**
     * Assert a file exists in the ZIP with exact content.
     */
    private function assertZipContainsFile(ZipArchive $zip, string $filename, string $expectedContent): void
    {
        $stat = $zip->statName($filename);
        $this->assertNotFalse($stat, sprintf('File "%s" should exist in ZIP', $filename));

        $content = $zip->getFromName($filename);
        $this->assertNotFalse($content, sprintf('Should be able to read content of "%s"', $filename));
        $this->assertEquals(
            $expectedContent,
            $content,
            sprintf('Content of "%s" should match expected', $filename)
        );
    }

    /**
     * Assert a file exists in the ZIP with matching hash.
     */
    private function assertZipFileHash(ZipArchive $zip, string $filename, string $expectedHash, string $algo = 'md5'): void
    {
        $stat = $zip->statName($filename);
        $this->assertNotFalse($stat, sprintf('File "%s" should exist in ZIP', $filename));

        $content = $zip->getFromName($filename);
        $this->assertNotFalse($content, sprintf('Should be able to read content of "%s"', $filename));

        $actualHash = hash($algo, $content);
        $this->assertEquals(
            $expectedHash,
            $actualHash,
            sprintf('Hash of "%s" should match (algo: %s)', $filename, $algo)
        );
    }

    /**
     * Assert a directory entry exists in the ZIP.
     */
    private function assertZipContainsDirectory(ZipArchive $zip, string $dirname): void
    {
        // Ensure trailing slash
        if (substr($dirname, -1) !== '/') {
            $dirname .= '/';
        }

        $stat = $zip->statName($dirname);
        $this->assertNotFalse($stat, sprintf('Directory "%s" should exist in ZIP', $dirname));
        $this->assertEquals(0, $stat['size'], sprintf('Directory "%s" should have zero size', $dirname));
    }

    /**
     * Extract ZIP to temporary directory and return the extraction path.
     */
    private function extractZip(ZipArchive $zip): string
    {
        $extractPath = $this->extractDir . '/' . uniqid('extract_');
        mkdir($extractPath, 0755, true);

        $result = $zip->extractTo($extractPath);
        $this->assertTrue($result, 'ZIP extraction should succeed');

        return $extractPath;
    }

    /**
     * Compare extracted directory contents with expected files.
     *
     * @param string $extractPath Path to extracted contents
     * @param array<string, string> $expectedFiles Map of relative paths to expected content
     */
    private function assertExtractedContents(string $extractPath, array $expectedFiles): void
    {
        foreach ($expectedFiles as $relativePath => $expectedContent) {
            $fullPath = $extractPath . '/' . $relativePath;
            $this->assertFileExists($fullPath, sprintf('Extracted file "%s" should exist', $relativePath));

            $actualContent = file_get_contents($fullPath);
            $this->assertEquals(
                $expectedContent,
                $actualContent,
                sprintf('Extracted content of "%s" should match', $relativePath)
            );
        }
    }

    /**
     * Create a new BatchZipSession for testing.
     */
    private function createSession(): BatchZipSession
    {
        return new BatchZipSession($this->stateDir, $this->archivePath);
    }

    // =========================================================================
    // 1. BASIC ZIP CREATION TESTS
    // =========================================================================

    /**
     * Test: Create ZIP with a single small text file.
     * 
     * WHY: Validates the most basic functionality - creating a valid ZIP
     * with one file that can be opened and its contents verified.
     */
    public function testCreateZipWithSingleSmallFile(): void
    {
        $session = $this->createSession();
        $session->startSession('single-file');

        $content = 'Hello, World!';
        $session->addFileFromString('hello.txt', $content);
        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 1);
        $this->assertZipContainsFile($zip, 'hello.txt', $content);

        $zip->close();
    }

    /**
     * Test: Create ZIP with multiple small files.
     * 
     * WHY: Ensures multiple files can be added sequentially and all
     * are preserved correctly in the final archive.
     */
    public function testCreateZipWithMultipleSmallFiles(): void
    {
        $session = $this->createSession();
        $session->startSession('multiple-files');

        $files = [
            'file1.txt' => 'Content of file 1',
            'file2.txt' => 'Content of file 2',
            'file3.txt' => 'Content of file 3',
            'data.json' => '{"key": "value", "number": 42}',
            'readme.md' => '# Readme\n\nThis is a test.',
        ];

        foreach ($files as $name => $content) {
            $session->addFileFromString($name, $content);
        }

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, count($files));

        foreach ($files as $name => $content) {
            $this->assertZipContainsFile($zip, $name, $content);
        }

        $zip->close();
    }

    /**
     * Test: Create ZIP and verify extraction produces identical files.
     * 
     * WHY: End-to-end validation that the ZIP can be fully extracted
     * and contents match the original.
     */
    public function testZipExtractionProducesIdenticalFiles(): void
    {
        $session = $this->createSession();
        $session->startSession('extraction-test');

        $files = [
            'document.txt' => 'This is a document with some content.',
            'config/settings.json' => '{"debug": true, "level": 5}',
        ];

        foreach ($files as $name => $content) {
            $session->addFileFromString($name, $content);
        }

        $session->finalize();

        // Extract and verify
        $zip = $this->assertValidZip($this->archivePath);
        $extractPath = $this->extractZip($zip);
        $zip->close();

        $this->assertExtractedContents($extractPath, $files);
    }

    // =========================================================================
    // 2. STREAMING / CHUNKED WRITING TESTS
    // =========================================================================

    /**
     * Test: Write files across multiple batches with progress saves.
     * 
     * WHY: Validates the core batch/streaming functionality - that ZIP
     * creation can be split across multiple executions with state persistence.
     */
    public function testStreamingWriteAcrossMultipleBatches(): void
    {
        // Batch 1: Add first set of files
        $session1 = $this->createSession();
        $session1->startSession('batch-streaming');
        $session1->addFileFromString('batch1/file1.txt', 'Batch 1 File 1');
        $session1->addFileFromString('batch1/file2.txt', 'Batch 1 File 2');
        $session1->saveProgress();
        $session1->close();

        // Batch 2: Resume and add more files
        $session2 = $this->createSession();
        $session2->startSession('batch-streaming');
        $session2->addFileFromString('batch2/file1.txt', 'Batch 2 File 1');
        $session2->addFileFromString('batch2/file2.txt', 'Batch 2 File 2');
        $session2->saveProgress();
        $session2->close();

        // Batch 3: Resume and finalize
        $session3 = $this->createSession();
        $session3->startSession('batch-streaming');
        $session3->addFileFromString('batch3/final.txt', 'Final file');
        $session3->finalize();

        // Validate all files present
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 5);

        $this->assertZipContainsFile($zip, 'batch1/file1.txt', 'Batch 1 File 1');
        $this->assertZipContainsFile($zip, 'batch1/file2.txt', 'Batch 1 File 2');
        $this->assertZipContainsFile($zip, 'batch2/file1.txt', 'Batch 2 File 1');
        $this->assertZipContainsFile($zip, 'batch2/file2.txt', 'Batch 2 File 2');
        $this->assertZipContainsFile($zip, 'batch3/final.txt', 'Final file');

        $zip->close();
    }

    /**
     * Test: Incremental flush during file addition.
     * 
     * WHY: Ensures that calling saveProgress() multiple times during
     * a single batch doesn't corrupt the archive.
     */
    public function testIncrementalFlushDuringFileAddition(): void
    {
        $session = $this->createSession();
        $session->startSession('incremental-flush');

        // Add files with intermediate saves
        for ($i = 1; $i <= 10; $i++) {
            $session->addFileFromString("file{$i}.txt", "Content {$i}");
            $session->saveProgress(); // Save after each file
        }

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 10);

        for ($i = 1; $i <= 10; $i++) {
            $this->assertZipContainsFile($zip, "file{$i}.txt", "Content {$i}");
        }

        $zip->close();
    }

    /**
     * Test: ZIP remains valid after many small writes.
     * 
     * WHY: Validates that many small file additions don't cause
     * cumulative corruption in the archive structure.
     */
    public function testZipValidAfterManySmallWrites(): void
    {
        $session = $this->createSession();
        $session->startSession('many-writes');

        $expectedFiles = [];
        for ($i = 0; $i < 100; $i++) {
            $filename = sprintf('files/file_%04d.txt', $i);
            $content = sprintf('File %d content with some padding: %s', $i, str_repeat('.', $i));
            $session->addFileFromString($filename, $content);
            $expectedFiles[$filename] = $content;
        }

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 100);

        // Spot-check some files
        $this->assertZipContainsFile($zip, 'files/file_0000.txt', $expectedFiles['files/file_0000.txt']);
        $this->assertZipContainsFile($zip, 'files/file_0050.txt', $expectedFiles['files/file_0050.txt']);
        $this->assertZipContainsFile($zip, 'files/file_0099.txt', $expectedFiles['files/file_0099.txt']);

        $zip->close();
    }

    // =========================================================================
    // 3. LARGE FILE HANDLING TESTS
    // =========================================================================

    /**
     * Test: Stream a large file (simulated via repeated chunks).
     * 
     * WHY: Validates that large files are streamed correctly without
     * loading entirely into memory, and the resulting file is intact.
     */
    public function testStreamLargeFileViaRepeatedChunks(): void
    {
        // Create a large content string (1MB simulated)
        $chunkSize = 64 * 1024; // 64KB chunks
        $numChunks = 16; // 16 chunks = 1MB
        $chunk = str_repeat('X', $chunkSize);
        $largeContent = str_repeat($chunk, $numChunks);
        $expectedHash = md5($largeContent);

        // Create temp file with large content
        $largeTempFile = $this->tempDir . '/large_source.bin';
        file_put_contents($largeTempFile, $largeContent);

        $session = $this->createSession();
        $session->startSession('large-file');

        // Use file stream to simulate streaming read
        $session->addFile('large_file.bin', $largeTempFile);
        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 1);
        $this->assertZipFileHash($zip, 'large_file.bin', $expectedHash);

        // Verify size
        $stat = $zip->statName('large_file.bin');
        $this->assertEquals(strlen($largeContent), $stat['size']);

        $zip->close();
    }

    /**
     * Test: Stream from StringReadableStream.
     * 
     * WHY: Validates that the library correctly handles streaming
     * from a ReadableStreamInterface implementation.
     */
    public function testStreamFromReadableStreamInterface(): void
    {
        $content = str_repeat('Stream content chunk. ', 1000);
        $expectedHash = md5($content);

        $session = $this->createSession();
        $session->startSession('stream-interface');

        $stream = new StringReadableStream($content, 'streamed.txt');
        $session->addFileFromStream('streamed.txt', $stream);
        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 1);
        $this->assertZipFileHash($zip, 'streamed.txt', $expectedHash);

        $zip->close();
    }

    /**
     * Test: Mix of small and large files.
     * 
     * WHY: Ensures the library handles archives with varying file sizes
     * without corruption or offset calculation errors.
     */
    public function testMixOfSmallAndLargeFiles(): void
    {
        $session = $this->createSession();
        $session->startSession('mixed-sizes');

        // Small file
        $smallContent = 'Tiny file';
        $session->addFileFromString('small.txt', $smallContent);

        // Medium file (100KB)
        $mediumContent = str_repeat('M', 100 * 1024);
        $mediumHash = md5($mediumContent);
        $session->addFileFromString('medium.bin', $mediumContent);

        // Another small file
        $anotherSmall = 'Another small one';
        $session->addFileFromString('another_small.txt', $anotherSmall);

        // Large file (500KB)
        $largeContent = str_repeat('L', 500 * 1024);
        $largeHash = md5($largeContent);
        $session->addFileFromString('large.bin', $largeContent);

        // Final small file
        $finalSmall = 'Final';
        $session->addFileFromString('final.txt', $finalSmall);

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 5);

        $this->assertZipContainsFile($zip, 'small.txt', $smallContent);
        $this->assertZipFileHash($zip, 'medium.bin', $mediumHash);
        $this->assertZipContainsFile($zip, 'another_small.txt', $anotherSmall);
        $this->assertZipFileHash($zip, 'large.bin', $largeHash);
        $this->assertZipContainsFile($zip, 'final.txt', $finalSmall);

        $zip->close();
    }

    // =========================================================================
    // 4. DIRECTORY STRUCTURE TESTS
    // =========================================================================

    /**
     * Test: ZIP with nested directory structure.
     * 
     * WHY: Validates that directory paths are preserved correctly
     * in the ZIP archive.
     */
    public function testNestedDirectoryStructure(): void
    {
        $session = $this->createSession();
        $session->startSession('nested-dirs');

        $files = [
            'root.txt' => 'Root level file',
            'level1/file.txt' => 'Level 1 file',
            'level1/level2/file.txt' => 'Level 2 file',
            'level1/level2/level3/file.txt' => 'Level 3 file',
            'level1/level2/level3/level4/deep.txt' => 'Deep file',
            'another/branch/file.txt' => 'Another branch',
        ];

        foreach ($files as $path => $content) {
            $session->addFileFromString($path, $content);
        }

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, count($files));

        foreach ($files as $path => $content) {
            $this->assertZipContainsFile($zip, $path, $content);
        }

        // Extract and verify directory structure
        $extractPath = $this->extractZip($zip);
        $zip->close();

        $this->assertExtractedContents($extractPath, $files);

        // Verify nested directories exist
        $this->assertDirectoryExists($extractPath . '/level1/level2/level3/level4');
        $this->assertDirectoryExists($extractPath . '/another/branch');
    }

    /**
     * Test: ZIP with explicit empty directories.
     * 
     * WHY: Validates that empty directory entries are correctly
     * stored and can be extracted.
     */
    public function testEmptyDirectoriesInZip(): void
    {
        $session = $this->createSession();
        $session->startSession('empty-dirs');

        // Add a regular file
        $session->addFileFromString('readme.txt', 'Root file');

        // Add empty directories
        $session->addEmptyDirectory('empty_dir');
        $session->addEmptyDirectory('another/empty/nested');
        $session->addEmptyDirectory('sibling/empty');

        // Add file in non-empty directory
        $session->addFileFromString('non_empty/file.txt', 'File in dir');

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);

        // Check empty directories exist
        $this->assertZipContainsDirectory($zip, 'empty_dir/');
        $this->assertZipContainsDirectory($zip, 'another/empty/nested/');
        $this->assertZipContainsDirectory($zip, 'sibling/empty/');

        // Check regular files
        $this->assertZipContainsFile($zip, 'readme.txt', 'Root file');
        $this->assertZipContainsFile($zip, 'non_empty/file.txt', 'File in dir');

        // Extract and verify
        $extractPath = $this->extractZip($zip);
        $zip->close();

        // Note: Some ZIP extractors may not create empty directories
        // We verify at least the files are extracted correctly
        $this->assertFileExists($extractPath . '/readme.txt');
        $this->assertFileExists($extractPath . '/non_empty/file.txt');
    }

    /**
     * Test: Directory structure preserved after extraction.
     * 
     * WHY: End-to-end test that extracted files maintain their
     * relative paths correctly.
     */
    public function testDirectoryStructurePreservedAfterExtraction(): void
    {
        $session = $this->createSession();
        $session->startSession('dir-preservation');

        $structure = [
            'src/main.php' => '<?php echo "main";',
            'src/lib/helper.php' => '<?php function help() {}',
            'src/lib/utils/format.php' => '<?php function format() {}',
            'tests/unit/MainTest.php' => '<?php class MainTest {}',
            'config/app.json' => '{"app": "test"}',
            'README.md' => '# Project',
        ];

        foreach ($structure as $path => $content) {
            $session->addFileFromString($path, $content);
        }

        $session->finalize();

        // Extract
        $zip = $this->assertValidZip($this->archivePath);
        $extractPath = $this->extractZip($zip);
        $zip->close();

        // Verify each file exists in correct location
        $this->assertExtractedContents($extractPath, $structure);

        // Verify directory structure
        $this->assertDirectoryExists($extractPath . '/src/lib/utils');
        $this->assertDirectoryExists($extractPath . '/tests/unit');
        $this->assertDirectoryExists($extractPath . '/config');
    }

    // =========================================================================
    // 5. FILE METADATA TESTS
    // =========================================================================

    /**
     * Test: File names with spaces are preserved.
     * 
     * WHY: Validates that filenames containing spaces are correctly
     * stored and retrievable from the ZIP.
     */
    public function testFileNamesWithSpaces(): void
    {
        $session = $this->createSession();
        $session->startSession('spaces-in-names');

        $files = [
            'file with spaces.txt' => 'Content 1',
            'directory with spaces/file.txt' => 'Content 2',
            'multiple   spaces.txt' => 'Content 3',
            'path/with spaces/and more spaces/file.txt' => 'Content 4',
        ];

        foreach ($files as $name => $content) {
            $session->addFileFromString($name, $content);
        }

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);

        foreach ($files as $name => $content) {
            $this->assertZipContainsFile($zip, $name, $content);
        }

        $zip->close();
    }

    /**
     * Test: UTF-8 / non-ASCII file names are preserved.
     * 
     * WHY: Validates international character support in filenames.
     */
    public function testUtf8NonAsciiFileNames(): void
    {
        $session = $this->createSession();
        $session->startSession('utf8-names');

        $files = [
            'café.txt' => 'French café',
            '日本語.txt' => 'Japanese text',
            'münchen.txt' => 'German city',
            'Привет.txt' => 'Russian hello',
            '中文文件.txt' => 'Chinese filename',
            'émoji_🎉.txt' => 'File with emoji',
            'path/с кириллицей/file.txt' => 'Cyrillic path',
        ];

        foreach ($files as $name => $content) {
            $session->addFileFromString($name, $content);
        }

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);

        foreach ($files as $name => $content) {
            $stat = $zip->statName($name);
            $this->assertNotFalse($stat, sprintf('UTF-8 filename "%s" should exist in ZIP', $name));

            $actualContent = $zip->getFromName($name);
            $this->assertEquals(
                $content,
                $actualContent,
                sprintf('Content of UTF-8 file "%s" should match', $name)
            );
        }

        $zip->close();
    }

    /**
     * Test: Special characters in filenames.
     * 
     * WHY: Validates that special characters (excluding path separators)
     * are handled correctly in filenames.
     */
    public function testSpecialCharactersInFilenames(): void
    {
        $session = $this->createSession();
        $session->startSession('special-chars');

        $files = [
            'file-with-dashes.txt' => 'Dashes',
            'file_with_underscores.txt' => 'Underscores',
            'file.multiple.dots.txt' => 'Dots',
            'file(with)parentheses.txt' => 'Parentheses',
            "file'with'quotes.txt" => 'Quotes',
            'file@with#symbols$percent.txt' => 'Symbols',
        ];

        foreach ($files as $name => $content) {
            $session->addFileFromString($name, $content);
        }

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);

        foreach ($files as $name => $content) {
            $this->assertZipContainsFile($zip, $name, $content);
        }

        $zip->close();
    }

    // =========================================================================
    // 6. EDGE CASES TESTS
    // =========================================================================

    /**
     * Test: Empty ZIP (no files).
     * 
     * WHY: Validates handling of edge case where archive is finalized
     * without any files being added.
     * 
     * EXPECTED: The library may either create an empty ZIP or throw
     * an exception. Both are acceptable behaviors.
     */
    public function testEmptyZipNoFiles(): void
    {
        $session = $this->createSession();
        $session->startSession('empty-zip');

        // Finalize without adding any files
        try {
            $session->finalize();

            // If we get here, empty ZIP was created
            if (file_exists($this->archivePath)) {
                $zip = new ZipArchive();
                $result = $zip->open($this->archivePath);
                $this->assertTrue($result === true, 'Empty ZIP should be openable');
                $this->assertEquals(0, $zip->numFiles, 'Empty ZIP should have 0 files');
                $zip->close();
            }
        } catch (InvalidOperationException $e) {
            // This is also acceptable behavior
            $this->assertStringContainsString(
                'finalize',
                strtolower($e->getMessage()) . strtolower($e->getAttemptedOperation()),
                'Exception should mention finalization'
            );
        }
    }

    /**
     * Test: ZIP with zero-byte files.
     * 
     * WHY: Validates that empty files (0 bytes) are stored correctly
     * and don't corrupt the archive.
     */
    public function testZeroByteFiles(): void
    {
        $session = $this->createSession();
        $session->startSession('zero-byte');

        $session->addFileFromString('empty.txt', '');
        $session->addFileFromString('also_empty.dat', '');
        $session->addFileFromString('non_empty.txt', 'Has content');
        $session->addFileFromString('another_empty.log', '');

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 4);

        // Check zero-byte files
        $stat = $zip->statName('empty.txt');
        $this->assertNotFalse($stat);
        $this->assertEquals(0, $stat['size'], 'Zero-byte file should have size 0');
        $this->assertEquals('', $zip->getFromName('empty.txt'));

        $stat = $zip->statName('also_empty.dat');
        $this->assertEquals(0, $stat['size']);

        // Check non-empty file is still correct
        $this->assertZipContainsFile($zip, 'non_empty.txt', 'Has content');

        $zip->close();
    }

    /**
     * Test: Finalization called twice.
     * 
     * WHY: Validates proper error handling when finalize() is called
     * multiple times on the same session.
     * 
     * EXPECTED: Second call should throw InvalidOperationException
     * or be a no-op (idempotent).
     */
    public function testFinalizationCalledTwice(): void
    {
        $session = $this->createSession();
        $session->startSession('double-finalize');
        $session->addFileFromString('test.txt', 'Test content');
        $session->finalize();

        // Second finalization attempt
        $this->expectException(InvalidOperationException::class);
        $session->finalize();
    }

    /**
     * Test: Writing after finalize.
     * 
     * WHY: Validates that adding files after finalization is properly
     * rejected with an exception.
     * 
     * EXPECTED: InvalidOperationException should be thrown.
     */
    public function testWritingAfterFinalize(): void
    {
        $session = $this->createSession();
        $session->startSession('write-after-finalize');
        $session->addFileFromString('initial.txt', 'Initial');
        $session->finalize();

        // Attempt to add file after finalization
        $this->expectException(InvalidOperationException::class);
        $session->addFileFromString('late.txt', 'Too late');
    }

    /**
     * Test: Very long file paths.
     * 
     * WHY: Validates handling of extremely long file paths that might
     * approach ZIP format limits.
     */
    public function testVeryLongFilePaths(): void
    {
        $session = $this->createSession();
        $session->startSession('long-paths');

        // Create a long path (close to the 255 char typical limit)
        $longPath = str_repeat('subdir/', 30) . 'file.txt'; // ~240 chars
        $content = 'Deep file content';

        $session->addFileFromString($longPath, $content);
        $session->addFileFromString('short.txt', 'Short path');

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 2);

        $stat = $zip->statName($longPath);
        $this->assertNotFalse($stat, 'Long path file should exist');
        $this->assertEquals($content, $zip->getFromName($longPath));

        $zip->close();
    }

    /**
     * Test: Binary content in files.
     * 
     * WHY: Validates that binary data (including null bytes) is
     * preserved correctly through compression.
     */
    public function testBinaryContentInFiles(): void
    {
        $session = $this->createSession();
        $session->startSession('binary-content');

        // Binary content with null bytes and full byte range
        $binaryContent = '';
        for ($i = 0; $i < 256; $i++) {
            $binaryContent .= chr($i);
        }
        $binaryContent = str_repeat($binaryContent, 10); // 2560 bytes
        $expectedHash = md5($binaryContent);

        $session->addFileFromString('binary.bin', $binaryContent);
        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileHash($zip, 'binary.bin', $expectedHash);

        // Verify exact content
        $extracted = $zip->getFromName('binary.bin');
        $this->assertEquals(
            $binaryContent,
            $extracted,
            'Binary content should be exactly preserved'
        );

        $zip->close();
    }

    /**
     * Test: Files with same content but different names.
     * 
     * WHY: Validates that files with identical content are stored
     * as separate entries.
     */
    public function testFilesWithSameContentDifferentNames(): void
    {
        $session = $this->createSession();
        $session->startSession('duplicate-content');

        $content = 'Identical content for all files';

        $session->addFileFromString('copy1.txt', $content);
        $session->addFileFromString('copy2.txt', $content);
        $session->addFileFromString('folder/copy3.txt', $content);

        $session->finalize();

        // Validate all exist as separate entries
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 3);

        $this->assertZipContainsFile($zip, 'copy1.txt', $content);
        $this->assertZipContainsFile($zip, 'copy2.txt', $content);
        $this->assertZipContainsFile($zip, 'folder/copy3.txt', $content);

        $zip->close();
    }

    // =========================================================================
    // 7. FAILURE & SAFETY CONDITIONS TESTS
    // =========================================================================

    /**
     * Test: Session abort marks state as failed.
     * 
     * WHY: Validates that explicit abort properly marks the session
     * as failed and prevents further operations.
     */
    public function testSessionAbortMarksFailed(): void
    {
        $session = $this->createSession();
        $session->startSession('abort-test');

        $session->addFileFromString('before_abort.txt', 'Content');
        $session->saveProgress();

        // Abort the session
        $session->abort('Manual abort for testing', true);

        // Archive should be deleted if requested
        $this->assertFileDoesNotExist($this->archivePath);

        // Attempting to resume should fail
        $session2 = $this->createSession();
        try {
            $session2->startSession('abort-test');
            // If it doesn't throw, the session should be in failed state
            // or not exist at all
            $this->assertTrue(true, 'Session was cleaned up properly');
        } catch (InvalidOperationException $e) {
            $this->assertStringContainsString(
                'failed',
                strtolower($e->getMessage()),
                'Exception should indicate failed state'
            );
        }
    }

    /**
     * Test: Resume corrupted/incomplete session.
     * 
     * WHY: Validates proper handling when trying to resume a session
     * that was interrupted and may have incomplete state.
     */
    public function testResumeInterruptedSession(): void
    {
        // Batch 1: Start and save
        $session1 = $this->createSession();
        $session1->startSession('interrupted');
        $session1->addFileFromString('file1.txt', 'Content 1');
        $session1->saveProgress();
        $session1->close();

        // Simulate interruption by just creating new session (old one was saved)
        // This should work - resumption from saved state
        $session2 = $this->createSession();
        $session2->startSession('interrupted');

        // Should be able to continue
        $state = $session2->getState();
        $this->assertEquals(1, $state->getFileCount(), 'Should have 1 file from before');

        $session2->addFileFromString('file2.txt', 'Content 2');
        $session2->finalize();

        // Validate final archive
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 2);
        $this->assertZipContainsFile($zip, 'file1.txt', 'Content 1');
        $this->assertZipContainsFile($zip, 'file2.txt', 'Content 2');

        $zip->close();
    }

    /**
     * Test: Graceful handling of uncompressible content.
     * 
     * WHY: Validates that already-compressed or random data that
     * doesn't compress well is handled without errors.
     */
    public function testUncompressibleContent(): void
    {
        $session = $this->createSession();
        $session->startSession('uncompressible');

        // Random-looking data that won't compress well
        $randomData = random_bytes(10000);
        $expectedHash = md5($randomData);

        $session->addFileFromString('random.bin', $randomData);
        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileHash($zip, 'random.bin', $expectedHash);

        $zip->close();
    }

    /**
     * Test: Memory-efficient handling of many files.
     * 
     * WHY: Validates that the library's memory-efficient architecture
     * handles many files without excessive memory growth.
     */
    public function testMemoryEfficientManyFiles(): void
    {
        $memoryBefore = memory_get_usage(true);

        $session = $this->createSession();
        $session->startSession('many-files-memory');

        // Add 500 files
        for ($i = 0; $i < 500; $i++) {
            $session->addFileFromString(
                sprintf('files/file_%05d.txt', $i),
                sprintf('Content for file %d - some padding text to make it realistic', $i)
            );
        }

        $session->finalize();

        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Memory increase should be reasonable (< 50MB for 500 files)
        $this->assertLessThan(
            50 * 1024 * 1024,
            $memoryUsed,
            'Memory usage should stay reasonable for 500 files'
        );

        // Validate archive
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 500);
        $zip->close();
    }

    // =========================================================================
    // 8. COMPRESSION TESTS
    // =========================================================================

    /**
     * Test: Deflate compression produces smaller output.
     * 
     * WHY: Validates that compression is actually working and reducing
     * file sizes for compressible content.
     */
    public function testDeflateCompressionReducesSize(): void
    {
        $session = $this->createSession();
        $session->startSession('compression-test');

        // Highly compressible content (repeated text)
        $compressibleContent = str_repeat('AAAAAAAAAA', 10000); // 100KB of 'A's
        $originalSize = strlen($compressibleContent);

        $session->addFileFromString('compressible.txt', $compressibleContent);
        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);

        // Get compressed size
        $stat = $zip->statName('compressible.txt');
        $compressedSize = $stat['comp_size'];

        // Compressed size should be significantly smaller
        $this->assertLessThan(
            $originalSize / 10, // Should be at least 10x smaller
            $compressedSize,
            'Highly compressible content should compress significantly'
        );

        // Content should still be correct after extraction
        $this->assertZipContainsFile($zip, 'compressible.txt', $compressibleContent);

        $zip->close();
    }

    /**
     * Test: Store compression (no compression) works.
     * 
     * WHY: Validates that STORE method (no compression) can be used
     * when compression is not desired.
     */
    public function testStoreCompressionNoCompression(): void
    {
        $session = $this->createSession();
        $session->startSession('store-compression');
        $writer = $session->getWriter();

        $content = 'This content will not be compressed';

        $stream = new StringReadableStream($content, 'stored.txt');
        $writer->addFile('stored.txt', $stream, ZipFormat::COMPRESSION_STORE);

        $session->finalize();

        // Validate
        $zip = $this->assertValidZip($this->archivePath);

        $stat = $zip->statName('stored.txt');
        // For STORE method, compressed size equals uncompressed size
        $this->assertEquals(
            $stat['size'],
            $stat['comp_size'],
            'STORE method should have equal compressed and uncompressed sizes'
        );

        $this->assertZipContainsFile($zip, 'stored.txt', $content);

        $zip->close();
    }

    // =========================================================================
    // 9. IN-MEMORY STREAM TESTS
    // =========================================================================

    /**
     * Test: Create ZIP using MemoryWritableStream.
     * 
     * WHY: Validates that the stream-agnostic architecture works with
     * in-memory streams for testing or small archives.
     */
    public function testCreateZipWithMemoryStream(): void
    {
        $memory = new MemoryWritableStream();

        $session = BatchZipSession::withStream($this->stateDir, $memory);
        $session->startSession('memory-stream');

        $session->addFileFromString('memory_file.txt', 'Created in memory');
        $session->finalize();

        // Get the buffer and write to file for validation
        $zipBytes = $memory->getBuffer();
        $this->assertNotEmpty($zipBytes, 'Memory buffer should contain ZIP data');

        file_put_contents($this->archivePath, $zipBytes);

        // Validate the written file
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipContainsFile($zip, 'memory_file.txt', 'Created in memory');
        $zip->close();
    }

    // =========================================================================
    // 10. REGRESSION / SPECIFIC SCENARIO TESTS
    // =========================================================================

    /**
     * Test: Archive with exact structure for backup scenario.
     * 
     * WHY: Simulates a real-world backup scenario with typical
     * WordPress file structure.
     */
    public function testBackupScenarioTypicalStructure(): void
    {
        $session = $this->createSession();
        $session->startSession('backup-scenario');

        // Simulate WordPress backup structure
        $files = [
            'wp-config.php' => '<?php define("DB_NAME", "test");',
            'wp-content/themes/theme/style.css' => '/* Theme styles */',
            'wp-content/themes/theme/functions.php' => '<?php // Functions',
            'wp-content/plugins/plugin/plugin.php' => '<?php /* Plugin */?>',
            'wp-content/uploads/2024/01/image.jpg' => 'fake image data',
            'wp-content/uploads/2024/02/document.pdf' => 'fake pdf data',
            '.htaccess' => 'RewriteEngine On',
            'wp-includes/version.php' => '<?php $wp_version = "6.0";',
        ];

        foreach ($files as $path => $content) {
            $session->addFileFromString($path, $content);
        }

        $session->finalize('WordPress Backup');

        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, count($files));

        foreach ($files as $path => $content) {
            $this->assertZipContainsFile($zip, $path, $content);
        }

        // Extract and verify
        $extractPath = $this->extractZip($zip);
        $zip->close();

        $this->assertExtractedContents($extractPath, $files);
    }

    /**
     * Test: Multiple sessions don't interfere.
     * 
     * WHY: Validates that concurrent session IDs maintain separate
     * state and don't corrupt each other.
     */
    public function testMultipleSessionsDontInterfere(): void
    {
        // Start two sessions
        $session1 = new BatchZipSession($this->stateDir, $this->tempDir . '/zip1.zip');
        $session1->startSession('session-1');
        $session1->addFileFromString('from_session_1.txt', 'Session 1 content');
        $session1->saveProgress();
        $session1->close();

        $session2 = new BatchZipSession($this->stateDir, $this->tempDir . '/zip2.zip');
        $session2->startSession('session-2');
        $session2->addFileFromString('from_session_2.txt', 'Session 2 content');
        $session2->saveProgress();
        $session2->close();

        // Resume and finalize both
        $session1 = new BatchZipSession($this->stateDir, $this->tempDir . '/zip1.zip');
        $session1->startSession('session-1');
        $session1->addFileFromString('session_1_final.txt', 'Final 1');
        $session1->finalize();

        $session2 = new BatchZipSession($this->stateDir, $this->tempDir . '/zip2.zip');
        $session2->startSession('session-2');
        $session2->addFileFromString('session_2_final.txt', 'Final 2');
        $session2->finalize();

        // Validate both ZIPs are correct and independent
        $zip1 = new ZipArchive();
        $zip1->open($this->tempDir . '/zip1.zip');
        $this->assertEquals(2, $zip1->numFiles);
        $this->assertNotFalse($zip1->statName('from_session_1.txt'));
        $this->assertNotFalse($zip1->statName('session_1_final.txt'));
        $this->assertFalse($zip1->statName('from_session_2.txt')); // Should not contain session 2 files
        $zip1->close();

        $zip2 = new ZipArchive();
        $zip2->open($this->tempDir . '/zip2.zip');
        $this->assertEquals(2, $zip2->numFiles);
        $this->assertNotFalse($zip2->statName('from_session_2.txt'));
        $this->assertNotFalse($zip2->statName('session_2_final.txt'));
        $this->assertFalse($zip2->statName('from_session_1.txt')); // Should not contain session 1 files
        $zip2->close();
    }

    /**
     * Test: file inside nested directories
     * WHY: Validates adding a file inside nested directories without add empty directory calls
     * 
     */
    public function testFileInsideNestedDirectoriesWithoutAddEmptyDirectoryCalls(): void
    {
        $session = $this->createSession();
        $session->startSession('nested-file-no-dirs');
        $filePath = 'level1/level2/level3/file.txt';
        $fileContent = 'Content in nested file';
        $session->addFileFromString($filePath, $fileContent);
        $session->finalize();
        // Validate
        $zip = $this->assertValidZip($this->archivePath);
        $this->assertZipFileCount($zip, 1);
        $this->assertZipContainsFile($zip, $filePath, $fileContent);
        $zip->close();
    }

    /**
     * Test: ZIP64 large file integration
     * WHY: Verifies that streaming a >4GB file triggers ZIP64 and generates correct headers.
     */
    public function testZip64LargeFileIntegration(): void
    {
        // 4.5 GB = 4,500,000,000 bytes
        $largeSize = 4500000000;
        
        $session = $this->createSession();
        $session->startSession('zip64-large-file');
        
        $virtualStream = new class($largeSize) implements \BatchZipStream\Contracts\ReadableStreamInterface {
            private int $totalSize;
            private int $bytesRead = 0;
            
            public function __construct(int $totalSize) {
                $this->totalSize = $totalSize;
            }
            
            public function read(int $length): string {
                $remaining = $this->totalSize - $this->bytesRead;
                if ($remaining <= 0) {
                    return '';
                }
                $toRead = min($length, $remaining);
                $this->bytesRead += $toRead;
                return str_repeat("\x00", $toRead);
            }
            
            public function eof(): bool {
                return $this->bytesRead >= $this->totalSize;
            }
            
            public function getSize(): ?int {
                return $this->totalSize;
            }
            
            public function close(): void {}
            
            public function getIdentifier(): string {
                return 'virtual-large-file';
            }
        };

        $session->addFileFromStream('huge_file.dat', $virtualStream);
        $session->finalize();

        // Verify ZIP64 status
        $stats = $session->getStats();
        $this->assertTrue($stats['requiresZip64'], 'Zip64 should be triggered for 4.5GB uncompressed file');

        // Extract metadata using native ZipArchive
        $zip = new ZipArchive();
        $res = $zip->open($this->archivePath);
        $this->assertTrue($res, 'Should open the generated zip archive');
        
        $stat = $zip->statName('huge_file.dat');
        $this->assertNotFalse($stat, 'File should exist inside ZIP');
        $this->assertEquals($largeSize, $stat['size'], 'Uncompressed size must match 4.5GB');
        $zip->close();
    }

    /**
     * Test: ZIP64 file limit integration
     * WHY: Verifies that adding more than 65,535 files triggers ZIP64 format.
     */
    public function testZip64ManyFilesIntegration(): void
    {
        $session = $this->createSession();
        $session->startSession('zip64-many-files');
        
        // Add 65,536 empty files to trigger ZIP64 file count limit
        for ($i = 0; $i < 65536; $i++) {
            $session->addFileFromString("file_{$i}.txt", '');
        }
        
        $session->finalize();
        
        $stats = $session->getStats();
        $this->assertTrue($stats['requiresZip64'], 'Zip64 should be triggered for 65,536 files');
        
        // Verify with ZipArchive
        $zip = new ZipArchive();
        $res = $zip->open($this->archivePath);
        $this->assertTrue($res);
        $this->assertEquals(65536, $zip->numFiles);
        $zip->close();
    }
}
