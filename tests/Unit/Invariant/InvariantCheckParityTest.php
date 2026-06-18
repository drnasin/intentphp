<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Invariant;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IntentPHP\Guard\Analysis\AstParser;
use IntentPHP\Guard\Checks\Invariant\InvariantCheck;
use IntentPHP\Guard\Checks\MassAssignmentCheck;
use IntentPHP\Guard\Checks\RouteAuthorizationCheck;
use IntentPHP\Guard\Checks\RouteProtectionDetector;
use IntentPHP\Guard\Invariant\Invariants\MassAssignmentInvariant;
use IntentPHP\Guard\Invariant\Invariants\RouteAuthorizationInvariant;
use IntentPHP\Guard\Invariant\InvariantInput;
use IntentPHP\Guard\Scan\Finding;
use PHPUnit\Framework\TestCase;

/**
 * THE GOLDEN PARITY TEST (Phase 11).
 *
 * For each migrated rule, runs BOTH the legacy check API and a directly
 * constructed InvariantCheck(invariant, input) on the SAME fixture and asserts
 * the fully serialized findings (Finding::toArray, which includes context AND
 * fingerprint) are identical after sorting by fingerprint.
 *
 * Because the legacy checks are now thin shims over the invariants, each
 * scenario ALSO asserts the concrete expected finding (check / severity /
 * context / fingerprint-relevant keys) directly, so a divergence in the moved
 * logic fails the test — it is not a trivial "x === x".
 *
 * Covered scenarios:
 *  - mass-assignment HIGH (direct $request->all())
 *  - mass-assignment MEDIUM (validated())
 *  - mass-assignment incremental (changedFiles restricts the controller scan)
 *  - route-authorization WITHOUT has_form_request
 *  - route-authorization WITH has_form_request
 */
class InvariantCheckParityTest extends TestCase
{
    private const ROUTE_NS = 'IntentPHP\\Guard\\Tests\\Fixtures\\RouteAuth\\';
    private const PARITY_NS = 'IntentPHP\\Guard\\Tests\\Fixtures\\InvariantParity\\';

    private string $root;
    private string $modelsPath;
    private string $controllersPath;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/route-auth/Controllers.php';
        require_once __DIR__ . '/../../fixtures/invariant-parity/FormRequestController.php';
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/guard_inv_parity_' . uniqid();
        $this->modelsPath = $this->root . '/Models';
        $this->controllersPath = $this->root . '/Http/Controllers';

        mkdir($this->modelsPath, 0777, true);
        mkdir($this->controllersPath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    // ---- mass-assignment ---------------------------------------------------

    public function test_mass_assignment_high_parity(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');
        $controller = $this->writeController('        OpenModel::create($request->all());');

        $legacy = (new MassAssignmentCheck($this->modelsPath, $this->controllersPath))->run();
        $invariant = $this->runMassAssignmentInvariant(null);

        $this->assertFindingsEqual($legacy, $invariant);

        // Structural assertions — would fail if the moved logic diverged.
        $this->assertCount(1, $invariant);
        $f = $invariant[0];
        $this->assertSame('mass-assignment', $f->check);
        $this->assertSame('high', $f->severity);
        $this->assertSame('OpenModel', $f->context['model']);
        $this->assertSame('create with $request->all()', $f->context['pattern']);
        // Exact context key order: pattern, snippet, model, model_file.
        $this->assertSame(['pattern', 'snippet', 'model', 'model_file'], array_keys($f->context));
        // Fingerprint primary identifier is model:{model}:{pattern}; the file
        // is normalized so the temp path does not leak.
        $this->assertSame($legacy[0]->fingerprint(), $f->fingerprint());

        unlink($controller);
    }

    public function test_mass_assignment_medium_parity(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');
        $controller = $this->writeController('        OpenModel::create($request->validated());');

        $legacy = (new MassAssignmentCheck($this->modelsPath, $this->controllersPath))->run();
        $invariant = $this->runMassAssignmentInvariant(null);

        $this->assertFindingsEqual($legacy, $invariant);

        $this->assertCount(1, $invariant);
        $f = $invariant[0];
        $this->assertSame('mass-assignment', $f->check);
        $this->assertSame('medium', $f->severity);
        $this->assertSame('create with $request->validated()', $f->context['pattern']);
        $this->assertSame(['pattern', 'snippet', 'model', 'model_file'], array_keys($f->context));

        unlink($controller);
    }

    public function test_mass_assignment_incremental_changed_files_parity(): void
    {
        $this->model('OpenModel', '    protected $guarded = [];');
        $controller = $this->writeController('        OpenModel::create($request->all());');

        // A changed-files list that does NOT include the controller → no scan.
        $unrelated = [$this->controllersPath . '/Other.php'];

        $legacyEmpty = (new MassAssignmentCheck($this->modelsPath, $this->controllersPath, $unrelated))->run();
        $invariantEmpty = $this->runMassAssignmentInvariant($unrelated);

        $this->assertFindingsEqual($legacyEmpty, $invariantEmpty);
        $this->assertSame([], $invariantEmpty, 'changed-files excluding the controller must skip it');

        // A changed-files list that DOES include the controller → scanned.
        $included = [$controller];

        $legacyHit = (new MassAssignmentCheck($this->modelsPath, $this->controllersPath, $included))->run();
        $invariantHit = $this->runMassAssignmentInvariant($included);

        $this->assertFindingsEqual($legacyHit, $invariantHit);
        $this->assertCount(1, $invariantHit);
        $this->assertSame('high', $invariantHit[0]->severity);

        unlink($controller);
    }

    // ---- route-authorization -----------------------------------------------

    public function test_route_authorization_without_form_request_parity(): void
    {
        $action = self::ROUTE_NS . 'SubstringController@index';
        $router = $this->routerFor('api/widgets', ['GET'], $action, ['web']);

        $legacy = (new RouteAuthorizationCheck($router))->run();
        $invariant = $this->runRouteInvariant($router);

        $this->assertFindingsEqual($legacy, $invariant);

        $this->assertCount(1, $invariant);
        $f = $invariant[0];
        $this->assertSame('route-authorization', $f->check);
        $this->assertSame('high', $f->severity);
        // No FormRequest param → context must NOT carry has_form_request.
        $this->assertSame(['uri', 'methods', 'action', 'middleware'], array_keys($f->context));
        $this->assertArrayNotHasKey('has_form_request', $f->context);
        $this->assertSame($legacy[0]->fingerprint(), $f->fingerprint());
    }

    public function test_route_authorization_with_form_request_parity(): void
    {
        $action = self::PARITY_NS . 'FormRequestController@store';
        $router = $this->routerFor('widgets', ['POST'], $action, ['web']);

        $legacy = (new RouteAuthorizationCheck($router))->run();
        $invariant = $this->runRouteInvariant($router);

        $this->assertFindingsEqual($legacy, $invariant);

        $this->assertCount(1, $invariant);
        $f = $invariant[0];
        $this->assertSame('route-authorization', $f->check);
        // FormRequest param present → has_form_request appended LAST.
        $this->assertSame(['uri', 'methods', 'action', 'middleware', 'has_form_request'], array_keys($f->context));
        $this->assertTrue($f->context['has_form_request']);
        $this->assertSame($legacy[0]->fingerprint(), $f->fingerprint());
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @param string[]|null $changedFiles
     * @return Finding[]
     */
    private function runMassAssignmentInvariant(?array $changedFiles): array
    {
        $input = new InvariantInput(
            router: null,
            controllersPath: $this->controllersPath,
            modelsPath: $this->modelsPath,
            ast: new AstParser(),
            changedFiles: $changedFiles,
            detector: null,
        );

        return (new InvariantCheck(new MassAssignmentInvariant(), $input))->run();
    }

    /** @return Finding[] */
    private function runRouteInvariant(Router $router): array
    {
        $input = new InvariantInput(
            router: $router,
            controllersPath: '',
            modelsPath: '',
            ast: new AstParser(),
            changedFiles: null,
            detector: new RouteProtectionDetector(),
        );

        return (new InvariantCheck(new RouteAuthorizationInvariant(), $input))->run();
    }

    /**
     * @param string[] $methods
     * @param string[] $middleware
     */
    private function routerFor(string $uri, array $methods, string $action, array $middleware): Router
    {
        $route = $this->createMock(Route::class);
        $route->method('uri')->willReturn($uri);
        $route->method('methods')->willReturn($methods);
        $route->method('getActionName')->willReturn($action);
        $route->method('gatherMiddleware')->willReturn($middleware);

        $router = $this->createMock(Router::class);
        $router->method('getRoutes')->willReturn([$route]);

        return $router;
    }

    /**
     * Assert two finding lists serialize identically (context + fingerprint),
     * order-independent (sorted by fingerprint).
     *
     * @param Finding[] $a
     * @param Finding[] $b
     */
    private function assertFindingsEqual(array $a, array $b): void
    {
        $this->assertSame($this->serialize($a), $this->serialize($b));
    }

    /**
     * @param Finding[] $findings
     * @return array<int, array<string, mixed>>
     */
    private function serialize(array $findings): array
    {
        $rows = array_map(static fn (Finding $f): array => $f->toArray(), $findings);
        usort($rows, static fn (array $x, array $y): int => strcmp($x['fingerprint'], $y['fingerprint']));

        return $rows;
    }

    private function model(string $class, string $body): void
    {
        file_put_contents(
            $this->modelsPath . "/{$class}.php",
            "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass {$class} extends Model\n{\n{$body}\n}\n",
        );
    }

    private function writeController(string $body): string
    {
        $path = $this->controllersPath . '/TestController.php';
        file_put_contents(
            $path,
            "<?php\nnamespace App\\Http\\Controllers;\nclass TestController\n{\n    public function store(\$request)\n    {\n{$body}\n    }\n}\n",
        );

        return $path;
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
