# Upgrading from 0.x to 2.0

2.0 has the same public API as 0.1. Only the platform and dependency
constraints change.

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.3 | 8.3, 8.4 or 8.5 |
| `contenir/maintenance` | ^0.1 | ^0.1 or ^2.0 |
| `contenir/config` | ^0.1 | ^0.2 or ^2.0 |

To upgrade, update the constraint:

```bash
composer require contenir/maintenance-mezzio:^2.0
```

No code or configuration changes are needed. `ConfigProvider`,
`Factory\MaintenanceMiddlewareFactory` and `Middleware\MaintenanceMiddleware`
keep their signatures, constants and behaviour, and every
`config['maintenance']` key means what it did in 0.1.

## Behaviour change

A `body_template_path` that stats as a readable file but cannot be opened
still throws the same `RuntimeException`, but no longer emits a PHP warning
first. Error handlers that counted that warning will no longer see it.

## Dependency floors

`contenir/config` 0.1 is no longer accepted. If another package pins it to
`^0.1`, update that package (or its constraint) first; 0.2 is
API-compatible for reading.

Projects that cannot move yet can stay on `^0.1`, which is maintained on the
`0.x` branch.
