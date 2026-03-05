<?php

declare(strict_types=1);

namespace IntentPHP\Guard\Tests\Unit\Intent\Drift;

use IntentPHP\Guard\Intent\Drift\Context\ObservedModel;
use PHPUnit\Framework\TestCase;

class ObservedModelParsingTest extends TestCase
{
    private function parse(string $contents): ObservedModel
    {
        return ObservedModel::fromFileContents('App\\Models\\Test', '/app/Models/Test.php', $contents);
    }

    public function test_inline_fillable(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class User extends Model
{
    protected $fillable = ['name', 'email'];
}
PHP);

        $this->assertTrue($model->hasFillable);
        $this->assertTrue($model->fillableParseable);
        $this->assertSame(['name', 'email'], $model->fillableAttrs);
    }

    public function test_multiline_fillable(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password',
    ];
}
PHP);

        $this->assertTrue($model->hasFillable);
        $this->assertTrue($model->fillableParseable);
        $this->assertSame(['name', 'email', 'password'], $model->fillableAttrs);
    }

    public function test_empty_fillable(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class User extends Model
{
    protected $fillable = [];
}
PHP);

        $this->assertTrue($model->hasFillable);
        $this->assertTrue($model->fillableParseable);
        $this->assertSame([], $model->fillableAttrs);
    }

    public function test_empty_guarded(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class Post extends Model
{
    protected $guarded = [];
}
PHP);

        $this->assertTrue($model->guardedIsEmpty);
    }

    public function test_populated_guarded(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class Post extends Model
{
    protected $guarded = ['id', 'created_at'];
}
PHP);

        $this->assertFalse($model->guardedIsEmpty);
    }

    public function test_dynamic_fillable_constant(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class User extends Model
{
    protected $fillable = self::FIELDS;
}
PHP);

        $this->assertTrue($model->hasFillable);
        $this->assertFalse($model->fillableParseable);
        $this->assertSame([], $model->fillableAttrs);
    }

    public function test_spread_operator_unparseable(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class User extends Model
{
    protected $fillable = [...parent::FILLABLE, 'extra'];
}
PHP);

        $this->assertTrue($model->hasFillable);
        $this->assertFalse($model->fillableParseable);
        $this->assertSame([], $model->fillableAttrs);
    }

    public function test_variable_reference_unparseable(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class User extends Model
{
    protected $fillable = [$baseField, 'name'];
}
PHP);

        $this->assertTrue($model->hasFillable);
        $this->assertFalse($model->fillableParseable);
        $this->assertSame([], $model->fillableAttrs);
    }

    public function test_no_property_at_all(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class User extends Model
{
    // No fillable or guarded
}
PHP);

        $this->assertFalse($model->hasFillable);
        $this->assertTrue($model->fillableParseable);
        $this->assertSame([], $model->fillableAttrs);
        $this->assertFalse($model->guardedIsEmpty);
    }

    public function test_double_quoted_attrs(): void
    {
        $model = $this->parse(<<<'PHP'
<?php
class User extends Model
{
    protected $fillable = ["name", "email"];
}
PHP);

        $this->assertTrue($model->hasFillable);
        $this->assertTrue($model->fillableParseable);
        $this->assertSame(['name', 'email'], $model->fillableAttrs);
    }

    public function test_fqcn_and_filepath_preserved(): void
    {
        $model = ObservedModel::fromFileContents(
            'App\\Models\\User',
            '/project/app/Models/User.php',
            '<?php class User extends Model { protected $fillable = []; }',
        );

        $this->assertSame('App\\Models\\User', $model->fqcn);
        $this->assertSame('/project/app/Models/User.php', $model->filePath);
    }
}
