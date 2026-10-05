# Upgrading from 0.x to 2.0

2.0 has the same public API as 0.1. Only the platform and dependency
constraints change.

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.3 | 8.3, 8.4 or 8.5 |
| `contenir/maintenance` | ^0.1 | ^0.1.1 or ^2.0 |
| `contenir/config` | ^0.1 | ^0.2 or ^2.0 |

To upgrade, update the constraint:

```bash
composer require contenir/maintenance-mezzio:^2.0
```

No code or configuration changes are needed. `ConfigProvider`,
`Factory\MaintenanceMiddlewareFactory` and `Middleware\MaintenanceMiddleware`
keep their signatures, constants and behaviour, and every
`config['maintenance']` key means what it did in 0.1.

## Final classes

Every concrete class is `final`, as it already was in 0.1. To change
behaviour, use the extension points instead of subclassing:

- `Contenir\Maintenance\MaintenanceRepositoryInterface` (registered in the
  container) for a different source of the state,
- `maintenance.bypass` to let some requests through,
- `maintenance.body_template` / `body_template_path` for the markup, or your
  own factory for the `MaintenanceMiddleware` service.

## Behaviour change

A `body_template_path` that stats as a readable file but cannot be opened
still throws the same `RuntimeException`, but no longer emits a PHP warning
first. Error handlers that counted that warning will no longer see it.

## Dependency floors

`contenir/maintenance` 0.1.0 is no longer accepted: it reads a flat state
file, not the `maintenance.state` shape the admin writes. `contenir/config`
0.1 is no longer accepted either. If another package pins it to
`^0.1`, update that package (or its constraint) first; 0.2 is
API-compatible for reading.

Projects that cannot move yet can stay on `^0.1`, which is maintained on the
`0.x` branch.

## Package renamed in 2.2

From 2.2, the package is published as `contenir/contenir-maintenance-mezzio`. It declares
`replace` for `contenir/maintenance-mezzio`, so the two can never be installed together.
Its dependencies move to their renamed packages too: `contenir/contenir-maintenance`
and `contenir/contenir-config`, both `^2.1`. Switch the requirement:

```bash
composer remove contenir/maintenance-mezzio && composer require contenir/contenir-maintenance-mezzio:^2.2
```

No code changes are needed: namespaces and classes are unchanged.
