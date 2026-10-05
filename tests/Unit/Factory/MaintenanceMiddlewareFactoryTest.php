<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\Unit\Factory;

use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Mezzio\Factory\MaintenanceMiddlewareFactory;
use Contenir\Maintenance\Mezzio\Tests\TestAsset\Container\ArrayContainer;
use Contenir\Maintenance\Mezzio\Tests\TestAsset\Handler\StubRequestHandler;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Every case sets an inline body_template and, unless it is about the `file`
 * option, registers an in-memory repository, so nothing here touches the
 * filesystem. File-backed behaviour lives in the integration suite.
 */
#[Group('unit')]
#[Group('factory')]
final class MaintenanceMiddlewareFactoryTest extends TestCase
{
    public static function allowEveryRequest(ServerRequestInterface $request): bool
    {
        return true;
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidFileProvider(): array
    {
        return [
            'empty string' => [''],
            'integer'      => [42],
            'array'        => [['maintenance.local.php']],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidRetryAfterProvider(): array
    {
        return [
            'word'  => ['soon'],
            'null'  => [null],
            'array' => [[600]],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonTrueBypassResultProvider(): array
    {
        return [
            'false'          => [false],
            'truthy integer' => [1],
            'truthy string'  => ['yes'],
            'null'           => [null],
        ];
    }

    /**
     * @return array<string, array{int|float|string, string}>
     */
    public static function retryAfterProvider(): array
    {
        return [
            'integer'        => [1800, '1800'],
            'numeric string' => ['120', '120'],
            'float'          => [90.5, '90'],
        ];
    }

    #[Test]
    public function acceptsAStaticMethodCallableAsTheBypass(): void
    {
        $response = $this->respond(['bypass' => [self::class, 'allowEveryRequest']]);

        static::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[Test]
    public function handsTheBodyTemplateConfigToTheMiddleware(): void
    {
        $response = $this->respond(['body_template' => '<p>%s</p>'], 'Back soon');

        static::assertSame('<p>Back soon</p>', (string) $response->getBody());
    }

    #[Test]
    public function ignoresTheFileOptionWhenARepositoryIsRegistered(): void
    {
        $response = $this->respond(['file' => ''], 'From the container');

        static::assertSame('MAINT: From the container', (string) $response->getBody());
    }

    #[Test]
    public function letsTheRequestThroughWhenTheBypassReturnsTrue(): void
    {
        $response = $this->respond(['bypass' => static fn(ServerRequestInterface $request): bool => true]);

        static::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[Test]
    #[DataProvider('nonTrueBypassResultProvider')]
    public function onlyAStrictTrueFromTheBypassLetsTheRequestThrough(mixed $result): void
    {
        $response = $this->respond(['bypass' => static fn(ServerRequestInterface $request): mixed => $result]);

        static::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function rejectsABypassThatIsNotCallable(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('config[maintenance][bypass] must be callable or null.');

        $this->respond(['bypass' => 'not a callable string xyz']);
    }

    #[Test]
    #[DataProvider('invalidFileProvider')]
    public function rejectsAFileOptionThatIsNotANonEmptyString(mixed $file): void
    {
        $container = new ArrayContainer([
            'config' => ['maintenance' => ['file' => $file, 'body_template' => '%s']],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('config[maintenance][file] must be a non-empty string.');

        (new MaintenanceMiddlewareFactory())($container);
    }

    #[Test]
    #[DataProvider('invalidRetryAfterProvider')]
    public function rejectsARetryAfterThatIsNotNumeric(mixed $retryAfter): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('config[maintenance][retry_after] must be a number of seconds.');

        $this->respond(['retry_after' => $retryAfter]);
    }

    #[Test]
    #[DataProvider('retryAfterProvider')]
    public function sendsTheConfiguredRetryAfter(int|float|string $retryAfter, string $expected): void
    {
        $response = $this->respond(['retry_after' => $retryAfter]);

        static::assertSame($expected, $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function sendsTheDefaultRetryAfterWhenNoneIsConfigured(): void
    {
        $response = $this->respond([]);

        static::assertSame('600', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function treatsANullBypassAsNoBypass(): void
    {
        $response = $this->respond(['bypass' => null]);

        static::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function usesTheRepositoryRegisteredInTheContainer(): void
    {
        $response = $this->respond([], 'From the container');

        static::assertSame('MAINT: From the container', (string) $response->getBody());
    }

    /**
     * @param array<string, mixed> $maintenance
     */
    private function respond(array $maintenance, string $message = 'Down for upgrade'): ResponseInterface
    {
        $container = new ArrayContainer([
            'config'                              => ['maintenance' => $maintenance + ['body_template' => 'MAINT: %s']],
            MaintenanceRepositoryInterface::class => new InMemoryRepository(MaintenanceState::active($message)),
        ]);

        return (new MaintenanceMiddlewareFactory())($container)->process(new ServerRequest(), new StubRequestHandler());
    }
}
