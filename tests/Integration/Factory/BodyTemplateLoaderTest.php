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

    public function testBundledTemplateHoldsExactlyOneMessagePlaceholder(): void
    {
        $template = (new BodyTemplateLoader())->resolve([]);

        self::assertSame([1, 1], [substr_count($template, needle: '%'), substr_count($template, needle: '%s')]);
    }

    public function testClosesItsOutputBufferWhenATemplateThrows(): void
    {
        $level = ob_get_level();
        $path  = $this->writeTemporaryFile('broken.phtml', 'partial<?php throw new DomainException("template broke");');

        try {
            $this->resolvePath($path);
            self::fail('The template exception should propagate.');
        } catch (DomainException $exception) {
            self::assertSame(['template broke', $level], [$exception->getMessage(), ob_get_level()]);
        }
    }

    #[DataProvider('phpExtensionProvider')]
    public function testEvaluatesPhpTemplates(string $fileName): void
    {
        $path = $this->writeTemporaryFile($fileName, '<p><?= strtoupper("hello") ?>: %s</p>');

        self::assertSame('<p>HELLO: %s</p>', $this->resolvePath($path));
    }

    public function testLoadsTheBundledTemplateByDefault(): void
    {
        $template = (new BodyTemplateLoader())->resolve([]);

        self::assertStringStartsWith('<!doctype html>', $template);
    }

    public function testReadsANonPhpTemplateRaw(): void
    {
        $path = $this->writeTemporaryFile('maintenance.html', '<p>Raw <?= "php" ?>: %s</p>');

        self::assertSame('<p>Raw <?= "php" ?>: %s</p>', $this->resolvePath($path));
    }

    public function testRejectsAFileThatCannotBeRead(): void
    {
        $path = $this->writeTemporaryFile('locked.html', '%s');
        chmod($path, permissions: 0o000);

        if (is_readable($path)) {
            self::markTestSkipped('Running as a user that can read any file, so permissions cannot lock it.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a readable file.');

        $this->resolvePath($path);
    }

    public function testRejectsAPathThatDoesNotExist(): void
    {
        $path = $this->temporaryPath('missing.phtml');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf('body_template_path "%s" is not a readable file.', $path));

        $this->resolvePath($path);
    }

    public function testRejectsAPathThatIsADirectory(): void
    {
        $path = $this->temporaryPath('templates.html');
        mkdir($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not a readable file.');

        $this->resolvePath($path);
    }

    public function testRejectsATemplateThatCannotBeOpenedAfterPassingTheReadabilityChecks(): void
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

    public function testRendersPhpTemplatesWithoutAccessToLoaderScope(): void
    {
        $path = $this->writeTemporaryFile(
            'scope.phtml',
            '<?= isset($maintenance) || isset($path) || isset($this) ? "leaked" : "isolated" ?>',
        );

        self::assertSame('isolated', $this->resolvePath($path));
    }

    public function testRestoresTheErrorHandlerWhenOpeningATemplateThrows(): void
    {
        $handler = $this->currentErrorHandler();
        $path    = ThrowingFileStreamWrapper::PROTOCOL . '://template.html';
        ThrowingFileStreamWrapper::register();

        try {
            $this->resolvePath($path);
            self::fail('The stream exception should propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                [ThrowingFileStreamWrapper::MESSAGE, $handler],
                [$exception->getMessage(), $this->currentErrorHandler()],
            );
        } finally {
            ThrowingFileStreamWrapper::unregister();
        }
    }

    public function testSilencesTheWarningWhenATemplateCannotBeOpened(): void
    {
        $path = UnopenableFileStreamWrapper::PROTOCOL . '://template.html';
        UnopenableFileStreamWrapper::register();
        error_clear_last();

        try {
            $this->resolvePath($path);
            self::fail('An unopenable template should be rejected.');
        } catch (RuntimeException) {
            self::assertNull(error_get_last());
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
