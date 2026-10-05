<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\Unit\Middleware;

use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use Contenir\Maintenance\Mezzio\Tests\TestAsset\Handler\StubRequestHandler;
use Contenir\Maintenance\Repository\InMemoryRepository;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Group('unit')]
#[Group('middleware')]
final class MaintenanceMiddlewareTest extends TestCase
{
    /**
     * @return array<string, array{ServerRequestInterface, int}>
     */
    public static function bypassHeaderProvider(): array
    {
        return [
            'request carrying the bypass header' => [
                (new ServerRequest())->withHeader('X-Maintenance-Bypass', '1'),
                200,
            ],
            'request without the bypass header'  => [new ServerRequest(), 503],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeMessageProvider(): array
    {
        return [
            'markup'           => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'double quotes'    => ['say "hi"', 'say &quot;hi&quot;'],
            'single quotes'    => ["it's", 'it&#039;s'],
            'ampersand'        => ['fish & chips', 'fish &amp; chips'],
            'invalid utf-8'    => ["bad \xC3\x28 byte", "bad \u{FFFD}( byte"],
            'percent sequence' => ['100%s sure', '100%s sure'],
        ];
    }

    public function testDelegatesToTheHandlerWhenMaintenanceIsInactive(): void
    {
        $response = $this->process(MaintenanceState::inactive());

        self::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    public function testDelegatesWhenTheBypassAllowsTheRequest(): void
    {
        $response = $this->process(
            MaintenanceState::active('m'),
            static fn(ServerRequestInterface $request): bool => true,
        );

        self::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    public function testDoesNotConsultTheBypassWhenMaintenanceIsInactive(): void
    {
        $consulted = false;
        $this->process(
            MaintenanceState::inactive(),
            static function (ServerRequestInterface $request) use (&$consulted): bool {
                $consulted = true;

                return false;
            },
        );

        self::assertFalse($consulted);
    }

    #[DataProvider('unsafeMessageProvider')]
    public function testEscapesTheMessage(string $message, string $expected): void
    {
        $response = $this->process(MaintenanceState::active($message), bodyTemplate: '%s');

        self::assertSame($expected, (string) $response->getBody());
    }

    public function testForbidsCachingTheResponse(): void
    {
        $response = $this->process(MaintenanceState::active('m'));

        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[DataProvider('bypassHeaderProvider')]
    public function testHandsTheRequestToTheBypass(ServerRequestInterface $request, int $expectedStatus): void
    {
        $response = $this->process(
            MaintenanceState::active('m'),
            static fn(ServerRequestInterface $request): bool => $request->hasHeader('X-Maintenance-Bypass'),
            $request,
        );

        self::assertSame($expectedStatus, $response->getStatusCode());
    }

    public function testRendersTheDefaultBodyTemplate(): void
    {
        $response = $this->process(MaintenanceState::active('Back at noon'));

        self::assertStringContainsString('<p>Back at noon</p>', (string) $response->getBody());
    }

    public function testRendersTheMessageIntoTheBodyTemplate(): void
    {
        $response = $this->process(MaintenanceState::active('Back at noon'), bodyTemplate: 'MAINT: %s');

        self::assertSame('MAINT: Back at noon', (string) $response->getBody());
    }

    public function testRespondsWithServiceUnavailableWhenMaintenanceIsActive(): void
    {
        $response = $this->process(MaintenanceState::active('Down for upgrade'));

        self::assertSame(503, $response->getStatusCode());
    }

    public function testRespondsWithServiceUnavailableWhenTheBypassRefusesTheRequest(): void
    {
        $response = $this->process(
            MaintenanceState::active('m'),
            static fn(ServerRequestInterface $request): bool => false,
        );

        self::assertSame(503, $response->getStatusCode());
    }

    public function testSendsTheConfiguredRetryAfter(): void
    {
        $response = $this->process(MaintenanceState::active('m'), retryAfter: 1800);

        self::assertSame('1800', $response->getHeaderLine('Retry-After'));
    }

    public function testSendsTheDefaultRetryAfter(): void
    {
        $response = $this->process(MaintenanceState::active('m'));

        self::assertSame('600', $response->getHeaderLine('Retry-After'));
    }

    public function testServesTheResponseAsUtf8Html(): void
    {
        $response = $this->process(MaintenanceState::active('m'));

        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    /**
     * @param (callable(ServerRequestInterface): bool)|null $bypass
     */
    private function process(
        MaintenanceState $state,
        ?callable $bypass = null,
        ?ServerRequestInterface $request = null,
        int $retryAfter = MaintenanceMiddleware::DEFAULT_RETRY_AFTER,
        string $bodyTemplate = MaintenanceMiddleware::DEFAULT_BODY_TEMPLATE,
    ): ResponseInterface {
        $middleware = new MaintenanceMiddleware(new InMemoryRepository($state), $retryAfter, $bodyTemplate, $bypass);

        return $middleware->process($request ?? new ServerRequest(), new StubRequestHandler());
    }
}
