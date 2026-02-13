<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Console\Doctor;

readonly class CheckResult
{
    public function __construct(
        public string $status,
        public string $label,
        public string $message,
    ) {}

    public function isError(): bool
    {
        return $this->status === 'ERROR';
    }

    public static function ok(string $label, string $message): self
    {
        return new self('OK', $label, $message);
    }

    public static function warn(string $label, string $message): self
    {
        return new self('WARN', $label, $message);
    }

    public static function error(string $label, string $message): self
    {
        return new self('ERROR', $label, $message);
    }
}
