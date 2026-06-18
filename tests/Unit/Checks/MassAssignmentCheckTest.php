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

    /** @return Finding[] */
    private function runWithMethod(string $methodSource): array
    {
        file_put_contents(
            $this->controllersPath . '/TestController.php',
            "<?php\nnamespace App\\Http\\Controllers;\nclass TestController\n{\n{$methodSource}\n}\n",
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

    public function test_multiline_create_call_is_detected(): void
    {
        // C1: the call spans lines — the old per-line regex missed this.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst("        OpenModel::create(\n            \$request->all()\n        );");

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_static_create_on_safe_model_is_not_flagged(): void
    {
        // ::create is anchored to unsafe models (parity). SafeModel has $fillable,
        // so it is not in the unsafe set → not flagged. (OpenModel only makes the
        // unsafe set non-empty so the scan runs.)
        $this->model('OpenModel', '    protected $guarded = [];');
        $this->model('SafeModel', "    protected \$fillable = ['name'];");

        $findings = $this->runAgainst('        SafeModel::create($request->all());');

        $this->assertSame([], $findings);
    }

    public function test_update_on_model_with_inherited_opening_is_still_flagged(): void
    {
        // findUnsafeModels can't see $guarded=[] inherited from a parent, so
        // Invoice isn't in the unsafe set. ->update() stays LOOSE, so the real
        // vuln is NOT dropped (regression guard against the resolved-but-safe FN).
        $this->model('OpenModel', '    protected $guarded = [];');
        $this->model('Invoice', '    // $guarded opened up in a parent class');

        $findings = $this->runAgainst('        Invoice::query()->update($request->all());');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_single_field_input_is_not_mass_assignment(): void
    {
        // $request->input('email') is a single value, not the bulk payload.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst('        OpenModel::create($request->input("email"));');

        $this->assertSame([], $findings);
    }

    public function test_object_named_like_request_is_not_treated_as_request(): void
    {
        // $pullRequest / $friendRequest etc. are domain objects, not the HTTP
        // request — must not be mistaken for request input.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst('        $pullRequest->fill($pullRequest->all());');

        $this->assertSame([], $findings);
    }

    public function test_unknown_receiver_is_flagged_when_an_unsafe_model_exists(): void
    {
        // Receiver is a plain variable (unresolvable) → legacy loose behavior:
        // flag while any unsafe model exists in the project.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst('        $thing->update($request->all());');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_taint_via_one_hop_assignment_is_flagged(): void
    {
        // C2: $d = $request->all(); ::create($d); — the indirection that the
        // per-line regex (and even direct AST) missed.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$data = \$request->all();\n        OpenModel::create(\$data);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertStringContainsString('tainted variable $data', $findings[0]->message);
    }

    public function test_taint_via_validated_is_medium(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$data = \$request->validated();\n        OpenModel::create(\$data);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('medium', $findings[0]->severity);
    }

    public function test_single_field_input_does_not_taint_for_mass_assignment(): void
    {
        // $d = $request->input('email') is a single field, not bulk → $d is not
        // a mass-assignment payload, so passing it to ::create is not flagged.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$d = \$request->input('email');\n        OpenModel::create(\$d);"
        );

        $this->assertSame([], $findings);
    }

    public function test_form_request_param_alias_taints_indirection(): void
    {
        // Param typed *Request acts as a request alias within the function.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runWithMethod(<<<'PHP'
            public function store(\App\Http\Requests\StoreUserRequest $req)
            {
                $data = $req->all();
                OpenModel::create($data);
            }
        PHP);

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_taint_is_monotonic_across_reassignment(): void
    {
        // Conservative-by-design: once $d is tainted anywhere in the function,
        // every read of $d in that function is treated as tainted. Reassignment
        // to a safe value does NOT untaint. This is documented (and accepted)
        // FP risk in exchange for not needing a sound flow-sensitive engine.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$d = \$request->all();\n"
            . "        \$d = ['safe' => 1];\n"
            . "        OpenModel::create(\$d);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_taint_does_not_leak_into_nested_closure(): void
    {
        // A nested closure has its own scope; $d defined inside it must not
        // be tainted from the outer function's $request, and vice versa.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(<<<'PHP'
        $cb = function ($request) {
            $d = $request->all();
        };
        $outer = 'safe';
        OpenModel::create($outer);
        PHP);

        $this->assertSame([], $findings);
    }

    public function test_foreach_over_request_does_not_taint_as_bulk(): void
    {
        // foreach element is a value derived from the request, not the bulk
        // payload — so it's a raw-SQL interpolation taint source (covered in
        // DangerousQueryInputCheckTest) but NOT a mass-assignment one.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(<<<'PHP'
        foreach ($request->all() as $v) {
            OpenModel::create($v);
        }
        PHP);

        $this->assertSame([], $findings);
    }

    public function test_taint_through_null_coalesce_is_flagged(): void
    {
        // C2 wrapper: $d = $x ?? $request->input('d'); — the request branch poisons $d.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$d = \$fallback ?? \$request->input();\n        OpenModel::create(\$d);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_taint_through_ternary_is_flagged(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$d = \$cond ? \$safe : \$request->all();\n        OpenModel::create(\$d);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_taint_through_match_arm_is_flagged(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(<<<'PHP'
        $d = match ($mode) {
            'a' => ['safe' => 1],
            default => $request->all(),
        };
        OpenModel::create($d);
        PHP);

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_taint_through_cast_is_flagged(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$d = (array) \$request->all();\n        OpenModel::create(\$d);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_chained_assignment_taints_both_lhs(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$a = \$b = \$request->all();\n        OpenModel::create(\$a);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_variable_chain_via_intermediate_taints(): void
    {
        // $tmp = $request->all(); $d = $tmp;  →  $d should be tainted via the
        // fixed-point iteration over the function body.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst(
            "        \$tmp = \$request->all();\n        \$d = \$tmp;\n        OpenModel::create(\$d);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_domain_request_typed_param_is_not_an_alias(): void
    {
        // FQCN-gated: \App\Domain\MergeRequest is NOT a Laravel HTTP request,
        // so $mergeRequest->all() must not be treated as request input. Without
        // FQCN resolution the *Request suffix heuristic would FP here.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runWithMethod(<<<'PHP'
            public function approve(\App\Domain\MergeRequest $mergeRequest)
            {
                $data = $mergeRequest->all();
                OpenModel::create($data);
            }
        PHP);

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

    /** Write a model file verbatim (caller controls the full source). */
    private function rawModel(string $class, string $source): void
    {
        file_put_contents($this->modelsPath . "/{$class}.php", $source);
    }

    public function test_guarded_empty_only_in_comment_is_not_flagged(): void
    {
        // #15 false positive: $guarded = [] appears ONLY inside a docblock; the
        // real declaration is a $fillable allowlist. AST reads the property
        // nodes, not raw text, so the model is correctly treated as safe.
        $this->rawModel('Article', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Model;
        class Article extends Model
        {
            /**
             * Do NOT do this:
             *   protected $guarded = [];
             */
            protected $fillable = ['title', 'body'];
        }
        PHP);

        $findings = $this->runAgainst('        Article::create($request->all());');

        $this->assertSame([], $findings);
    }

    public function test_guarded_empty_only_in_string_is_not_flagged(): void
    {
        // #15 false positive variant: the substring lives in a string literal.
        $this->rawModel('Note', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Model;
        class Note extends Model
        {
            public const HINT = 'never write protected $guarded = [];';
            protected $fillable = ['body'];
        }
        PHP);

        $findings = $this->runAgainst('        Note::create($request->all());');

        $this->assertSame([], $findings);
    }

    public function test_genuine_empty_guarded_is_still_flagged(): void
    {
        // Inverse guard: a real $guarded = [] in code must still be flagged
        // (the AST rewrite must not over-correct into a false negative).
        $this->rawModel('Wide', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Model;
        class Wide extends Model
        {
            // example only: protected $fillable = ['safe'];
            protected $guarded = [];
        }
        PHP);

        $findings = $this->runAgainst('        Wide::create($request->all());');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertStringContainsString('empty array', $findings[0]->message);
    }

    public function test_class_keyword_in_comment_before_real_class_resolves_correctly(): void
    {
        // #15 extractClassName: a `class` keyword in a leading comment, plus a
        // ::class constant, must not be mistaken for the class declaration.
        // The real (unsafe) class is Order — it must be flagged under that name.
        $this->rawModel('Order', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Model;
        // This class extends Decoy and is totally safe (it is not).
        class Order extends Model
        {
            public const REF = \App\Models\Decoy::class;
            protected $guarded = [];
        }
        PHP);

        $findings = $this->runAgainst('        Order::create($request->all());');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
        // The finding's model is the real declaration, Order — not "Decoy".
        $this->assertSame('Order', $findings[0]->context['model']);
    }

    public function test_helper_class_before_model_is_not_mis_identified(): void
    {
        // First class in the file is a non-Eloquent helper; the actual model is
        // the second class. findModelClass returns the first NAMED class, and
        // extendsModel gates on the Eloquent base — so a leading helper that is
        // unsafe-looking must not cause OpenModel-style flagging via the helper.
        // Here the helper has $guarded=[] but does NOT extend Model → ignored;
        // the model extends Model with a $fillable allowlist → safe.
        $this->rawModel('Mixed', <<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Database\Eloquent\Model;
        class MixedHelper
        {
            protected $guarded = [];
        }
        class MixedModel extends Model
        {
            protected $fillable = ['name'];
        }
        PHP);

        // OpenModel keeps the unsafe set non-empty so the controller scan runs.
        $this->model('OpenModel', '    protected $guarded = [];');

        $findings = $this->runAgainst('        MixedModel::create($request->all());');

        // MixedModel is safe (fillable) and MixedHelper is not Eloquent → no flag
        // for this static create (anchored to unsafe models).
        $this->assertSame([], $findings);
    }

    public function test_extends_project_base_class_remains_unresolved(): void
    {
        // #15 (C) decision: inheritance is NOT resolved across files (documented
        // limitation, finder docblock). A model extending a project base class
        // reads as a non-Eloquent class and is treated safe. Pinned so the
        // bounded scope is intentional, not accidental.
        $this->rawModel('Post', <<<'PHP'
        <?php
        namespace App\Models;
        class Post extends BaseModel
        {
            protected $guarded = [];
        }
        PHP);

        $findings = $this->runAgainst('        Post::create($request->all());');

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
