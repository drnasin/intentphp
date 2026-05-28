<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Checks;

use IntentPHP\Guard\Analysis\AstParser;
use IntentPHP\Guard\Analysis\RequestExpr;
use IntentPHP\Guard\Scan\Finding;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use Symfony\Component\Finder\Finder;

class DangerousQueryInputCheck implements CheckInterface
{
    /** Raw-SQL builder methods whose first argument is an SQL fragment. */
    private const RAW_SINKS = [
        'whereRaw', 'orderByRaw', 'havingRaw', 'groupByRaw', 'selectRaw', 'fromRaw',
    ];

    /** Builder methods that take a column/value and are dangerous with raw input. */
    private const INPUT_SINKS = ['orderBy', 'where', 'whereColumn'];

    /** DB facade methods whose first argument is an SQL string. */
    private const DB_SINKS = [
        'raw', 'statement', 'select', 'selectOne', 'insert', 'update', 'delete', 'unprepared',
    ];

    private const SORT_VAR = '/^(sort|order|column|field|dir)/i';

    private readonly AstParser $ast;

    /**
     * @param string[]|null $onlyFiles When set, only scan these absolute paths
     */
    public function __construct(
        private readonly string $controllersPath,
        private readonly ?array $onlyFiles = null,
        ?AstParser $ast = null,
    ) {
        $this->ast = $ast ?? new AstParser();
    }

    public function name(): string
    {
        return 'dangerous-query-input';
    }

    /** @return Finding[] */
    public function run(): array
    {
        $findings = [];
        $nodeFinder = new NodeFinder();

        foreach ($this->filesToScan() as [$filePath, $contents]) {
            $stmts = $this->ast->parse($filePath, $contents);

            if ($stmts === null) {
                // Parse failure is recorded on the shared parser and surfaced as
                // a scan/parse-error finding by the command — never silently dropped.
                continue;
            }

            $lines = explode("\n", $contents);

            /** @var array<Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall> $calls */
            $calls = $nodeFinder->find(
                $stmts,
                static fn (Node $n): bool => $n instanceof Expr\MethodCall
                    || $n instanceof Expr\NullsafeMethodCall
                    || $n instanceof Expr\StaticCall,
            );

            foreach ($calls as $call) {
                $finding = $this->classify($call, $filePath, $lines);

                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    private function classify(Node $call, string $filePath, array $lines): ?Finding
    {
        if (! $call->name instanceof Node\Identifier) {
            return null; // dynamic call name
        }

        $method = $call->name->toString();
        $args = $call->args;
        $first = $args[0]->value ?? null;

        // DB::raw(...) / DB::statement(...) / DB::select(...) etc.
        if ($call instanceof Expr\StaticCall && $this->isDbFacade($call->class) && in_array($method, self::DB_SINKS, true)) {
            if ($first !== null && RequestExpr::embedsRequest($first)) {
                return $this->high($call, $filePath, $lines, "DB::{$method}", "DB::{$method}() built from request input");
            }

            return null;
        }

        if (! $call instanceof Expr\MethodCall && ! $call instanceof Expr\NullsafeMethodCall) {
            return null;
        }

        // Raw builder methods: ->whereRaw("... $x ..."), ->orderByRaw('...'.$req), etc.
        if (in_array($method, self::RAW_SINKS, true)) {
            if ($first !== null && RequestExpr::embedsRequest($first)) {
                return $this->high($call, $filePath, $lines, $method, "{$method}() built from request input");
            }

            return null;
        }

        // Column methods: only the FIRST argument (the column/expression) is an
        // injection sink. A request value in a later argument is a parameterized
        // binding (->where('col', $request->x)) and is safe — do not flag it.
        if (in_array($method, self::INPUT_SINKS, true)) {
            if ($first !== null && RequestExpr::embedsRequest($first)) {
                return $this->high($call, $filePath, $lines, $method, "{$method}() with request input");
            }

            // Lower-confidence name heuristic (the value may be validated):
            // ->orderBy($sort) / $orderColumn / $direction ...
            if ($method === 'orderBy' && $first instanceof Expr\Variable && is_string($first->name)
                && preg_match(self::SORT_VAR, $first->name)
            ) {
                return $this->medium($call, $filePath, $lines);
            }
        }

        return null;
    }

    private function high(Node $call, string $filePath, array $lines, string $sink, string $what): Finding
    {
        return Finding::high(
            check: $this->name(),
            message: "Dangerous query input detected: {$what}.",
            file: $filePath,
            line: $this->sinkLine($call),
            context: [
                'pattern' => $what,
                'sink' => $sink,
                'snippet' => $this->snippet($call, $lines),
            ],
            fix_hint: 'Never pass raw request input into query builder methods. Validate and whitelist allowed values before use.',
        );
    }

    private function medium(Node $call, string $filePath, array $lines): Finding
    {
        return Finding::medium(
            check: $this->name(),
            message: 'Possible dangerous query input (unverified variable): orderBy with a sort-like variable. Confirm the value is validated/whitelisted before it reaches the query.',
            file: $filePath,
            line: $this->sinkLine($call),
            context: [
                'pattern' => 'orderBy with a sort-like variable',
                'sink' => 'orderBy',
                'snippet' => $this->snippet($call, $lines),
            ],
            fix_hint: 'If the column/direction comes from request input, map it through an allowlist (in_array/match) before use.',
        );
    }

    private function isDbFacade(Node $class): bool
    {
        return $class instanceof Node\Name && $class->getLast() === 'DB';
    }

    /** Line of the sink method name itself (not the chain root). */
    private function sinkLine(Node $call): int
    {
        /** @var Expr\MethodCall|Expr\NullsafeMethodCall|Expr\StaticCall $call */
        return $call->name->getStartLine();
    }

    /**
     * @param string[] $lines
     */
    private function snippet(Node $call, array $lines): string
    {
        $start = max(0, $call->getStartLine() - 1);
        $end = min(count($lines) - 1, $call->getEndLine() - 1);

        $text = trim(implode(' ', array_map('trim', array_slice($lines, $start, $end - $start + 1))));

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }

    /**
     * @return iterable<array{0: string, 1: string}>
     */
    private function filesToScan(): iterable
    {
        if ($this->onlyFiles !== null) {
            $controllerDir = rtrim(str_replace('\\', '/', $this->controllersPath), '/');

            foreach ($this->onlyFiles as $file) {
                $normalized = str_replace('\\', '/', $file);

                if (str_ends_with($normalized, '.php')
                    && str_starts_with($normalized, $controllerDir)
                    && is_readable($file)
                ) {
                    yield [$file, (string) file_get_contents($file)];
                }
            }

            return;
        }

        if (! is_dir($this->controllersPath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($this->controllersPath)->name('*.php');

        foreach ($finder as $file) {
            yield [$file->getRealPath(), $file->getContents()];
        }
    }
}
