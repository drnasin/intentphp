<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Scan;

use IntentPHP\Guard\Checks\CheckInterface;

class Scanner
{
    /** @var CheckInterface[] */
    private array $checks = [];

    /**
     * @param CheckInterface[] $checks
     */
    public function __construct(array $checks = [])
    {
        foreach ($checks as $check) {
            $this->addCheck($check);
        }
    }

    public function addCheck(CheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * @return Finding[]
     */
    public function run(): array
    {
        $findings = [];

        foreach ($this->checks as $check) {
            $findings = array_merge($findings, $check->run());
        }

        return $findings;
    }

    /**
     * @return Finding[]
     */
    public function runAndFilter(string $severity = 'all'): array
    {
        $findings = $this->run();

        if ($severity === 'all') {
            return $findings;
        }

        return array_values(array_filter(
            $findings,
            fn (Finding $f) => $f->severity === $severity,
        ));
    }
}
