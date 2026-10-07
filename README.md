# contenir/contenir-maintenance-mezzio

Formerly `contenir/maintenance-mezzio`; the old package is abandoned in favour of this one.

[![Continuous Integration](https://github.com/contenir/contenir-maintenance-mezzio/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-maintenance-mezzio/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-maintenance-mezzio/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-maintenance-mezzio)

Mezzio adapter for [`contenir/contenir-maintenance`](https://github.com/contenir/contenir-maintenance).

When the admin (Contenir CMS) toggles maintenance mode, this adapter's
PSR-15 middleware answers every request in the consuming Site with a 503
response, until the flag is cleared.

## Requirements

- PHP 8.3, 8.4 or 8.5
- `contenir/contenir-maintenance` 2.1+, `contenir/contenir-config` 2.1+
- `laminas/laminas-diactoros` 3.x and the PSR-7, PSR-11 and PSR-15 interfaces

The 0.x releases remain available from the `0.x` branch and `v0.*` tags; see
[UPGRADE-2.0.md](UPGRADE-2.0.md).

## Install

```bash
composer require contenir/contenir-maintenance-mezzio
```

## Wire-up

### 1. Register the ConfigProvider

```php
// config/config.php
$aggregator = new ConfigAggregator([
    // ...other providers
    \Contenir\Maintenance\Mezzio\ConfigProvider::class,
]);
```

If you have `laminas/laminas-component-installer` installed, this happens
automatically.

### 2. Pipe the middleware early

```php
// config/pipeline.php
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;

return static function (Application $app): void {
    $app->pipe(ErrorHandler::class);
    $app->pipe(ServerUrlMiddleware::class);

    // Before any page cache, so cached pages are not served during maintenance.
    $app->pipe(MaintenanceMiddleware::class);

    // ...page cache, routing, dispatch
};
```

Pipe it straight after `ErrorHandler` and `ServerUrlMiddleware`, and
before anything that can answer a request on its own, in particular any
page-cache middleware. A cache piped ahead of it would keep serving stored
pages while the site is meant to be down. The 503 carries
`Cache-Control: no-store`, so caches further down the line will not store
it either.

### 3. Point at the shared state file

```php
// config/autoload/maintenance.global.php
return [
    'maintenance' => [
        // Same path the admin (Contenir CMS) is configured to write.
        'file' => '/var/www/shared/maintenance.local.php',
    ],
];
```

`file` defaults to `config/autoload/maintenance.local.php` under the
working directory, which is the file the admin writes for a Site in the
standard layout. With nothing else set, the middleware returns the bundled
503 page with the admin's message whenever maintenance is active.

#### Why the file is read on every request

The middleware asks the repository for the state on every request rather
than reading `$config['maintenance']['state']`. Mezzio caches the merged
config in production (`config/autoload/*.local.php` included), so state
read from config would be frozen at the moment the cache was built, and
the admin's toggles would be ignored until someone cleared it. Reading the
small PHP state file costs one `include`, which opcache serves from memory.
If the Site runs with `opcache.validate_timestamps=0` in a different PHP
pool from the admin, the admin's write cannot invalidate the Site's cached
copy; leave timestamp validation on for that file's directory, or reset
opcache on toggle.

To read the state from somewhere else, register a service for
`Contenir\Maintenance\MaintenanceRepositoryInterface`; when one exists it
is used instead of the file, and `file` is ignored.

### 4. (Optional) Bypass for operators

```php
// config/autoload/maintenance.global.php
return [
    'maintenance' => [
        'bypass' => [\App\Maintenance\Bypass::class, 'allows'],
    ],
];
```

```php
namespace App\Maintenance;

use Mezzio\Authentication\UserInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Bypass
{
    /**
     * Return true to let the request through despite maintenance mode.
     */
    public static function allows(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('X-Maintenance-Bypass') === getenv('MAINTENANCE_BYPASS_TOKEN')) {
            return true;
        }

        if (in_array($request->getServerParams()['REMOTE_ADDR'] ?? '', ['203.0.113.10'], true)) {
            return true;
        }

        $user = $request->getAttribute(UserInterface::class);

        return $user instanceof UserInterface && in_array('admin', $user->getRoles(), true);
    }
}
```

`bypass` is any callable that takes the `ServerRequestInterface` and
returns `bool`; only a strict `true` lets the request through. Prefer a
static method callable, as above, over a closure: Mezzio cannot write a
closure into its config cache. The bypass sees the request as it is when
the middleware runs, so request attributes such as an authenticated user
are only there if the middleware that sets them is piped ahead of this one.

### 5. (Optional) Customise the response body

```php
'maintenance' => [
    'body_template_path' => __DIR__ . '/../../templates/maintenance.phtml',
    'retry_after'        => 1800, // seconds, sent as Retry-After header
],
```

`body_template_path` may name any file: `.phtml` and `.php` files are
evaluated as PHP once, when the middleware is built, and anything else is
read as-is. The bundled `templates/maintenance.phtml` is a good starting
point. For a short body, set `body_template` to an inline string instead;
it wins over `body_template_path`.

Either way the result is a `sprintf` format string. It receives two
arguments, and any other `%` must be written as `%%`:

| Placeholder    | Value                                                                                                                                                 |
|----------------|-------------------------------------------------------------------------------------------------------------------------------------------------------|
| `%s` or `%1$s` | The HTML-escaped message                                                                                                                              |
| `%2$s`         | `since`, when maintenance started, as ISO 8601 (`2026-05-05T03:14:15+00:00`); an empty string when the state has no `since` or it could not be parsed |

A template that only uses `%s` is unaffected by `since`. To show it:

```php
'body_template' => '<h1>Down for maintenance</h1><p>%1$s</p>'
    . '<p>Down since <time datetime="%2$s">%2$s</time></p>',
```

If you need anything more elaborate (full layout, template renderer,
translation), replace the `MaintenanceMiddleware` service with your own
factory.

## Options

| Key                  | Default                                               | Meaning                                               |
|----------------------|-------------------------------------------------------|-------------------------------------------------------|
| `file`               | `getcwd() . '/config/autoload/maintenance.local.php'` | State file written by the admin                       |
| `retry_after`        | `600`                                                 | Seconds sent as the `Retry-After` header              |
| `bypass`             | `null`                                                | `callable(ServerRequestInterface): bool`              |
| `body_template`      | unset                                                 | Inline `sprintf` body; wins over the path             |
| `body_template_path` | bundled `templates/maintenance.phtml`                 | Body template file; `null` uses a minimal inline body |

## Public API

| Class | Purpose |
|-------|---------|
| `ConfigProvider` | `__invoke()` and `getDependencies()` register the middleware factory. It adds no `maintenance` key, so the site's config is the only source of options. `defaultBodyTemplatePath()` returns the bundled template's absolute path. |
| `Factory\MaintenanceMiddlewareFactory` | Builds the middleware from `config['maintenance']` (options above). `DEFAULT_STATE_FILE` is the state file path relative to the working directory. Invalid options throw `RuntimeException` when the container builds the middleware. |
| `Middleware\MaintenanceMiddleware` | The PSR-15 middleware. `DEFAULT_RETRY_AFTER` (600) and `DEFAULT_BODY_TEMPLATE` (a minimal inline page) are its defaults. |

`Factory\BodyTemplateLoader` is internal.

The middleware can also be built by hand, for example in a site's own factory:

```php
use Contenir\Maintenance\Mezzio\Middleware\MaintenanceMiddleware;
use Contenir\Maintenance\Repository\FileRepository;
use Psr\Http\Message\ServerRequestInterface;

$middleware = new MaintenanceMiddleware(
    repository: new FileRepository('/var/www/shared/maintenance.local.php'),
    retryAfter: 1800,
    bodyTemplate: '<!doctype html><title>Back soon</title><p>%s</p>',
    bypass: static fn (ServerRequestInterface $request): bool => $request->hasHeader('X-Ops'),
);
```

When the repository reports maintenance as active and the bypass does not
return `true`, `process()` returns a `503` `text/html; charset=utf-8` response
with `Retry-After` and `Cache-Control: no-store`. The admin's message is
HTML-escaped and put in place of the template's `%s`, and `since` in place
of `%2$s`. Otherwise the request
goes to the next handler.

## Development

The QA toolchain is [contenir/contenir-qa-tools](https://github.com/contenir/contenir-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: middleware and factory with in-memory doubles, no I/O
composer test-integration  # integration suite: real template and state files in a temp directory
composer test-coverage     # both suites, clover.xml for Codecov
composer mutation-test     # Infection mutation testing over both suites
```

## License

MIT. See [LICENSE](LICENSE).
