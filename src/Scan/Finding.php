<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Scan;

final readonly class Finding
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $check,
        public string $severity,
        public string $message,
        public ?string $file,
        public ?int $line,
        public array $context,
        public string $fix_hint,
        public ?string $ai_suggestion = null,
        public ?string $suppressed_reason = null,
    ) {}

    public function fingerprint(): string
    {
        return Fingerprint::compute($this);
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed_reason !== null;
    }

    public function withSuppression(string $reason): self
    {
        return new self(
            $this->check,
            $this->severity,
            $this->message,
            $this->file,
            $this->line,
            $this->context,
            $this->fix_hint,
            $this->ai_suggestion,
            $reason,
        );
    }

    public function withAiSuggestion(string $suggestion): self
    {
        return new self(
            $this->check,
            $this->severity,
            $this->message,
            $this->file,
            $this->line,
            $this->context,
            $this->fix_hint,
            $suggestion,
            $this->suppressed_reason,
        );
    }

    /**
     * Return a new Finding with extra keys merged into context.
     *
     * @param array<string, mixed> $extra
     */
    public function withMergedContext(array $extra): self
    {
        return new self(
            $this->check,
            $this->severity,
            $this->message,
            $this->file,
            $this->line,
            array_merge($this->context, $extra),
            $this->fix_hint,
            $this->ai_suggestion,
            $this->suppressed_reason,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function high(
        string $check,
        string $message,
        ?string $file = null,
        ?int $line = null,
        array $context = [],
        string $fix_hint = '',
    ): self {
        return new self($check, 'high', $message, $file, $line, $context, $fix_hint);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function medium(
        string $check,
        string $message,
        ?string $file = null,
        ?int $line = null,
        array $context = [],
        string $fix_hint = '',
    ): self {
        return new self($check, 'medium', $message, $file, $line, $context, $fix_hint);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'check' => $this->check,
            'severity' => $this->severity,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'context' => $this->context,
            'fix_hint' => $this->fix_hint,
            'fingerprint' => $this->fingerprint(),
        ];

        if ($this->ai_suggestion !== null) {
            $data['ai_suggestion'] = $this->ai_suggestion;
        }

        if ($this->suppressed_reason !== null) {
            $data['suppressed_reason'] = $this->suppressed_reason;
        }

        return $data;
    }
}
