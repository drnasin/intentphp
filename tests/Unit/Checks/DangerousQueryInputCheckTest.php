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
