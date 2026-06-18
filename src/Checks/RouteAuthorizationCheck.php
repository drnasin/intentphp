<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use Illuminate\Routing\Router;
use IntentPHP\Guard\Analysis\AstParser;
use IntentPHP\Guard\Checks\Invariant\InvariantCheck;
use IntentPHP\Guard\Invariant\Invariants\RouteAuthorizationInvariant;
use IntentPHP\Guard\Invariant\InvariantInput;
use IntentPHP\Guard\Scan\Finding;

/**
 * Thin shim over {@see RouteAuthorizationInvariant} (Phase 11 migration).
 *
 * The detection logic now lives in the invariant; this class preserves the
 * legacy public API (constructor signature + name()) and delegates run() to an
 * {@see InvariantCheck}, so existing callers and tests are unchanged and output
 * is byte-identical (see DECISIONS D-007).
 */
class RouteAuthorizationCheck implements CheckInterface
{
    /** @var string[] */
    private readonly array $publicRoutes;

    /** @var string[] */
    private readonly array $skipGuestRoutes;

    /** @var string[] */
    private readonly array $skipInfraRoutes;

    private readonly RouteProtectionDetector $detector;

    public const DEFAULT_SKIP_GUEST = [
        'login',
        'register',
        'forgot-password',
        'reset-password/*',
        'two-factor-challenge',
        'email/verify',
        'email/verify/*',
        'confirm-password',
    ];

    public const DEFAULT_SKIP_INFRA = [
        'up',
        'health',
        'sanctum/csrf-cookie',
        'livewire/*',
        '_ignition/*',
        '_debugbar/*',
        '_boost/*',
    ];

    /**
     * @param string[] $authMiddlewares Legacy flat list (ignored if $detector is provided)
     * @param string[] $publicRoutes    User-declared public routes
     * @param string[] $skipGuestRoutes Built-in guest auth route skip patterns
     * @param string[] $skipInfraRoutes Built-in infrastructure route skip patterns
     */
    public function __construct(
        private readonly Router $router,
        array $authMiddlewares = [],
        array $publicRoutes = [],
        ?RouteProtectionDetector $detector = null,
        array $skipGuestRoutes = self::DEFAULT_SKIP_GUEST,
        array $skipInfraRoutes = self::DEFAULT_SKIP_INFRA,
    ) {
        $this->publicRoutes = $publicRoutes;
        $this->skipGuestRoutes = $skipGuestRoutes;
        $this->skipInfraRoutes = $skipInfraRoutes;
        $this->detector = $detector ?? new RouteProtectionDetector($authMiddlewares);
    }

    public function name(): string
    {
        return 'route-authorization';
    }

    /** @return Finding[] */
    public function run(): array
    {
        // The route invariant only consumes router + detector; controllersPath,
        // modelsPath, ast and changedFiles are inert here (a fresh AstParser and
        // empty paths satisfy the InvariantInput contract).
        $input = new InvariantInput(
            router: $this->router,
            controllersPath: '',
            modelsPath: '',
            ast: new AstParser(),
            changedFiles: null,
            detector: $this->detector,
        );

        $invariant = new RouteAuthorizationInvariant(
            $this->publicRoutes,
            $this->skipGuestRoutes,
            $this->skipInfraRoutes,
        );

        return (new InvariantCheck($invariant, $input))->run();
    }
}
