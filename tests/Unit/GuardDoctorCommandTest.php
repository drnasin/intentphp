<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\GuardServiceProvider;
use Orchestra\Testbench\TestCase;

class GuardDoctorCommandTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GuardServiceProvider::class];
    }

    public function test_doctor_command_runs_and_exits_zero(): void
    {
        $this->artisan('guard:doctor')
            ->assertExitCode(0);
    }

    public function test_doctor_command_shows_section_headers(): void
    {
        $this->artisan('guard:doctor')
            ->expectsOutputToContain('Laravel Context')
            ->expectsOutputToContain('Storage / Writable')
            ->expectsOutputToContain('Git')
            ->expectsOutputToContain('Baseline')
            ->expectsOutputToContain('AI Driver')
            ->expectsOutputToContain('Cache')
            ->expectsOutputToContain('Result:')
            ->assertExitCode(0);
    }
}
