<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Checks;

use IntentPHP\Guard\Checks\DangerousQueryInputCheck;
use IntentPHP\Guard\Scan\Finding;
use PHPUnit\Framework\TestCase;

class DangerousQueryInputCheckTest extends TestCase
{
    private string $controllersPath;

    protected function setUp(): void
    {
        $this->controllersPath = sys_get_temp_dir() . '/guard_dqi_' . uniqid();
        mkdir($this->controllersPath, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->controllersPath . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->controllersPath);
    }

    /** @return Finding[] */
    private function scan(string $body): array
    {
        file_put_contents(
            $this->controllersPath . '/C.php',
            "<?php\nclass C {\n    public function i(\$request) {\n{$body}\n    }\n}\n",
        );

        return (new DangerousQueryInputCheck($this->controllersPath))->run();
    }

    /** @return Finding[] */
    private function scanMethod(string $methodSource): array
    {
        file_put_contents(
            $this->controllersPath . '/C.php',
            "<?php\nclass C {\n{$methodSource}\n}\n",
        );

        return (new DangerousQueryInputCheck($this->controllersPath))->run();
    }

    public function test_direct_request_input_is_high(): void
    {
        $findings = $this->scan('        User::query()->orderBy($request->input("sort"));');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_sort_like_variable_is_medium_not_high(): void
    {
        // Name-only heuristic: the variable may be validated, so this must not
        // gate CI at default severity.
        $findings = $this->scan('        User::query()->orderBy($sort);');

        $this->assertCount(1, $findings);
        $this->assertSame('medium', $findings[0]->severity);
        $this->assertStringContainsString('unverified variable', $findings[0]->message);
    }

    public function test_suffixed_sort_variable_names_still_flagged_as_medium(): void
    {
        // $sortBy / $orderColumn / $direction are the canonical sort-var names;
        // the downgrade must not narrow coverage to the exact keyword only.
        foreach (['$sortBy', '$orderColumn', '$direction', '$columns'] as $var) {
            $findings = $this->scan("        User::query()->orderBy({$var});");

            $this->assertCount(1, $findings, "expected a finding for {$var}");
            $this->assertSame('medium', $findings[0]->severity, "expected MEDIUM for {$var}");
        }
    }

    public function test_unrelated_variable_name_is_not_flagged(): void
    {
        // A variable not matching the heuristic name list is not flagged at all.
        $findings = $this->scan('        User::query()->orderBy($somethingElse);');

        $this->assertSame([], $findings);
    }

    public function test_request_value_in_where_binding_is_not_flagged(): void
    {
        // ->where('col', $request->x) is a parameterized binding (safe). Only a
        // request value in the FIRST (column) position is dangerous.
        $findings = $this->scan('        User::query()->where("id", $request->input("id"));');

        $this->assertSame([], $findings);
    }

    public function test_request_in_column_position_is_flagged(): void
    {
        $findings = $this->scan('        User::query()->where($request->input("col"), "x");');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_multiline_call_is_detected(): void
    {
        // C1: a per-line regex misses this; the AST does not.
        $findings = $this->scan(
            "        User::query()->whereRaw(\n            \$request->input('q')\n        );",
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_interpolated_request_in_raw_sql_is_high(): void
    {
        // C3: string interpolation embedding request input.
        $findings = $this->scan('        DB::table("u")->orderByRaw("name {$request->input(\'dir\')}");');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_concatenated_request_in_raw_sql_is_high(): void
    {
        $findings = $this->scan('        DB::table("u")->whereRaw("id = " . $request->input("id"));');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_db_facade_statement_with_request_is_high(): void
    {
        $findings = $this->scan('        DB::statement("DELETE FROM t WHERE id = {$request->id}");');

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_object_named_like_request_in_raw_sql_is_not_flagged(): void
    {
        // $pullRequest is a domain object, not the HTTP request.
        $findings = $this->scan('        DB::table("u")->whereRaw("id = {$pullRequest->id}");');

        $this->assertSame([], $findings);
    }

    public function test_interpolated_non_request_variable_is_not_flagged(): void
    {
        // No taint tracking yet (C2 deferred): a non-request variable in raw SQL
        // is not flagged, avoiding false positives on validated/constant values.
        $findings = $this->scan('        DB::table("u")->whereRaw("name = $safeConst");');

        $this->assertSame([], $findings);
    }

    public function test_tainted_variable_interpolated_in_raw_sql_is_flagged(): void
    {
        // C2: $dir is filled from request input on a previous line, then
        // interpolated into raw SQL. The per-line regex couldn't see this; AST
        // + monotonic taint now can.
        $findings = $this->scan(
            "        \$dir = \$request->input('dir');\n"
            . "        DB::table('u')->orderByRaw(\"name \$dir\");"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
        $this->assertStringContainsString('tainted variable $dir', $findings[0]->message);
    }

    public function test_tainted_variable_concatenated_in_raw_sql_is_flagged(): void
    {
        $findings = $this->scan(
            "        \$q = \$request->input('q');\n"
            . "        DB::table('u')->whereRaw('name = ' . \$q);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_tainted_variable_as_orderby_column_is_flagged(): void
    {
        $findings = $this->scan(
            "        \$col = \$request->input('col');\n"
            . "        User::query()->orderBy(\$col);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_form_request_alias_taints_through_indirection(): void
    {
        $findings = $this->scanMethod(<<<'PHP'
            public function index(\App\Http\Requests\ReportRequest $r)
            {
                $col = $r->input('col');
                User::query()->orderByRaw("name $col");
            }
        PHP);

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_taint_is_monotonic_across_reassignment_in_raw_sql(): void
    {
        // Once tainted, always tainted within the function — even after a
        // reassignment to a safe value. Documented conservative FP.
        $findings = $this->scan(
            "        \$dir = \$request->input('dir');\n"
            . "        \$dir = 'asc';\n"
            . "        DB::table('u')->orderByRaw(\"name \$dir\");"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_untainted_variable_in_raw_sql_is_not_flagged(): void
    {
        // Sanity: only a request-derived variable triggers; an unrelated local
        // variable does not (no taint, no FP).
        $findings = $this->scan(
            "        \$dir = 'asc';\n"
            . "        DB::table('u')->orderByRaw(\"name \$dir\");"
        );

        $this->assertSame([], $findings);
    }

    public function test_foreach_element_taints_for_raw_sql(): void
    {
        // foreach element is a request-derived value → tainted for raw SQL.
        $findings = $this->scan(<<<'PHP'
        foreach ($request->all() as $v) {
            DB::table('u')->whereRaw("name = $v");
        }
        PHP);

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_concat_built_sql_via_intermediate_is_flagged(): void
    {
        // Textbook SQLi: build the SQL string from request input, then pass it
        // to DB::select / whereRaw. The wrapper recursion + variable taint
        // catches this even though the sink doesn't reference $request itself.
        $findings = $this->scan(
            "        \$sql = 'SELECT * FROM u WHERE id = ' . \$request->input('id');\n"
            . "        DB::select(\$sql);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_null_coalesce_in_sink_arg_is_flagged(): void
    {
        // $cond ?? $request->input('q') as the sink argument directly.
        $findings = $this->scan(
            "        User::query()->orderBy(\$fallback ?? \$request->input('col'));"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_taint_via_null_coalesce_assignment_is_flagged(): void
    {
        $findings = $this->scan(
            "        \$col = \$x ?? \$request->input('col');\n"
            . "        User::query()->orderBy(\$col);"
        );

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_foreach_with_wrapped_source_taints_element(): void
    {
        // Real Laravel pattern: foreach (($request->input('ids') ?? []) as $id).
        $findings = $this->scan(<<<'PHP'
        foreach (($request->input('ids') ?? []) as $id) {
            DB::table('u')->whereRaw("id = $id");
        }
        PHP);

        $this->assertCount(1, $findings);
        $this->assertSame('high', $findings[0]->severity);
    }

    public function test_fingerprint_is_stable_across_reformatting(): void
    {
        $a = $this->scan('        User::query()->whereRaw($request->input("q"));');
        $b = $this->scan("        User::query()->whereRaw(\n            \$request->input(\"q\")\n        );");

        // Same logical sink, different formatting → identifier must not churn.
        // (Line differs, but the snippet no longer drives the fingerprint.)
        $this->assertSame($a[0]->context['sink'], $b[0]->context['sink']);
        $this->assertSame($a[0]->context['pattern'], $b[0]->context['pattern']);
    }
}
