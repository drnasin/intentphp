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
}
