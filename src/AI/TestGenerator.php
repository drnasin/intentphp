<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI;

use IntentPHP\Guard\Scan\Finding;

class TestGenerator
{
    private const TEMPLATE_HEADER = <<<'PHP'
<?php

namespace Tests\Feature\GuardGenerated;

use Tests\TestCase;

PHP;

    public function __construct(
        private readonly AiClientInterface $client,
    ) {}

    /**
     * Generate test file contents for HIGH severity findings.
     *
     * @param Finding[] $findings
     * @return array<string, string> filename => file contents
     */
    public function generate(array $findings): array
    {
        $grouped = $this->groupByCheck($findings);
        $files = [];

        foreach ($grouped as $check => $checkFindings) {
            $result = match ($check) {
                'route-authorization' => $this->generateRouteAuthTests($checkFindings),
                'dangerous-query-input' => $this->generateInputValidationTests($checkFindings),
                'mass-assignment' => $this->generateMassAssignmentTests($checkFindings),
                default => null,
            };

            if ($result !== null) {
                $files[$result['filename']] = $result['content'];
            }
        }

        return $files;
    }

    /**
     * @param Finding[] $findings
     * @return array<string, Finding[]>
     */
    private function groupByCheck(array $findings): array
    {
        $grouped = [];

        foreach ($findings as $finding) {
            if ($finding->severity !== 'high') {
                continue;
            }

            $grouped[$finding->check][] = $finding;
        }

        return $grouped;
    }

    /**
     * @param Finding[] $findings
     * @return array{filename: string, content: string}
     */
    private function generateRouteAuthTests(array $findings): array
    {
        $methods = [];

        foreach ($findings as $i => $finding) {
            $uri = $finding->context['uri'] ?? '/unknown';
            $httpMethods = $finding->context['methods'] ?? ['GET'];
            $httpMethod = strtolower($httpMethods[0] ?? 'get');
            $safeName = $this->safeMethodName($uri);
            $index = $i + 1;

            $methods[] = $this->buildRouteAuthTestMethod($safeName, $index, $httpMethod, $uri);
        }

        $className = 'RouteAuthorizationTest';
        $content = self::TEMPLATE_HEADER;
        $content .= "class {$className} extends TestCase\n{\n";
        $content .= implode("\n", $methods);
        $content .= "}\n";

        return [
            'filename' => "{$className}.php",
            'content' => $content,
        ];
    }

    private function buildRouteAuthTestMethod(string $safeName, int $index, string $httpMethod, string $uri): string
    {
        $uri = '/' . ltrim($uri, '/');

        return <<<PHP
    /**
     * A guest (unauthenticated user) should not be able to access this route.
     * If this test fails, the route is missing authorization protection.
     */
    public function test_guest_cannot_access_{$safeName}_{$index}(): void
    {
        \$response = \$this->{$httpMethod}('{$uri}');

        \$response->assertUnauthorized();
    }

PHP;
    }

    /**
     * @param Finding[] $findings
     * @return array{filename: string, content: string}
     */
    private function generateInputValidationTests(array $findings): array
    {
        $methods = [];

        foreach ($findings as $i => $finding) {
            $file = $finding->file ? basename($finding->file, '.php') : 'Controller';
            $pattern = $finding->context['pattern'] ?? 'unknown';
            $safeName = $this->safeMethodName($file . '_' . $pattern);
            $index = $i + 1;

            $methods[] = $this->buildInputValidationTestMethod($safeName, $index, $finding);
        }

        $className = 'DangerousInputValidationTest';
        $content = self::TEMPLATE_HEADER;
        $content .= "class {$className} extends TestCase\n{\n";
        $content .= implode("\n", $methods);
        $content .= "}\n";

        return [
            'filename' => "{$className}.php",
            'content' => $content,
        ];
    }

    private function buildInputValidationTestMethod(string $safeName, int $index, Finding $finding): string
    {
        $snippet = $finding->context['snippet'] ?? '';

        return <<<PHP
    /**
     * Regression test: sending malicious sort/filter input must not cause a 500 error.
     * Flagged code: {$snippet}
     */
    public function test_handles_malicious_input_{$safeName}_{$index}(): void
    {
        // Send a request with potentially dangerous input.
        // The application should handle this gracefully (validate, reject, or ignore).
        \$response = \$this->get('/', [
            'sort' => "id'; DROP TABLE users--",
            'direction' => 'invalid_dir',
            'filter' => '<script>alert(1)</script>',
        ]);

        // The response must not be a server error — any controlled status is acceptable.
        \$this->assertContains(
            \$response->getStatusCode(),
            [200, 301, 302, 403, 404, 422],
            'Endpoint returned 500 when given malicious input. This may indicate unvalidated query input.'
        );
    }

PHP;
    }

    /**
     * @param Finding[] $findings
     * @return array{filename: string, content: string}
     */
    private function generateMassAssignmentTests(array $findings): array
    {
        $methods = [];
        $seen = [];

        foreach ($findings as $i => $finding) {
            $model = $finding->context['model'] ?? null;

            if ($model === null || isset($seen[$model])) {
                continue;
            }

            $seen[$model] = true;
            $safeName = $this->safeMethodName($model);
            $index = $i + 1;

            $methods[] = $this->buildMassAssignmentTestMethod($safeName, $index, $model);
        }

        if (empty($methods)) {
            return [
                'filename' => 'MassAssignmentProtectionTest.php',
                'content' => self::TEMPLATE_HEADER . "class MassAssignmentProtectionTest extends TestCase\n{\n}\n",
            ];
        }

        $className = 'MassAssignmentProtectionTest';
        $content = self::TEMPLATE_HEADER;
        $content .= "class {$className} extends TestCase\n{\n";
        $content .= implode("\n", $methods);
        $content .= "}\n";

        return [
            'filename' => "{$className}.php",
            'content' => $content,
        ];
    }

    private function buildMassAssignmentTestMethod(string $safeName, int $index, string $model): string
    {
        return <<<PHP
    /**
     * Verifies that {$model} has mass assignment protection.
     * The model must define \$fillable or have non-empty \$guarded.
     */
    public function test_{$safeName}_is_protected_against_mass_assignment_{$index}(): void
    {
        \$model = new \\App\\Models\\{$model}();

        \$hasFillable = !empty(\$model->getFillable());
        \$hasGuarded = \$model->getGuarded() !== [] && \$model->getGuarded() !== ['*'];

        // At least one protection mechanism should be explicitly defined.
        // Note: Laravel defaults \$guarded to ['*'] which IS safe, but
        // explicit \$fillable is preferred for clarity.
        \$this->assertTrue(
            \$hasFillable || \$hasGuarded,
            'Model {$model} must define \$fillable or have non-empty \$guarded to prevent mass assignment.'
        );
    }

PHP;
    }

    private function safeMethodName(string $input): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9]/', '_', $input);
        $safe = preg_replace('/_+/', '_', $safe);
        $safe = trim($safe, '_');

        return strtolower($safe);
    }
}
