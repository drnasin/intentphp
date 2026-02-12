<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\Cache\ScanCache;
use PHPUnit\Framework\TestCase;

class CacheKeyCompositionTest extends TestCase
{
    public function test_different_php_versions_produce_different_keys(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123', '8.2');
        $v2 = ScanCache::computeVersion('10.0', 'abc123', '8.3');

        $this->assertNotSame($v1, $v2);
    }

    public function test_different_laravel_versions_produce_different_keys(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123', '8.2');
        $v2 = ScanCache::computeVersion('11.0', 'abc123', '8.2');

        $this->assertNotSame($v1, $v2);
    }

    public function test_different_mtimes_hashes_produce_different_keys_no_sha(): void
    {
        $v1 = ScanCache::computeVersion('10.0', null, '8.2', 'mtimes_hash_a');
        $v2 = ScanCache::computeVersion('10.0', null, '8.2', 'mtimes_hash_b');

        $this->assertNotSame($v1, $v2);
    }

    public function test_sha_trumps_mtimes(): void
    {
        $v1 = ScanCache::computeVersion('10.0', 'abc123', '8.2', 'mtimes_hash_a');
        $v2 = ScanCache::computeVersion('10.0', 'abc123', '8.2', 'mtimes_hash_b');

        $this->assertSame($v1, $v2);
    }

    public function test_default_php_version_uses_runtime_constants(): void
    {
        $withDefault = ScanCache::computeVersion('10.0', 'abc123');
        $withExplicit = ScanCache::computeVersion('10.0', 'abc123', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);

        $this->assertSame($withDefault, $withExplicit);
    }

    public function test_no_git_no_mtimes_is_stable(): void
    {
        $v1 = ScanCache::computeVersion('10.0', null, '8.2', null);
        $v2 = ScanCache::computeVersion('10.0', null, '8.2', null);

        $this->assertSame($v1, $v2);
    }

    public function test_output_is_sha1_format(): void
    {
        $version = ScanCache::computeVersion('10.0', 'abc123', '8.2');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $version);
    }

    public function test_uses_version_constant(): void
    {
        $this->assertSame('0.6.0', ScanCache::VERSION);
    }
}
