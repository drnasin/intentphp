<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Golden;

use Illuminate\Routing\Router;
use IntentPHP\Guard\Checks\Intent\IntentDriftCheck;
use IntentPHP\Guard\Console\GuardScanCommand;
use IntentPHP\Guard\GuardServiceProvider;
use IntentPHP\Guard\Intent\IntentContext;
use IntentPHP\Guard\Scan\Scanner;
use Orchestra\Testbench\TestCase;

/**
 * Regression test proving: if intent/intent.yaml does not exist,
 * IntentDriftCheck is NOT registered in the Scanner.
 *
 * Uses Orchestra Testbench so that config() and app_path() are available
 * when invoking GuardScanCommand::buildScanner() via reflection.
 */
final class DriftNoIntentFileRegressionTest extends TestCase
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [GuardServiceProvider::class];
    }

    /**
     * IntentContext::tryLoad() with a nonexistent path returns null context
     * and no errors. This is the gate that prevents IntentDriftCheck from
     * being registered in GuardScanCommand::buildScanner().
     */
    public function test_tryLoad_nonexistent_file_returns_null_context(): void
    {
        $result = IntentContext::tryLoad('/nonexistent/path/intent.yaml');

        $this->assertNull($result['context']);
        $this->assertSame([], $result['errors']);
    }

    /**
     * When intentContext is null (no intent file), buildScanner() must NOT
     * register any IntentDriftCheck instances. Verified via reflection on
     * the real GuardScanCommand wiring.
     */
    public function test_buildScanner_with_null_intent_has_no_drift_check(): void
    {
        $command = new GuardScanCommand();

        $method = new \ReflectionMethod($command, 'buildScanner');
        $method->setAccessible(true);

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        /** @var array{scanner: Scanner, route_scan_mode: string} $built */
        $built = $method->invoke(
            $command,
            $router,
            null,   // changedFiles
            null,   // intentContext = null => drift check must NOT be registered
        );

        $scanner = $built['scanner'];
        $this->assertInstanceOf(Scanner::class, $scanner);

        // Extract registered checks from Scanner (private $checks)
        $prop = new \ReflectionProperty($scanner, 'checks');
        $prop->setAccessible(true);
        /** @var array<int, object> $checks */
        $checks = $prop->getValue($scanner);

        foreach ($checks as $check) {
            $this->assertNotInstanceOf(
                IntentDriftCheck::class,
                $check,
                'IntentDriftCheck must not be registered when intent file is missing',
            );
        }
    }

    /**
     * Verify that the check class exists so this test stays valid
     * if someone renames it.
     */
    public function test_intent_drift_check_class_exists(): void
    {
        $this->assertTrue(class_exists(IntentDriftCheck::class));
    }
}
