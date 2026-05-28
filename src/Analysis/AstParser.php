<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Analysis;

use PhpParser\Error as PhpParserError;
use PhpParser\Node;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Thin wrapper around nikic/php-parser.
 *
 * Parses PHP source into a statement AST, caching by file path so a file is
 * parsed once even when several checks request it within a single scan. Parse
 * failures are recorded (not thrown) so callers can surface a coverage gap
 * instead of crashing or silently skipping the file.
 */
final class AstParser
{
    private readonly Parser $parser;

    /** @var array<string, list<Node\Stmt>|null> path → AST (or null if it failed) */
    private array $cache = [];

    /** @var array<string, string> path → parser error message */
    private array $errors = [];

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * Parse a file's contents. Returns the statement list, or null if the
     * source could not be parsed (the error is recorded in getErrors()).
     *
     * @return list<Node\Stmt>|null
     */
    public function parse(string $path, string $code): ?array
    {
        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }

        try {
            $stmts = $this->parser->parse($code);
            unset($this->errors[$path]);

            return $this->cache[$path] = $stmts;
        } catch (PhpParserError $e) {
            $this->errors[$path] = $e->getMessage();

            return $this->cache[$path] = null;
        }
    }

    /**
     * Files that failed to parse during this scan, path → error message.
     * Sorted by path for deterministic reporting.
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        $errors = $this->errors;
        ksort($errors, SORT_STRING);

        return $errors;
    }
}
