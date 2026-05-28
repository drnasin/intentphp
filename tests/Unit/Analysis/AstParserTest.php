<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Analysis;

use IntentPHP\Guard\Analysis\AstParser;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\TestCase;

class AstParserTest extends TestCase
{
    public function test_parses_valid_php_to_statements(): void
    {
        $stmts = (new AstParser())->parse('a.php', "<?php \$x = 1;");

        $this->assertIsArray($stmts);
        $this->assertContainsOnlyInstancesOf(Stmt::class, $stmts);
    }

    public function test_records_error_and_returns_null_on_unparseable_input(): void
    {
        $parser = new AstParser();

        $result = $parser->parse('broken.php', "<?php class {{{ <<<< CONFLICT");

        $this->assertNull($result);
        $this->assertArrayHasKey('broken.php', $parser->getErrors());
        $this->assertNotSame('', $parser->getErrors()['broken.php']);
    }

    public function test_caches_by_path_and_does_not_crash_on_reparse(): void
    {
        $parser = new AstParser();

        $a = $parser->parse('a.php', "<?php \$x = 1;");
        $b = $parser->parse('a.php', "<?php \$x = 2;"); // ignored — cached by path

        $this->assertSame($a, $b);
        $this->assertSame([], $parser->getErrors());
    }

    public function test_errors_are_sorted_by_path(): void
    {
        $parser = new AstParser();
        $parser->parse('z.php', '<?php !!!');
        $parser->parse('a.php', '<?php ???');

        $this->assertSame(['a.php', 'z.php'], array_keys($parser->getErrors()));
    }
}
