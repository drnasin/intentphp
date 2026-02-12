<?php

declare(strict_types=1);

namespace Tests\Unit;

use IntentPHP\Guard\Git\GitHelper;
use PHPUnit\Framework\TestCase;

class GitHelperTest extends TestCase
{
    // ── parseFileList ───────────────────────────────────────────────

    public function test_parse_file_list_basic(): void
    {
        $output = "app/Http/Controllers/OrderController.php\napp/Models/Order.php\n";
        $result = GitHelper::parseFileList($output, '/home/user/project');

        $this->assertCount(2, $result);
        $this->assertSame('/home/user/project/app/Http/Controllers/OrderController.php', $result[0]);
        $this->assertSame('/home/user/project/app/Models/Order.php', $result[1]);
    }

    public function test_parse_file_list_empty_output(): void
    {
        $this->assertSame([], GitHelper::parseFileList('', '/project'));
        $this->assertSame([], GitHelper::parseFileList("\n\n", '/project'));
    }

    public function test_parse_file_list_trims_whitespace(): void
    {
        $output = "  app/file.php  \n  routes/web.php  \n";
        $result = GitHelper::parseFileList($output, '/project');

        $this->assertCount(2, $result);
        $this->assertSame('/project/app/file.php', $result[0]);
        $this->assertSame('/project/routes/web.php', $result[1]);
    }

    public function test_parse_file_list_normalizes_backslashes(): void
    {
        $output = "app\\Http\\Controllers\\Test.php\n";
        $result = GitHelper::parseFileList($output, 'C:/project');

        $this->assertSame('C:/project/app/Http/Controllers/Test.php', $result[0]);
    }

    public function test_parse_file_list_strips_trailing_slash_from_base(): void
    {
        $output = "file.php\n";
        $result = GitHelper::parseFileList($output, '/project/');

        $this->assertSame('/project/file.php', $result[0]);
    }

    // ── containsRouteFiles ──────────────────────────────────────────

    public function test_contains_route_files_true(): void
    {
        $files = [
            '/project/app/Http/Controllers/OrderController.php',
            '/project/routes/web.php',
        ];

        $this->assertTrue(GitHelper::containsRouteFiles($files));
    }

    public function test_contains_route_files_api(): void
    {
        $files = ['/project/routes/api.php'];

        $this->assertTrue(GitHelper::containsRouteFiles($files));
    }

    public function test_contains_route_files_false(): void
    {
        $files = [
            '/project/app/Http/Controllers/OrderController.php',
            '/project/app/Models/Order.php',
        ];

        $this->assertFalse(GitHelper::containsRouteFiles($files));
    }

    public function test_contains_route_files_empty(): void
    {
        $this->assertFalse(GitHelper::containsRouteFiles([]));
    }

    public function test_contains_route_files_windows_paths(): void
    {
        $files = ['C:\\project\\routes\\web.php'];

        $this->assertTrue(GitHelper::containsRouteFiles($files));
    }

    // ── containsControllerFiles ────────────────────────────────────

    public function test_contains_controller_files_direct(): void
    {
        $files = ['/project/app/Http/Controllers/UserController.php'];

        $this->assertTrue(GitHelper::containsControllerFiles($files));
    }

    public function test_contains_controller_files_nested_subdir(): void
    {
        $files = ['/project/app/Http/Controllers/Admin/DashboardController.php'];

        $this->assertTrue(GitHelper::containsControllerFiles($files));
    }

    public function test_contains_controller_files_false_models_only(): void
    {
        $files = [
            '/project/app/Models/User.php',
            '/project/app/Models/Order.php',
        ];

        $this->assertFalse(GitHelper::containsControllerFiles($files));
    }

    public function test_contains_controller_files_empty(): void
    {
        $this->assertFalse(GitHelper::containsControllerFiles([]));
    }

    public function test_contains_controller_files_windows_paths(): void
    {
        $files = ['C:\\project\\app\\Http\\Controllers\\UserController.php'];

        $this->assertTrue(GitHelper::containsControllerFiles($files));
    }
}
