<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\Integration\Factory;

use Contenir\Maintenance\Mezzio\Factory\BodyTemplateLoader;
use Contenir\Maintenance\Mezzio\Tests\TestAsset\Stream\ThrowingFileStreamWrapper;
use Contenir\Maintenance\Mezzio\Tests\TestAsset\Stream\UnopenableFileStreamWrapper;
use Contenir\Maintenance\Mezzio\Tests\Trait\TemporaryDirectoryTrait;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function chmod;
use function error_clear_last;
use function error_get_last;
use function is_readable;
use function mkdir;
use function ob_get_level;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function substr_count;

#[Group('integration')]
#[Group('factory')]
final class BodyTemplateLoaderTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /**
     * @return array<string, array{string}>
     */
    public static function phpExtensionProvider(): array
    {
        return [
            'phtml'            => ['maintenance.phtml'],
            'php'              => ['maintenance.php'],
            'upper-case PHTML' => ['maintenance.PHTML'],
        ];
    }

    #[Test]
    public function bundledTemplateHoldsExactlyOneMessagePlaceholder(): void
    {
        $template = (new BodyTemplateLoader())->resolve([]);

        static::assertSame([1, 1], [substr_count($template, needle: '%'), substr_count($template, needle: '%s')]);
    }

    #[Test]
    public function closesItsOutputBufferWhenATemplateThrows(): void
    {
        $level = ob_get_level();
        $path  = $this->writeTemporaryFile('broken.phtml', 'partial<?php throw new DomainException("template broke");');

        try {
            $this->resolvePath($path);
            static::fail('The template exception should propagate.');
        } catch (DomainException $exception) {
            static::assertSame(['template broke', $level], [$exception->getMessage(), ob_get_level()]);
        }
    }

    #[Test]
    #[DataProvider('phpExtensionProvider')]
    public function evaluatesPhpTemplates(string $fileName): void
    {
        $path = $this->writeTemporaryFile($fileName, '<p><?= strtoupper("hello") ?>: %s</p>');

        static::assertSame('<p>HELLO: %s</p>', $this->resolvePath($path));
    }

    #[Test]
    public function loadsTheBundledTemplateByDefault(): void
    {
        $template = (new BodyTemplateLoader())->resolve([]);

        static::assertStringStartsWith('<!doctype html>', $template);
    }

    #[Test]
    public function readsANonPhpTemplateRaw(): void
    {
        $path = $this->writeTemporaryFile('maintenance.html', '<p>Raw <?= "php" ?>: %s</p>');

        static::assertSame('<p>Raw <?= "php" ?>: %s</p>', $this->resolvePath($path));
    }

    #[Test]
    public function rejectsAFileThatCannotBeRead(): void
    {
        $path = $this->writeTemporaryFile('locked.html', '%s');
        chmod($path, permissions: 0o000);

        if (is_readable($path)) {
            static::markTestSkipped('Running as a user that can read any file, so permissions cannot lock it.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a readable file.');

        $this->resolvePath($path);
    }

    #[Test]
    public function rejectsAPathThatDoesNotExist(): void
    {
        $path = $this->temporaryPath('missing.phtml');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('body_template_path "%s" is not a readable file.', $path));

        $this->resolvePath($path);
    }

    #[Test]
    public function rejectsAPathThatIsADirectory(): void
    {
        $path = $this->temporaryPath('templates.html');
        mkdir($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a readable file.');

        $this->resolvePath($path);
    }

    #[Test]
    public function rejectsATemplateThatCannotBeOpenedAfterPassingTheReadabilityChecks(): void
    {
        $path = UnopenableFileStreamWrapper::PROTOCOL . '://template.html';
        UnopenableFileStreamWrapper::register();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(sprintf('body_template_path "%s" is not a readable file.', $path));

            $this->resolvePath($path);
        } finally {
            UnopenableFileStreamWrapper::unregister();
        }
    }

    #[Test]
    public function rendersPhpTemplatesWithoutAccessToLoaderScope(): void
    {
        $path = $this->writeTemporaryFile(
            'scope.phtml',
            '<?= isset($maintenance) || isset($path) || isset($this) ? "leaked" : "isolated" ?>',
        );

        static::assertSame('isolated', $this->resolvePath($path));
    }

    #[Test]
    public function restoresTheErrorHandlerWhenOpeningATemplateThrows(): void
    {
        $handler = $this->currentErrorHandler();
        $path    = ThrowingFileStreamWrapper::PROTOCOL . '://template.html';
        ThrowingFileStreamWrapper::register();

        try {
            $this->resolvePath($path);
            static::fail('The stream exception should propagate.');
        } catch (RuntimeException $exception) {
            static::assertSame(
                [ThrowingFileStreamWrapper::MESSAGE, $handler],
                [$exception->getMessage(), $this->currentErrorHandler()],
            );
        } finally {
            ThrowingFileStreamWrapper::unregister();
        }
    }

    #[Test]
    public function silencesTheWarningWhenATemplateCannotBeOpened(): void
    {
        $path = UnopenableFileStreamWrapper::PROTOCOL . '://template.html';
        UnopenableFileStreamWrapper::register();
        error_clear_last();

        try {
            $this->resolvePath($path);
            static::fail('An unopenable template should be rejected.');
        } catch (RuntimeException) {
            static::assertNull(error_get_last());
        } finally {
            UnopenableFileStreamWrapper::unregister();
        }
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function currentErrorHandler(): mixed
    {
        $handler = set_error_handler(null);
        restore_error_handler();

        return $handler;
    }

    private function resolvePath(string $path): string
    {
        return (new BodyTemplateLoader())->resolve(['body_template_path' => $path]);
    }
}
