<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Intent\Drift\Context;

final readonly class ObservedModel
{
    /**
     * @param string   $fqcn              Fully-qualified class name
     * @param string   $filePath          Absolute path to model file
     * @param bool     $hasFillable       Whether $fillable property exists
     * @param bool     $fillableParseable Whether $fillable uses a literal array (parseable for attr extraction)
     * @param string[] $fillableAttrs     Extracted attribute names (empty if !parseable or !hasFillable)
     * @param bool     $guardedIsEmpty    Whether $guarded = []
     */
    public function __construct(
        public string $fqcn,
        public string $filePath,
        public bool $hasFillable,
        public bool $fillableParseable,
        public array $fillableAttrs,
        public bool $guardedIsEmpty,
    ) {}

    /**
     * Parse a model file's contents and build an ObservedModel.
     *
     * Supported patterns (v1, regex-based):
     * - Literal $fillable = ['attr', ...] (inline or multiline)
     * - Literal $guarded = [] or $guarded = ['attr', ...]
     *
     * Unsupported (fillableParseable = false):
     * - $fillable = self::FIELDS, $fillable = $var, spread operator
     */
    public static function fromFileContents(string $fqcn, string $filePath, string $contents): self
    {
        $hasFillable = false;
        $fillableParseable = true;
        $fillableAttrs = [];

        if (preg_match('/\$fillable\s*=\s*\[(.*?)\]/s', $contents, $match)) {
            $hasFillable = true;
            $body = $match[1];

            if (preg_match('/\.\.\.|\$\w+/', $body)) {
                $fillableParseable = false;
            } else {
                preg_match_all("/['\"]([^'\"]+)['\"]/", $body, $attrMatches);
                $fillableAttrs = $attrMatches[1] ?? [];
            }
        } elseif (preg_match('/\$fillable\s*=/', $contents)) {
            $hasFillable = true;
            $fillableParseable = false;
        }

        $guardedIsEmpty = (bool) preg_match('/\$guarded\s*=\s*\[\s*\]/', $contents);

        return new self(
            fqcn: $fqcn,
            filePath: $filePath,
            hasFillable: $hasFillable,
            fillableParseable: $fillableParseable,
            fillableAttrs: $fillableAttrs,
            guardedIsEmpty: $guardedIsEmpty,
        );
    }
}
