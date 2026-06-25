<?php

declare(strict_types=1);

/**
 * Batch ZIP Stream Autoloader
 * 
 * This file provides PSR-4 compatible autoloading for the batch-zip-stream namespace.
 * Include this file to use the library without Composer.
 * 
 * Usage:
 * require_once __DIR__ . '/path/to/batch-zip-stream/autoload.php'; 
 */

spl_autoload_register(function (string $class): void {
    // Namespace prefix
    $prefix = 'BatchZpStream\\';

    // Base directory for the namespace
    $baseDir = __DIR__ . '/src/';

    // Check if the class uses the namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relativeClass = substr($class, $len);

    // Build the file path
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    // Load the file if it exists
    if (file_exists($file)) {
        require $file;
    }
});
