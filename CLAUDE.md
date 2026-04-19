# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress plugin adding advanced features for post revisions: configurable limits per post type,
admin overview, and bulk deletion tools.

**PHP 8.1+ minimum.** Strict types everywhere (`declare(strict_types=1)`).

Derived from [`apermo/template-wordpress`](https://github.com/apermo/template-wordpress).

## Architecture

- Main plugin file: `plugin.php`
- PSR-4 root: `src/` → `Apermo\AdvancedRevisions\`
- Entry class: `src/Plugin.php` (lifecycle: `activate()`, `deactivate()`, `boot()`)
- Uninstall hook: `uninstall.php`

### Sibling dev plugin

`advanced-revisions-dev/` is a **separate WordPress plugin** that ships test CPTs
and seed WP-CLI commands. It's excluded from the production ZIP via
`.gitattributes` `export-ignore`, uses its own inline autoloader (no dependency
on the main plugin's Composer autoload), and lives under namespace
`Apermo\AdvancedRevisionsDev\`. Pattern: [inpsyde/WP-Stash](https://github.com/inpsyde/WP-Stash/tree/main/wp-stash-test-plugin).
Never install it in production.

### Key conventions

- Coding standards: `apermo/apermo-coding-standards` (PHPCS)
- Static analysis: `apermo/phpstan-wordpress-rules` + `szepeviktor/phpstan-wordpress`
- Testing: PHPUnit + Brain Monkey + Yoast PHPUnit Polyfills
- Test suites: `tests/Unit/` and `tests/Integration/`
- E2E: Playwright under `e2e/`

## Commands

```bash
composer cs              # Run PHPCS
composer cs:fix          # Fix PHPCS violations
composer analyse         # Run PHPStan
composer test            # Run all tests
composer test:unit       # Run unit tests only
composer test:integration # Run integration tests only
npm run test:e2e         # Run Playwright E2E tests
npm run test:e2e:ui      # Run E2E tests with UI
```

## Local Development (DDEV)

```bash
ddev start && ddev orchestrate   # Full WordPress environment
```

- Uses `apermo/ddev-orchestrate` addon
- Project type is `php` (not `wordpress`), so WP-CLI uses a custom `ddev wp` command wrapper
- WordPress installs into `.ddev/wordpress/` subdirectory (keeps project root clean)
- `ddev-orchestrate` symlinks the project into the WP plugins directory automatically

## Git Hooks

Pre-commit hook runs PHPCS and PHPStan on staged files. Enabled by default (via `setup.sh`).
Re-enable with:

```bash
git config core.hooksPath .githooks
```

## CI (GitHub Actions)

- `ci.yml` — PHPCS + PHPStan + PHPUnit across PHP 8.1, 8.2, 8.3, 8.4
- `integration.yml` — WP integration tests (real WP + MySQL, multisite matrix)
- `e2e.yml` — Playwright E2E tests against running WordPress
- `wp-beta.yml` — Nightly WP beta/RC compatibility check
- `release.yml` — CHANGELOG-driven releases
- `pr-validation.yml` — conventional commit and changelog checks
- `wporg-deploy.yml` — WordPress.org SVN deploy (requires `WPORG_SVN_USERNAME` / `WPORG_SVN_PASSWORD` secrets)

### Integration test environment

Integration tests run against a real WordPress instance. The bootstrap auto-detects
`vendor/wp-phpunit/wp-phpunit` when `WP_TESTS_DIR` is unset. For local development:

```bash
composer require --dev wp-phpunit/wp-phpunit
cp wp-tests-config.php.dist wp-tests-config.php  # edit DB credentials
composer test:integration
```

You can also set `WP_TESTS_DIR` explicitly:

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_MULTISITE=1 composer test:integration
```

When neither `WP_TESTS_DIR` nor `vendor/wp-phpunit/wp-phpunit` exist, the bootstrap
skips WP loading — unit tests work unchanged.

### E2E test environment

E2E tests use Playwright against a running WordPress instance (DDEV locally, PHP built-in server in CI):

```bash
npm ci
npx playwright install --with-deps chromium
npm run test:e2e
```

The `WP_BASE_URL` env var overrides the default DDEV site URL. Authentication
is handled by `e2e/auth.setup.js` which stores state in `.auth/admin.json`.

## Template Sync

To pull upstream changes from the template:

```bash
git remote add template https://github.com/apermo/template-wordpress.git
git fetch template
git checkout -b chore/sync-template
git merge template/main --allow-unrelated-histories
```
