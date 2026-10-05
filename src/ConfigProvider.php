<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio;

/**
 * Registers the maintenance middleware with the Mezzio container.
 *
 * Intentionally contributes no `maintenance` key: defaults live in the
 * factory so that after the ConfigAggregator merges providers with the site's
 * autoload files, $config['maintenance'] holds only the site's explicit
 * values. That is how the factory tells "the site set body_template" apart
 * from "a package default leaked through the merge"; without it,
 * body_template would always appear set and body_template_path overrides
 * would be ignored.
 *
 * @api
 */
final class ConfigProvider
{
    /**
     * Absolute path to the package's bundled default 503 body template.
     *
     * Rendered by the factory via include + output buffering when the
     * middleware is built, so PHP inside the file is evaluated once per
     * container. Sites override it with `maintenance.body_template_path`
     * (any extension) or `maintenance.body_template` (inline string).
     */
    public static function defaultBodyTemplatePath(): string
    {
        return __DIR__ . '/../templates/maintenance.phtml';
    }

    /**
     * @return array{factories: array<class-string, class-string>}
     */
    public function getDependencies(): array
    {
        return [
            'factories' => [
                Middleware\MaintenanceMiddleware::class => Factory\MaintenanceMiddlewareFactory::class,
            ],
        ];
    }

    /**
     * @return array{dependencies: array{factories: array<class-string, class-string>}}
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }
}
