<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Tests\Unit\Middleware;

use Contenir\Maintenance\MaintenanceState;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use Contenir\Maintenance\Mezzio\Tests\TestAsset\Handler\StubRequestHandler;
use Contenir\Maintenance\Repository\InMemoryRepository;
use DateTimeImmutable;
use DateTimeZone;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function delegatesToTheHandlerWhenMaintenanceIsInactive(): void
    {
        $response = $this->process(MaintenanceState::inactive());

        static::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[Test]
    public function delegatesWhenTheBypassAllowsTheRequest(): void
    {
        $response = $this->process(
            MaintenanceState::active('m'),
            static fn(ServerRequestInterface $request): bool => true,
        );

        static::assertSame(StubRequestHandler::BODY, (string) $response->getBody());
    }

    #[Test]
    public function doesNotConsultTheBypassWhenMaintenanceIsInactive(): void
    {
        $consulted = false;
        $this->process(
            MaintenanceState::inactive(),
            static function (ServerRequestInterface $request) use (&$consulted): bool {
                $consulted = true;

                return false;
            },
        );

        static::assertFalse($consulted);
    }

    #[Test]
    #[DataProvider('unsafeMessageProvider')]
    public function escapesTheMessage(string $message, string $expected): void
    {
        $response = $this->process(MaintenanceState::active($message), bodyTemplate: '%s');

        static::assertSame($expected, (string) $response->getBody());
    }

    #[Test]
    public function forbidsCachingTheResponse(): void
    {
        $response = $this->process(MaintenanceState::active('m'));

        static::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    #[DataProvider('bypassHeaderProvider')]
    public function handsTheRequestToTheBypass(ServerRequestInterface $request, int $expectedStatus): void
    {
        $response = $this->process(
            MaintenanceState::active('m'),
            static fn(ServerRequestInterface $request): bool => $request->hasHeader('X-Maintenance-Bypass'),
            $request,
        );

        static::assertSame($expectedStatus, $response->getStatusCode());
    }

    #[Test]
    public function passesAnEmptySinceWhenTheStateHasNone(): void
    {
        $response = $this->process(
            new MaintenanceState(
                active: true,
                message: 'Down',
                since: null,
            ),
            bodyTemplate: '<p>%s</p><time datetime="%2$s"></time>',
        );

        static::assertSame('<p>Down</p><time datetime=""></time>', (string) $response->getBody());
    }

    #[Test]
    public function passesTheSinceTimeToTheTemplateAsIso8601(): void
    {
        $since    = new DateTimeImmutable('2026-05-05 13:14:15', new DateTimeZone('Australia/Sydney'));
        $response = $this->process(
            MaintenanceState::active('Down', $since),
            bodyTemplate: '<p>%1$s</p><time datetime="%2$s"></time>',
        );

        static::assertSame(
            '<p>Down</p><time datetime="2026-05-05T13:14:15+10:00"></time>',
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function rendersTheDefaultBodyTemplate(): void
    {
        $response = $this->process(MaintenanceState::active('Back at noon'));

        static::assertStringContainsString('<p>Back at noon</p>', (string) $response->getBody());
    }

    #[Test]
    public function rendersTheMessageIntoTheBodyTemplate(): void
    {
        $response = $this->process(MaintenanceState::active('Back at noon'), bodyTemplate: 'MAINT: %s');

        static::assertSame('MAINT: Back at noon', (string) $response->getBody());
    }

    #[Test]
    public function respondsWithServiceUnavailableWhenMaintenanceIsActive(): void
    {
        $response = $this->process(MaintenanceState::active('Down for upgrade'));

        static::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function respondsWithServiceUnavailableWhenTheBypassRefusesTheRequest(): void
    {
        $response = $this->process(
            MaintenanceState::active('m'),
            static fn(ServerRequestInterface $request): bool => false,
        );

        static::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function sendsTheConfiguredRetryAfter(): void
    {
        $response = $this->process(MaintenanceState::active('m'), retryAfter: 1800);

        static::assertSame('1800', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function sendsTheDefaultRetryAfter(): void
    {
        $response = $this->process(MaintenanceState::active('m'));

        static::assertSame('600', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function servesTheResponseAsUtf8Html(): void
    {
        $response = $this->process(MaintenanceState::active('m'));

        static::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
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
