<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\Cache\ScanCache;
use PHPUnit\Framework\TestCase;

class ScanCacheTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/guard_cache_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);

        parent::tearDown();
    }

    // ── Basic get/put ───────────────────────────────────────────────

    public function test_put_and_get(): void
    {
        $cache = new ScanCache($this->tempDir);
        $version = 'v1';

        $cache->put('test_key', ['hello' => 'world'], $version);
        $result = $cache->get('test_key', $version);

        $this->assertSame(['hello' => 'world'], $result);
    }

    public function test_get_returns_null_on_miss(): void
    {
        $cache = new ScanCache($this->tempDir);

        $this->assertNull($cache->get('nonexistent', 'v1'));
    }

    // ── Version invalidation ────────────────────────────────────────

    public function test_version_mismatch_returns_null(): void
    {
        $cache = new ScanCache($this->tempDir);

        $cache->put('data', ['cached'], 'v1');
        $result = $cache->get('data', 'v2');

        $this->assertNull($result);
    }

    public function test_version_mismatch_clears_old_cache(): void
    {
        $cache = new ScanCache($this->tempDir);

        $cache->put('data', ['old'], 'v1');
        $cache->get('data', 'v2'); // triggers clear

        // Re-check with old version
        $this->assertNull($cache->get('data', 'v1'));
    }

    // ── Disabled cache ──────────────────────────────────────────────

    public function test_disabled_cache_returns_null(): void
    {
        $cache = new ScanCache($this->tempDir, enabled: false);

        $cache->put('key', 'value', 'v1');
        $this->assertNull($cache->get('key', 'v1'));
    }

    public function test_is_enabled(): void
    {
        $enabled = new ScanCache($this->tempDir, enabled: true);
        $disabled = new ScanCache($this->tempDir, enabled: false);

        $this->assertTrue($enabled->isEnabled());
        $this->assertFalse($disabled->isEnabled());
    }

    // ── Clear ───────────────────────────────────────────────────────

    public function test_clear(): void
    {
        $cache = new ScanCache($this->tempDir);

        $cache->put('a', 'data_a', 'v1');
        $cache->put('b', 'data_b', 'v1');
        $cache->clear();

        $this->assertNull($cache->get('a', 'v1'));
        $this->assertNull($cache->get('b', 'v1'));
    }

    // ── computeVersion ──────────────────────────────────────────────

    public function test_compute_version_stable(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123');
        $v2 = ScanCache::computeVersion('10.0', 'abc123');

        $this->assertSame($v1, $v2);
    }

    public function test_compute_version_changes_with_sha(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123');
        $v2 = ScanCache::computeVersion('10.0', 'def456');

        $this->assertNotSame($v1, $v2);
    }

    public function test_compute_version_changes_with_app_version(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123');
        $v2 = ScanCache::computeVersion('11.0', 'abc123');

        $this->assertNotSame($v1, $v2);
    }

    public function test_compute_version_without_sha(): void
    {
        $v1 = ScanCache::computeVersion('10.0', null);
        $v2 = ScanCache::computeVersion('10.0', 'abc123');

        $this->assertNotSame($v1, $v2);
    }

    // ── Enriched computeVersion ────────────────────────────────────

    public function test_php_version_affects_key(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123', '8.2');
        $v2 = ScanCache::computeVersion('10.0', 'abc123', '8.3');

        $this->assertNotSame($v1, $v2);
    }

    public function test_laravel_version_separation(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123', '8.2');
        $v2 = ScanCache::computeVersion('11.0', 'abc123', '8.2');

        $this->assertNotSame($v1, $v2);
    }

    public function test_mtimes_hash_affects_key_when_no_sha(): void
    {
        $v1 = ScanCache::computeVersion('10.0', null, '8.2', 'hash_a');
        $v2 = ScanCache::computeVersion('10.0', null, '8.2', 'hash_b');

        $this->assertNotSame($v1, $v2);
    }

    public function test_sha_precedence_over_mtimes(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123', '8.2', 'hash_a');
        $v2 = ScanCache::computeVersion('10.0', 'abc123', '8.2', 'hash_b');

        $this->assertSame($v1, $v2);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');

        if ($files !== false) {
            foreach ($files as $file) {
                is_dir($file) ? $this->removeDir($file) : @unlink($file);
            }
        }

        @rmdir($dir);
    }
}
