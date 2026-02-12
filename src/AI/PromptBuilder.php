<?php

declare(strict_types=1);

namespace IntentPHP\Guard\AI;

use IntentPHP\Guard\Scan\Finding;

class PromptBuilder
{
    public function buildFixPrompt(Finding $finding): string
    {
        $parts = [
            'You are a Laravel security expert.',
            'A security scanner found the following issue in a Laravel application.',
            '',
            'Check: ' . $finding->check,
            'Severity: ' . strtoupper($finding->severity),
            'Message: ' . $finding->message,
        ];

        if ($finding->file) {
            $parts[] = 'File: ' . $finding->file . ($finding->line ? ":{$finding->line}" : '');
        }

        if (! empty($finding->context['snippet'])) {
            $parts[] = '';
            $parts[] = 'Code snippet:';
            $parts[] = '```php';
            $parts[] = $finding->context['snippet'];
            $parts[] = '```';
        }

        if (! empty($finding->context['middleware'])) {
            $parts[] = 'Current middleware: ' . implode(', ', $finding->context['middleware']);
        }

        if (! empty($finding->context['action'])) {
            $parts[] = 'Controller action: ' . $finding->context['action'];
        }

        $parts[] = '';
        $parts[] = 'Constraints:';
        $parts[] = '- Suggest the MINIMAL change to fix this issue';
        $parts[] = '- Do NOT refactor surrounding code';
        $parts[] = '- Do NOT add new dependencies';
        $parts[] = '- Produce a safe, production-ready patch';
        $parts[] = '- Assume Laravel 10+ and PHP 8.2+';
        $parts[] = '';

        $parts = array_merge($parts, $this->checkSpecificInstructions($finding));

        $parts[] = '';
        $parts[] = 'Respond with:';
        $parts[] = '1. A brief explanation of the risk (2-3 sentences)';
        $parts[] = '2. The recommended fix strategy';
        $parts[] = '3. A unified diff patch showing the change';

        return implode("\n", $parts);
    }

    /**
     * @return string[]
     */
    private function checkSpecificInstructions(Finding $finding): array
    {
        return match ($finding->check) {
            'route-authorization' => [
                'Fix strategy options:',
                '- Add auth middleware to the route or route group',
                '- Add $this->authorize() call in the controller method',
                '- Add a Gate check in the controller method',
                'Choose the most appropriate approach based on the route context.',
            ],
            'dangerous-query-input' => [
                'Fix strategy:',
                '- Replace raw request input with a validated, allowlisted value',
                '- Create an allowlist array mapping safe column names',
                '- Use in_array() or match() to validate before passing to query builder',
                '- Never pass user input directly into orderBy, where, or raw queries',
            ],
            'mass-assignment' => [
                'Fix strategy options:',
                '- Replace $request->all() with $request->only([...]) listing specific fields',
                '- Or use $request->validated() if a FormRequest is available',
                '- Define a $fillable property on the model listing allowed attributes',
                '- Never use $guarded = [] in production',
            ],
            default => [],
        };
    }
}
