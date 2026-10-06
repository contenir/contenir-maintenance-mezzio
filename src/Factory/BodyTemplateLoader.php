<?php

declare(strict_types=1);

namespace Contenir\Maintenance\Mezzio\Factory;

use Contenir\Maintenance\Mezzio\ConfigProvider;
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use RuntimeException;

use function array_key_exists;
use function file_get_contents;
use function get_debug_type;
use function is_file;
use function is_readable;
use function is_string;
use function ob_end_clean;
use function ob_get_contents;
use function ob_start;
use function pathinfo;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * Resolves the sprintf body template handed to the MaintenanceMiddleware.
 *
 * Precedence:
 *   1. An explicit `body_template` in the site config wins.
 *   2. Otherwise `body_template_path` is loaded, whether site-set or the
 *      bundled default.
 *   3. If the site set `body_template_path` to null or an empty string, the
 *      inline default body template is used.
 *
 * Either way the result is a sprintf template: exactly one %s for the admin
 * message and no other unescaped percent signs.
 *
 * @internal
 */
final class BodyTemplateLoader
{
    private static function render(string $path): string
    {
        return (static function (string $template): string {
            ob_start();
            try {
                include $template;

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        })($path);
    }

    private static function unreadable(string $path): RuntimeException
    {
        return new RuntimeException(sprintf(
            'contenir/contenir-maintenance-mezzio: body_template_path "%s" is not a readable file.',
            $path,
        ));
    }

    /**
     * @param array<array-key, mixed> $maintenance The site's `config['maintenance']`.
     *
     * @throws RuntimeException When body_template is not a string or the path cannot be read.
     */
    public function resolve(array $maintenance): string
    {
        if (array_key_exists('body_template', $maintenance)) {
            return $this->requireString($maintenance['body_template']);
        }

        if (! array_key_exists('body_template_path', $maintenance)) {
            return $this->load(ConfigProvider::defaultBodyTemplatePath());
        }

        return $this->fromPath($maintenance['body_template_path']);
    }

    /**
     * @throws RuntimeException When the path cannot be read.
     */
    private function fromPath(mixed $path): string
    {
        if (is_string($path) && '' !== $path) {
            return $this->load($path);
        }

        return MaintenanceMiddleware::DEFAULT_BODY_TEMPLATE;
    }

    /**
     * For .phtml and .php paths the file is included under output buffering
     * inside an isolated closure, so its PHP runs once when the middleware is
     * built without seeing loader-scope variables. Any other extension is
     * read raw.
     *
     * @throws RuntimeException When the path is not a readable file.
     */
    private function load(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw self::unreadable($path);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ('phtml' === $extension || 'php' === $extension) {
            return self::render($path);
        }

        set_error_handler(static fn(): bool => true);

        try {
            $content = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        return false === $content ? throw self::unreadable($path) : $content;
    }

    /**
     * @throws RuntimeException When the value is not a string.
     */
    private function requireString(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException(sprintf(
                'contenir/contenir-maintenance-mezzio: config[maintenance][body_template] must be a string, got %s.',
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
