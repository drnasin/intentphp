<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Invariant;

use IntentPHP\Guard\Analysis\AstParser;
use IntentPHP\Guard\Checks\Invariant\InvariantCheck;
use IntentPHP\Guard\Invariant\Invariant;
use IntentPHP\Guard\Invariant\InvariantInput;
use IntentPHP\Guard\Invariant\Violation;
use PHPUnit\Framework\TestCase;

class ViolationTest extends TestCase
{
    public function test_violation_exposes_its_fields(): void
    {
        $v = new Violation(
            invariantId: 'mass-assignment',
            targetId: 'OpenModel:create with $request->all()',
            severity: 'high',
            message: 'boom',
            file: 'app/Foo.php',
            line: 42,
            context: ['pattern' => 'p', 'snippet' => 's', 'model' => 'OpenModel', 'model_file' => null],
            fixHint: 'fix it',
        );

        $this->assertSame('mass-assignment', $v->invariantId);
        $this->assertSame('OpenModel:create with $request->all()', $v->targetId);
        $this->assertSame('high', $v->severity);
        $this->assertSame('boom', $v->message);
        $this->assertSame('app/Foo.php', $v->file);
        $this->assertSame(42, $v->line);
        $this->assertSame('fix it', $v->fixHint);
        $this->assertSame(['pattern' => 'p', 'snippet' => 's', 'model' => 'OpenModel', 'model_file' => null], $v->context);
    }

    public function test_invariant_check_maps_one_violation_to_one_finding(): void
    {
        $violation = new Violation(
            invariantId: 'route-authorization',
            targetId: 'GET api/widgets@X',
            severity: 'high',
            message: 'Route [GET] api/widgets has no authorization protection.',
            file: null,
            line: null,
            context: ['uri' => 'api/widgets', 'methods' => ['GET'], 'action' => 'X', 'middleware' => ['web']],
            fixHint: 'add middleware',
        );

        $invariant = new class($violation) implements Invariant {
            public function __construct(private readonly Violation $violation) {}

            public function id(): string
            {
                return 'route-authorization';
            }

            public function description(): string
            {
                return '';
            }

            public function evaluate(InvariantInput $input): array
            {
                return [$this->violation];
            }
        };

        $input = new InvariantInput(null, '', '', new AstParser(), null, null);
        $findings = (new InvariantCheck($invariant, $input))->run();

        $this->assertCount(1, $findings);
        $finding = $findings[0];

        // check === invariantId, and context is passed straight through
        // (identical, including key insertion order).
        $this->assertSame('route-authorization', $finding->check);
        $this->assertSame('high', $finding->severity);
        $this->assertSame($violation->message, $finding->message);
        $this->assertNull($finding->file);
        $this->assertNull($finding->line);
        $this->assertSame($violation->context, $finding->context);
        $this->assertSame('add middleware', $finding->fix_hint);
    }

    public function test_invariant_check_name_is_the_invariant_id(): void
    {
        $invariant = new class implements Invariant {
            public function id(): string
            {
                return 'my-id';
            }

            public function description(): string
            {
                return '';
            }

            public function evaluate(InvariantInput $input): array
            {
                return [];
            }
        };

        $input = new InvariantInput(null, '', '', new AstParser(), null, null);

        $this->assertSame('my-id', (new InvariantCheck($invariant, $input))->name());
    }
}
