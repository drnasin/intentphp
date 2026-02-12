<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit;

use IntentPHP\Guard\Patch\PatchBuilder;
use PHPUnit\Framework\TestCase;

class PatchBuilderTest extends TestCase
{
    private PatchBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new PatchBuilder();
    }

    public function test_builds_unified_diff_with_correct_headers(): void
    {
        $patch = $this->builder->build(
            'app/Models/Post.php',
            '$guarded = [];',
            "\$fillable = ['title', 'body'];",
            10,
        );

        $this->assertStringContainsString('--- a/app/Models/Post.php', $patch->diff);
        $this->assertStringContainsString('+++ b/app/Models/Post.php', $patch->diff);
        $this->assertStringContainsString('@@ -10,1 +10,1 @@', $patch->diff);
    }

    public function test_shows_removed_and_added_lines(): void
    {
        $patch = $this->builder->build(
            'app/Models/Post.php',
            '$guarded = [];',
            "\$fillable = ['title', 'body'];",
            10,
        );

        $this->assertStringContainsString('-$guarded = [];', $patch->diff);
        $this->assertStringContainsString("+\$fillable = ['title', 'body'];", $patch->diff);
    }

    public function test_handles_multiline_original_and_suggestion(): void
    {
        $original = "line one\nline two";
        $suggested = "line one\nline modified\nline three";

        $patch = $this->builder->build('file.php', $original, $suggested, 5);

        $this->assertStringContainsString('@@ -5,2 +5,3 @@', $patch->diff);
        $this->assertStringContainsString('-line one', $patch->diff);
        $this->assertStringContainsString('-line two', $patch->diff);
        $this->assertStringContainsString('+line one', $patch->diff);
        $this->assertStringContainsString('+line modified', $patch->diff);
        $this->assertStringContainsString('+line three', $patch->diff);
    }

    public function test_handles_empty_original(): void
    {
        $patch = $this->builder->build('file.php', '', "new line", 1);

        $this->assertStringContainsString('@@ -1,0 +1,1 @@', $patch->diff);
        $this->assertStringContainsString('+new line', $patch->diff);
    }

    public function test_patch_stores_file_and_content(): void
    {
        $patch = $this->builder->build('app/test.php', 'old', 'new', 1);

        $this->assertSame('app/test.php', $patch->file);
        $this->assertSame('old', $patch->original);
        $this->assertSame('new', $patch->suggested);
    }

    public function test_patch_to_string_returns_diff(): void
    {
        $patch = $this->builder->build('file.php', 'a', 'b', 1);

        $this->assertSame($patch->diff, (string) $patch);
    }
}
