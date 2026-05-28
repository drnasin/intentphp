<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use IntentPHP\Guard\Checks\MassAssignmentCheck;
use IntentPHP\Guard\Scan\Finding;
use PHPUnit\Framework\TestCase;

class MassAssignmentCheckTest extends TestCase
{
    private string $root;
    private string $modelsPath;
    private string $controllersPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/guard_ma_' . uniqid();
        $this->modelsPath = $this->root . '/Models';
        $this->controllersPath = $this->root . '/Http/Controllers';

        mkdir($this->modelsPath, 0777, true);
        mkdir($this->controllersPath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function model(string $class, string $body): void
    {
        file_put_contents(
            $this->modelsPath . "/{$class}.php",
            "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass {$class} extends Model\n{\n{$body}\n}\n",
        );
    }

    /** @return Finding[] */
    private function runAgainst(string $controllerBody): array
    {
        file_put_contents(
            $this->controllersPath . '/TestController.php',
            "<?php\nnamespace App\\Http\\Controllers;\nclass TestController\n{\n    public function store(\$request)\n    {\n{$controllerBody}\n    }\n}\n",
        );

        return (new MassAssignmentCheck($this->modelsPath, $this->controllersPath))->run();
    }

    public function test_bare_model_is_not_flagged(): void
    {
        // No $fillable AND no $guarded → inherits Eloquent default $guarded=['*'] → safe.
        $this->model('BareModel', '    // nothing');

        $findings = $this->runAgainst('        BareModel::create($request->all());');

        $this->assertSame([], $findings);
    }

    public function test_guarded_star_is_not_flagged(): void
    {
        $this->model('LockedModel', "    protected \$guarded = ['*'];");

        $findings = $this->runAgainst('        LockedModel::create($request->all());');

        $this->assertSame([], $findings);
    }

    public function test_fillable_allowlist_is_not_flagged(): void
    {
        $this->model('SafeModel', "    protected \$fillable = ['name', 'email'];");

        $findings = $this->runAgainst('        SafeModel::create($request->all());');

        $this->assertSame([], $findings);
    }

    public function test_empty_guarded_is_flagged(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst('        OpenModel::create($request->all());');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertStringContainsString('empty array', $findings[0]->message);
    }

    public function test_partial_guarded_without_fillable_is_flagged(): void
    {
        // $guarded = ['id'] with no $fillable → every other attribute is open.
        $this->model('PartialModel', "    protected \$guarded = ['id'];");

        $findings = $this->runAgainst('        PartialModel::create($request->all());');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertStringContainsString('partial allowlist', $findings[0]->message);
    }

    public function test_partial_guarded_with_fillable_is_not_flagged(): void
    {
        // An explicit $fillable allowlist constrains assignment regardless of $guarded.
        $this->model('ConstrainedModel', "    protected \$guarded = ['id'];\n    protected \$fillable = ['name'];");

        $findings = $this->runAgainst('        ConstrainedModel::create($request->all());');

        $this->assertSame([], $findings);
    }

    public function test_protection_inherited_from_parent_is_not_resolved(): void
    {
        // Documented limitation: only the model's own file is scanned. A child
        // extending Model whose openness lives in a parent/trait reads as "bare"
        // and is treated safe here. Pinning current behavior so it's intentional,
        // not accidental — full coverage would require inheritance resolution.
        $this->model('ChildModel', '    // guarded opened up in a (hypothetical) parent');

        $findings = $this->runAgainst('        ChildModel::create($request->all());');

        $this->assertSame([], $findings);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
