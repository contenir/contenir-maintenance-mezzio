<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Test\TestAsset\Handler;

use Laminas\Diactoros\Response\TextResponse;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stands in for the rest of the pipeline: answers every request with 200.
 */
final readonly class StubRequestHandler implements RequestHandlerInterface
{
    public const string BODY = 'handled by the application';

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new TextResponse(self::BODY);
    }
}
