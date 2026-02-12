<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI\Cli;

class ArgParser
{
    /**
     * Split a string of arguments, respecting single and double quotes.
     *
     * @return string[]
     */
    public static function parse(string $args): array
    {
        $args = trim($args);

        if ($args === '') {
            return [];
        }

        $tokens = [];
        $current = '';
        $inQuote = null;
        $len = strlen($args);

        for ($i = 0; $i < $len; $i++) {
            $char = $args[$i];

            if ($inQuote === null) {
                if ($char === '"' || $char === "'") {
                    $inQuote = $char;
                } elseif ($char === ' ' || $char === "\t") {
                    if ($current !== '') {
                        $tokens[] = $current;
                        $current = '';
                    }
                } else {
                    $current .= $char;
                }
            } elseif ($char === $inQuote) {
                $inQuote = null;
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
