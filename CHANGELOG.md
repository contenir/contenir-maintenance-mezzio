# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.0.0] - Unreleased

The public API is unchanged. The major version aligns the package with the
other Contenir 2.x packages: the same supported PHP versions, the shared
php-db QA toolchain and CI. See [UPGRADE-2.0.md](UPGRADE-2.0.md).

### Changed

- Requires PHP 8.3, 8.4 or 8.5 (`~8.3.0 || ~8.4.0 || ~8.5.0`; was `^8.3`).
- `contenir/config` is required at `^0.2 || ^2.0` (was `^0.1`, which excluded
  the current 0.2 release). `contenir/maintenance` accepts `^0.1 || ^2.0`.
- `LICENSE` names Contenir as the copyright holder, in line with the other
  Contenir packages.
- The local path and VCS repository entries are gone from `composer.json`;
  everything resolves from Packagist.

### Fixed

- A `body_template_path` that passes the readability checks but then fails to
  open no longer emits a PHP warning ahead of the `RuntimeException`. The read
  runs under an error handler scoped to that call.

### Added

- Continuous integration through `php-db/phpdb-qa-tools` on PHP 8.3, 8.4 and
  8.5 against lowest, locked and latest dependencies, with coverage reported
  to Codecov. `composer.lock` is committed.
- 100% line and branch coverage across the unit and integration suites.

### Removed

- The package's own `quality.yml` workflow, replaced by the shared one.

## [0.1.0] - 2026-10-05

- Initial release: `MaintenanceMiddleware`, its factory and `ConfigProvider`.
  Answers every request with a 503 while the admin's maintenance state is
  active, reading the state file on every request, with an optional bypass
  callable, `Retry-After` and a bundled or site-supplied body template.
