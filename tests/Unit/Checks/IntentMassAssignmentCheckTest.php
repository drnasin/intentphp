<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use IntentPHP\Guard\Checks\IntentMassAssignmentCheck;
use IntentPHP\Guard\Intent\Auth\AuthSpec;
use IntentPHP\Guard\Intent\Baseline\BaselineSpec;
use IntentPHP\Guard\Intent\Data\DataSpec;
use IntentPHP\Guard\Intent\Data\ModelSpec;
use IntentPHP\Guard\Intent\Defaults;
use IntentPHP\Guard\Intent\IntentContext;
use IntentPHP\Guard\Intent\IntentSpec;
use IntentPHP\Guard\Intent\ProjectMeta;
use PHPUnit\Framework\TestCase;

class IntentMassAssignmentCheckTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/intent_mass_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }

    private function makeSpec(array $models = []): IntentSpec
    {
        return new IntentSpec(
            version: '0.1',
            project: new ProjectMeta('test', 'laravel'),
            defaults: new Defaults(),
            auth: AuthSpec::empty(),
            data: new DataSpec(models: $models),
            baseline: BaselineSpec::empty(),
        );
    }

    private function writeModelFile(string $relativePath, string $contents): string
    {
        $fullPath = $this->tmpDir . DIRECTORY_SEPARATOR . $relativePath;
        $dir = dirname($fullPath);

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, $contents);

        return $fullPath;
    }

    public function test_no_models_in_spec_returns_no_findings(): void
    {
        $spec = $this->makeSpec();
        $context = new IntentContext($spec);
        $check = new IntentMassAssignmentCheck($this->tmpDir, $spec, $context);

        $findings = $check->run();
        $this->assertSame([], $findings);
    }

    public function test_model_missing_fillable_with_explicit_allowlist_emits_finding(): void
    {
        $this->writeModelFile('User.php', <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    // No $fillable here
}
PHP);

        $spec = $this->makeSpec([
            'App\\Models\\User' => new ModelSpec(
                fqcn: 'App\\Models\\User',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name', 'email'],
            ),
        ]);

        $context = new IntentContext($spec);
        $check = new IntentMassAssignmentCheck($this->tmpDir, $spec, $context);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame('intent-mass-assignment', $findings[0]->check);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertSame('missing_fillable', $findings[0]->context['pattern']);
        $this->assertSame('App\\Models\\User', $findings[0]->context['model_fqcn']);
    }

    public function test_model_with_fillable_emits_no_finding(): void
    {
        $this->writeModelFile('User.php', <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email'];
}
PHP);

        $spec = $this->makeSpec([
            'App\\Models\\User' => new ModelSpec(
                fqcn: 'App\\Models\\User',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name', 'email'],
            ),
        ]);

        $context = new IntentContext($spec);
        $check = new IntentMassAssignmentCheck($this->tmpDir, $spec, $context);

        $findings = $check->run();
        $this->assertSame([], $findings);
    }

    public function test_forbidden_attribute_in_fillable_emits_finding(): void
    {
        $this->writeModelFile('User.php', <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email', 'is_admin'];
}
PHP);

        $spec = $this->makeSpec([
            'App\\Models\\User' => new ModelSpec(
                fqcn: 'App\\Models\\User',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name', 'email'],
                forbid: ['is_admin'],
            ),
        ]);

        $context = new IntentContext($spec);
        $check = new IntentMassAssignmentCheck($this->tmpDir, $spec, $context);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertSame('forbidden_in_fillable:is_admin', $findings[0]->context['pattern']);
    }

    public function test_guarded_mode_with_empty_guarded_emits_finding(): void
    {
        $this->writeModelFile('Post.php', <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $guarded = [];
}
PHP);

        $spec = $this->makeSpec([
            'App\\Models\\Post' => new ModelSpec(
                fqcn: 'App\\Models\\Post',
                massAssignmentMode: 'guarded',
            ),
        ]);

        $context = new IntentContext($spec);
        $check = new IntentMassAssignmentCheck($this->tmpDir, $spec, $context);

        $findings = $check->run();
        $this->assertCount(1, $findings);
        $this->assertSame('guarded_empty', $findings[0]->context['pattern']);
    }

    public function test_model_file_not_found_adds_warning_no_finding(): void
    {
        $spec = $this->makeSpec([
            'App\\Models\\Missing' => new ModelSpec(
                fqcn: 'App\\Models\\Missing',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name'],
            ),
        ]);

        $context = new IntentContext($spec);
        $check = new IntentMassAssignmentCheck($this->tmpDir, $spec, $context);

        $findings = $check->run();
        $this->assertSame([], $findings);
        $this->assertCount(1, $context->warnings);
        $this->assertStringContainsString('Missing', $context->warnings[0]);
    }

    public function test_fingerprint_is_deterministic(): void
    {
        $this->writeModelFile('User.php', <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    // No fillable
}
PHP);

        $spec = $this->makeSpec([
            'App\\Models\\User' => new ModelSpec(
                fqcn: 'App\\Models\\User',
                massAssignmentMode: 'explicit_allowlist',
                allow: ['name'],
            ),
        ]);

        $context = new IntentContext($spec);
        $check = new IntentMassAssignmentCheck($this->tmpDir, $spec, $context);

        $findings1 = $check->run();
        $findings2 = $check->run();

        $this->assertCount(1, $findings1);
        $this->assertCount(1, $findings2);
        $this->assertSame($findings1[0]->fingerprint(), $findings2[0]->fingerprint());
    }

    public function test_guarded_mode_with_populated_guarded_emits_no_finding(): void
    {
        $this->writeModelFile('Post.php', <<<'PHP'
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $guarded = ['id', 'created_at'];
}
PHP);

        $spec = $this->makeSpec([
            'App\\Models\\Post' => new ModelSpec(
                fqcn: 'App\\Models\\Post',
                massAssignmentMode: 'guarded',
            ),
        ]);

        $context = new IntentContext($spec);
        $check = new IntentMassAssignmentCheck($this->tmpDir, $spec, $context);

        $findings = $check->run();
        $this->assertSame([], $findings);
    }
}
