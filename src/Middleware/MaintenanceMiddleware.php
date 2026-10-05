<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Middleware;

use Closure;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use DateTimeInterface;
use Laminas\Diactoros\Response\HtmlResponse;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function htmlspecialchars;
use function sprintf;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

/**
 * Answers the request with a 503 response when maintenance mode is active.
 *
 * Resolution order:
 *   1. Repository reports inactive: the request is delegated to the handler.
 *   2. Bypass callable returns true: the request is delegated to the handler.
 *   3. Otherwise: a 503 HTML response is returned with Retry-After and
 *      Cache-Control: no-store headers, and the configured body template
 *      (sprintf-style, single %s for the escaped admin message) as the body.
 *
 * The body template receives two arguments: the HTML-escaped message
 * (`%s` or `%1$s`) and the `since` time as ISO 8601 (`%2$s`), which is an
 * empty string when the state has no `since`. A template that only uses
 * `%s` ignores the second argument.
 *
 * The repository is asked for the state on every request, so a toggle written
 * by the admin takes effect immediately without a config cache clear.
 *
 * @api
 */
final readonly class MaintenanceMiddleware implements MiddlewareInterface
{
    public const string DEFAULT_BODY_TEMPLATE =
        '<!doctype html>'
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<title>503 Service Unavailable</title></head>'
            . '<body><h1>Service Unavailable</h1><p>%s</p></body></html>';

    public const int DEFAULT_RETRY_AFTER = 600;

    /**
     * @var (Closure(ServerRequestInterface): bool)|null
     */
    private ?Closure $bypass;

    /**
     * @param (callable(ServerRequestInterface): bool)|null $bypass
     */
    public function __construct(
        private MaintenanceRepositoryInterface $repository,
        private int $retryAfter = self::DEFAULT_RETRY_AFTER,
        private string $bodyTemplate = self::DEFAULT_BODY_TEMPLATE,
        ?callable $bypass = null,
    ) {
        $this->bypass = null === $bypass ? null : $bypass(...);
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $state = $this->repository->get();

        if (! $state->active || $this->isBypassed($request)) {
            return $handler->handle($request);
        }

        $message = htmlspecialchars($state->message, ENT_QUOTES | ENT_SUBSTITUTE, encoding: 'UTF-8');

        $since = $state->since?->format(DateTimeInterface::ATOM) ?? '';

        return new HtmlResponse(sprintf($this->bodyTemplate, $message, $since), 503, [
            'Retry-After'   => (string) $this->retryAfter,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function isBypassed(ServerRequestInterface $request): bool
    {
        return null !== $this->bypass && ($this->bypass)($request);
    }
}
