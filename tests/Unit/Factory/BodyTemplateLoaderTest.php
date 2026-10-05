<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\Unit\Factory;

use Contenir\Maintenance\Mezzio\Factory\BodyTemplateLoader;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('unit')]
#[Group('factory')]
final class BodyTemplateLoaderTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function blankPathProvider(): array
    {
        return [
            'null'         => [null],
            'empty string' => [''],
            'false'        => [false],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function nonStringTemplateProvider(): array
    {
        return [
            'null'    => [null, 'null'],
            'integer' => [503, 'int'],
            'array'   => [['%s'], 'array'],
        ];
    }

    #[DataProvider('blankPathProvider')]
    public function testFallsBackToTheInlineDefaultWhenThePathIsBlanked(mixed $path): void
    {
        $template = (new BodyTemplateLoader())->resolve(['body_template_path' => $path]);

        self::assertSame(MaintenanceMiddleware::DEFAULT_BODY_TEMPLATE, $template);
    }

    public function testInlineBodyTemplateWinsOverBodyTemplatePath(): void
    {
        $template = (new BodyTemplateLoader())->resolve([
            'body_template'      => 'INLINE: %s',
            'body_template_path' => '/this/path/does/not/exist.html',
        ]);

        self::assertSame('INLINE: %s', $template);
    }

    #[DataProvider('nonStringTemplateProvider')]
    public function testRejectsABodyTemplateThatIsNotAString(mixed $template, string $type): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("config[maintenance][body_template] must be a string, got {$type}.");

        (new BodyTemplateLoader())->resolve(['body_template' => $template]);
    }

    public function testReturnsAnInlineBodyTemplateVerbatim(): void
    {
        $template = (new BodyTemplateLoader())->resolve(['body_template' => 'INLINE: %s']);

        self::assertSame('INLINE: %s', $template);
    }
}
