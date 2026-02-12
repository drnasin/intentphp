<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\Git\GitHelper;
use PHPUnit\Framework\TestCase;

class RouteScanModeTest extends TestCase
{
    public function test_null_returns_full(): void
    {
        $this->assertSame('full', GitHelper::determineRouteScanMode(null));
    }

    public function test_route_files_in_set_returns_full(): void
    {
        $files = [
            '/project/routes/web.php',
            '/project/app/Http/Controllers/UserController.php',
        ];

        $this->assertSame('full', GitHelper::determineRouteScanMode($files));
    }

    public function test_only_controller_files_returns_filtered(): void
    {
        $files = [
            '/project/app/Http/Controllers/UserController.php',
            '/project/app/Http/Controllers/Admin/DashboardController.php',
        ];

        $this->assertSame('filtered', GitHelper::determineRouteScanMode($files));
    }

    public function test_only_model_service_files_returns_skipped(): void
    {
        $files = [
            '/project/app/Models/User.php',
            '/project/app/Services/PaymentService.php',
        ];

        $this->assertSame('skipped', GitHelper::determineRouteScanMode($files));
    }

    public function test_empty_array_returns_skipped(): void
    {
        $this->assertSame('skipped', GitHelper::determineRouteScanMode([]));
    }

    public function test_both_route_and_controller_returns_full(): void
    {
        $files = [
            '/project/routes/api.php',
            '/project/app/Http/Controllers/OrderController.php',
        ];

        $this->assertSame('full', GitHelper::determineRouteScanMode($files));
    }
}
