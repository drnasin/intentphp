<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Cache;

class ScanCache
{
    /** Bump this on each release to invalidate caches across upgrades. */
    public const VERSION = '1.1.0';

    private const VERSION_FILE = '.version';

    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Get a cached value. Returns null on miss or version mismatch.
     */
    public function get(string $key, string $version): mixed
    {
        if (! $this->enabled) {
            return null;
        }

        if (! $this->isVersionCurrent($version)) {
            $this->clear();

            return null;
        }

        $path = $this->filePath($key);

        if (! file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $data = @unserialize($contents);

        return $data !== false ? $data : null;
    }

    /**
     * Store a value in the cache.
     */
    public function put(string $key, mixed $data, string $version): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        file_put_contents(
            $this->cacheDir . DIRECTORY_SEPARATOR . self::VERSION_FILE,
            $version,
        );

        file_put_contents($this->filePath($key), serialize($data));
    }

    /**
     * Remove all cache files.
     */
    public function clear(): void
    {
        if (! is_dir($this->cacheDir)) {
            return;
        }

        $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*');

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Compute a cache version key from package version, PHP version, Laravel version, and git SHA or mtimes hash.
     */
    public static function computeVersion(
        string $laravelVersion,
        ?string $gitSha = null,
        ?string $phpVersion = null,
        ?string $mtimesHash = null,
    ): string {
        $phpVersion ??= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        $parts = [
            'guard:' . self::VERSION,
            'php:' . $phpVersion,
            'laravel:' . $laravelVersion,
        ];

        if ($gitSha !== null) {
            $parts[] = 'sha:' . $gitSha;
        } elseif ($mtimesHash !== null) {
            $parts[] = 'mtimes:' . $mtimesHash;
        }

        return sha1(implode('|', $parts));
    }

    /**
     * Compute a hash of file modification times for cache invalidation in non-git environments.
     *
     * @param string[] $paths Absolute paths to files or directories to scan
     */
    public static function computeMtimesHash(array $paths): ?string
    {
        $entries = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $normalized = str_replace('\\', '/', $path);
                $entries[$normalized] = @filemtime($path);
            } elseif (is_dir($path)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                );

                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $normalized = str_replace('\\', '/', $file->getPathname());
                        $entries[$normalized] = $file->getMTime();
                    }
                }
            }
        }

        if (empty($entries)) {
            return null;
        }

        ksort($entries);

        $hashInput = '';
        foreach ($entries as $filePath => $mtime) {
            $hashInput .= $filePath . ':' . $mtime . "\n";
        }

        return sha1($hashInput);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    private function isVersionCurrent(string $version): bool
    {
        $versionFile = $this->cacheDir . DIRECTORY_SEPARATOR . self::VERSION_FILE;

        if (! file_exists($versionFile)) {
            return false;
        }

        return trim((string) file_get_contents($versionFile)) === $version;
    }

    private function filePath(string $key): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.cache';
    }
}
