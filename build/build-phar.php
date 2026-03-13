<?php

declare(strict_types=1);

/**
 * PHAR build script for lphenom/queue.
 *
 * Packages src/ into a compressed PHAR archive with a PSR-4 autoloader stub.
 * This is a LIBRARY PHAR — it contains only the queue source files.
 * Dependencies (lphenom/db, lphenom/redis) must be installed separately
 * via Composer by the consuming application.
 *
 * Run with phar.readonly=0:
 *   php -d phar.readonly=0 build/build-phar.php
 */

$buildDir = dirname(__DIR__);
$pharFile = $buildDir . '/lphenom-queue.phar';

if (file_exists($pharFile)) {
    unlink($pharFile);
}

$phar = new Phar($pharFile, 0, 'lphenom-queue.phar');
$phar->startBuffering();

// Add all source files from src/
$srcBase     = $buildDir . '/src';
$srcIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcBase, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($srcIterator as $file) {
    /** @var SplFileInfo $file */
    $localPath = 'src/' . ltrim(str_replace($srcBase, '', $file->getPathname()), '/');
    $phar->addFile($file->getPathname(), $localPath);
}

// Bootstrap stub — provides a PSR-4 autoloader for LPhenom\Queue\ namespace
// Dependencies (lphenom/db, lphenom/redis) must be autoloaded separately.
$stub = <<<'STUB'
<?php
Phar::mapPhar('lphenom-queue.phar');

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'LPhenom\\Queue\\') !== 0) {
        return;
    }
    $relative = substr($class, strlen('LPhenom\\Queue\\'));
    $path     = 'phar://lphenom-queue.phar/src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->stopBuffering();

// Compress files with GZ
$phar->compressFiles(Phar::GZ);

$size  = number_format((int) filesize($pharFile));
$count = count($phar);

echo 'PHAR built: ' . $pharFile . PHP_EOL;
echo '  Size:  ' . $size . ' bytes' . PHP_EOL;
echo '  Files: ' . $count . PHP_EOL;
echo '=== PHAR build: OK ===' . PHP_EOL;

