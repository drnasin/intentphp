<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit;

use IntentPHP\Guard\Scan\BaselineManager;
use IntentPHP\Guard\Scan\Finding;
use IntentPHP\Guard\Scan\InlineIgnoreManager;
use PHPUnit\Framework\TestCase;

class SuppressionTest extends TestCase
{
    // --- Finding suppression ---

    public function test_finding_is_not_suppressed_by_default(): void
    {
        $finding = Finding::high(check: 'test', message: 'msg');

        $this->assertFalse($finding->isSuppressed());
        $this->assertNull($finding->suppressed_reason);
    }

    public function test_with_suppression_returns_suppressed_finding(): void
    {
        $finding = Finding::high(check: 'test', message: 'msg');
        $suppressed = $finding->withSuppression('baseline');

        $this->assertTrue($suppressed->isSuppressed());
        $this->assertSame('baseline', $suppressed->suppressed_reason);
        $this->assertFalse($finding->isSuppressed()); // original unchanged
    }

    public function test_to_array_includes_suppressed_reason(): void
    {
        $finding = Finding::high(check: 'test', message: 'msg')
            ->withSuppression('inline-ignore');

        $arr = $finding->toArray();

        $this->assertSame('inline-ignore', $arr['suppressed_reason']);
        $this->assertArrayHasKey('fingerprint', $arr);
    }

    public function test_to_array_excludes_suppressed_reason_when_null(): void
    {
        $finding = Finding::high(check: 'test', message: 'msg');

        $arr = $finding->toArray();

        $this->assertArrayNotHasKey('suppressed_reason', $arr);
    }

    // --- Baseline Manager ---

    public function test_baseline_save_and_load(string $tmpDir = ''): void
    {
        $tmpDir = $tmpDir ?: sys_get_temp_dir();
        $path = $tmpDir . '/guard_test_baseline_' . uniqid() . '.json';

        $findings = [
            Finding::high(check: 'test-a', message: 'msg a', file: 'app/Foo.php', line: 1),
            Finding::high(check: 'test-b', message: 'msg b', file: 'app/Bar.php', line: 2),
        ];

        $manager = new BaselineManager();
        $count = $manager->save($findings, $path);

        $this->assertSame(2, $count);
        $this->assertFileExists($path);

        $loaded = $manager->load($path);

        $this->assertCount(2, $loaded);
        $this->assertContains($findings[0]->fingerprint(), $loaded);
        $this->assertContains($findings[1]->fingerprint(), $loaded);

        @unlink($path);
    }

    public function test_baseline_load_returns_empty_for_missing_file(): void
    {
        $manager = new BaselineManager();

        $this->assertSame([], $manager->load('/nonexistent/path.json'));
    }

    public function test_baseline_suppresses_matching_findings(): void
    {
        $findings = [
            Finding::high(check: 'test-a', message: 'msg a', file: 'app/Foo.php', line: 1),
            Finding::high(check: 'test-b', message: 'msg b', file: 'app/Bar.php', line: 2),
        ];

        $baseline = [$findings[0]->fingerprint()]; // Only suppress first

        $manager = new BaselineManager();
        $result = $manager->suppress($findings, $baseline);

        $this->assertTrue($result[0]->isSuppressed());
        $this->assertSame('baseline', $result[0]->suppressed_reason);
        $this->assertFalse($result[1]->isSuppressed());
    }

    // --- Inline Ignore Manager ---

    public function test_inline_ignore_detects_matching_comment(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'guard_test_');
        file_put_contents($tmpFile, implode("\n", [
            '<?php',
            '// guard:ignore dangerous-query-input',
            '->orderBy($request->input("sort"))',
            '$otherCode = true;',
        ]));

        $finding = Finding::high(
            check: 'dangerous-query-input',
            message: 'Dangerous.',
            file: $tmpFile,
            line: 3, // The orderBy line
        );

        $manager = new InlineIgnoreManager();
        $result = $manager->apply([$finding]);

        $this->assertTrue($result[0]->isSuppressed());
        $this->assertSame('inline-ignore', $result[0]->suppressed_reason);

        @unlink($tmpFile);
    }

    public function test_inline_ignore_detects_all_keyword(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'guard_test_');
        file_put_contents($tmpFile, implode("\n", [
            '<?php',
            '// guard:ignore all',
            '->orderBy($request->input("sort"))',
        ]));

        $finding = Finding::high(
            check: 'dangerous-query-input',
            message: 'Dangerous.',
            file: $tmpFile,
            line: 3,
        );

        $manager = new InlineIgnoreManager();
        $result = $manager->apply([$finding]);

        $this->assertTrue($result[0]->isSuppressed());

        @unlink($tmpFile);
    }

    public function test_inline_ignore_on_same_line(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'guard_test_');
        file_put_contents($tmpFile, implode("\n", [
            '<?php',
            '->orderBy($request->input("sort")) // guard:ignore dangerous-query-input',
        ]));

        $finding = Finding::high(
            check: 'dangerous-query-input',
            message: 'Dangerous.',
            file: $tmpFile,
            line: 2,
        );

        $manager = new InlineIgnoreManager();
        $result = $manager->apply([$finding]);

        $this->assertTrue($result[0]->isSuppressed());

        @unlink($tmpFile);
    }

    public function test_inline_ignore_does_not_match_different_check(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'guard_test_');
        file_put_contents($tmpFile, implode("\n", [
            '<?php',
            '// guard:ignore route-authorization',
            '->orderBy($request->input("sort"))',
        ]));

        $finding = Finding::high(
            check: 'dangerous-query-input',
            message: 'Dangerous.',
            file: $tmpFile,
            line: 3,
        );

        $manager = new InlineIgnoreManager();
        $result = $manager->apply([$finding]);

        $this->assertFalse($result[0]->isSuppressed());

        @unlink($tmpFile);
    }

    public function test_inline_ignore_skips_findings_without_file(): void
    {
        $finding = Finding::high(
            check: 'route-authorization',
            message: 'No auth.',
        );

        $manager = new InlineIgnoreManager();
        $result = $manager->apply([$finding]);

        $this->assertFalse($result[0]->isSuppressed());
    }
}
