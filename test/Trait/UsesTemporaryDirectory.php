<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Test\Trait;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function bin2hex;
use function chdir;
use function chmod;
use function dirname;
use function file_put_contents;
use function getcwd;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

/**
 * Gives each test its own scratch directory, removed again in tearDown along
 * with any change to the working directory.
 */
trait UsesTemporaryDirectory
{
    private string $temporaryDirectory;

    private string $originalWorkingDirectory;

    protected function setUpTemporaryDirectory(): void
    {
        $this->originalWorkingDirectory = (string) getcwd();
        $this->temporaryDirectory       = sys_get_temp_dir()
        . '/contenir-maintenance-mezzio-'
        . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, permissions: 0o700, recursive: true);
    }

    protected function tearDownTemporaryDirectory(): void
    {
        chdir($this->originalWorkingDirectory);

        if (! is_dir($this->temporaryDirectory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temporaryDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            chmod($entry->getPathname(), permissions: 0o700);
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
                continue;
            }

            unlink($entry->getPathname());
        }

        rmdir($this->temporaryDirectory);
    }

    private function temporaryPath(string $relativePath): string
    {
        return "{$this->temporaryDirectory}/{$relativePath}";
    }

    private function writeTemporaryFile(string $relativePath, string $contents): string
    {
        $path = $this->temporaryPath($relativePath);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), permissions: 0o700, recursive: true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function changeWorkingDirectoryToTemporary(): void
    {
        chdir($this->temporaryDirectory);
    }
}
