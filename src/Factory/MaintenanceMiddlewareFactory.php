<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Factory;

use Closure;
use Contenir\Maintenance\MaintenanceRepositoryInterface;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use Contenir\Maintenance\Repository\FileRepository;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

use function array_key_exists;
use function getcwd;
use function is_array;
use function is_callable;
use function is_numeric;
use function is_string;

/**
 * Builds the MaintenanceMiddleware from `config['maintenance']`.
 *
 * Recognised keys, all optional:
 *   - retry_after:        seconds sent as the Retry-After header (600)
 *   - bypass:             null, or callable(ServerRequestInterface): bool
 *   - body_template:      inline sprintf template with a single %s
 *   - body_template_path: template file; .phtml/.php is rendered, any other
 *                         extension is read raw (bundled template by default)
 *   - file:               state file written by the admin
 *                         (getcwd() . '/config/autoload/maintenance.local.php')
 *
 * @api
 */
final class MaintenanceMiddlewareFactory
{
    public const string DEFAULT_STATE_FILE = '/config/autoload/maintenance.local.php';

    /**
     * @return array<array-key, mixed>
     */
    private function maintenanceConfig(mixed $config): array
    {
        if (! is_array($config) || ! is_array($config['maintenance'] ?? null)) {
            return [];
        }

        return $config['maintenance'];
    }

    /**
     * The callable is wrapped so that only a strict `true` lets a request
     * through, whatever the site's callable is declared to return.
     *
     * @return (Closure(ServerRequestInterface): bool)|null
     *
     * @throws RuntimeException When the value is neither null nor callable.
     */
    private function resolveBypass(mixed $bypass): ?Closure
    {
        if (null === $bypass) {
            return null;
        }

        if (! is_callable($bypass)) {
            throw new RuntimeException(
                'contenir/contenir-maintenance-mezzio: config[maintenance][bypass] must be callable or null.',
            );
        }

        return static fn(ServerRequestInterface $request): bool => true === $bypass($request);
    }

    /**
     * A repository registered in the container wins. Otherwise the state file
     * is read through a FileRepository on every request: Mezzio caches the
     * merged config in production, so `$config['maintenance']['state']` would
     * keep serving the state from the moment the cache was built and ignore
     * admin toggles until it was cleared.
     *
     * @throws RuntimeException When the file option is not a non-empty string.
     * @throws ContainerExceptionInterface When the registered repository cannot be built.
     */
    private function resolveRepository(ContainerInterface $container, mixed $file): MaintenanceRepositoryInterface
    {
        if ($container->has(MaintenanceRepositoryInterface::class)) {
            return $container->get(MaintenanceRepositoryInterface::class);
        }

        if (null === $file) {
            $cwd = getcwd();

            return new FileRepository((false === $cwd ? '.' : $cwd) . self::DEFAULT_STATE_FILE);
        }

        if (! is_string($file) || '' === $file) {
            throw new RuntimeException(
                'contenir/contenir-maintenance-mezzio: config[maintenance][file] must be a non-empty string.',
            );
        }

        return new FileRepository($file);
    }

    /**
     * @param array<array-key, mixed> $maintenance
     *
     * @throws RuntimeException When the value is not numeric.
     */
    private function resolveRetryAfter(array $maintenance): int
    {
        if (! array_key_exists('retry_after', $maintenance)) {
            return MaintenanceMiddleware::DEFAULT_RETRY_AFTER;
        }

        if (! is_numeric($maintenance['retry_after'])) {
            throw new RuntimeException(
                'contenir/contenir-maintenance-mezzio: config[maintenance][retry_after] must be a number of seconds.',
            );
        }

        return (int) $maintenance['retry_after'];
    }

    /**
     * @throws RuntimeException When the maintenance config holds an invalid value.
     * @throws ContainerExceptionInterface When a service the factory reads cannot be built.
     */
    public function __invoke(ContainerInterface $container): MaintenanceMiddleware
    {
        $maintenance = $this->maintenanceConfig($container->has('config') ? $container->get('config') : []);

        return new MaintenanceMiddleware(
            repository: $this->resolveRepository($container, $maintenance['file'] ?? null),
            retryAfter: $this->resolveRetryAfter($maintenance),
            bodyTemplate: (new BodyTemplateLoader())->resolve($maintenance),
            bypass: $this->resolveBypass($maintenance['bypass'] ?? null),
        );
    }
}
