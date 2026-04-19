# Advanced Revisions

[![PHP CI](https://github.com/apermo/advanced-revisions/actions/workflows/ci.yml/badge.svg)](https://github.com/apermo/advanced-revisions/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](LICENSE)

Advanced features for WordPress revisions: configurable limits per post type, an admin overview,
and bulk deletion tools.

> **Status:** v0.1.0 ships project scaffolding only. User-facing features are planned and will be
> tracked as GitHub issues.

## Planned Features

- Configure the number of revisions kept **per post type** (override `WP_POST_REVISIONS`)
- Admin overview listing revisions across the site
- Bulk deletion of revisions (by post type, age, or count)
- Clean, easy-to-use settings UI

## Requirements

- WordPress 6.4+
- PHP 8.1+

## Installation

Until the first tagged release is published, install from source:

```bash
git clone https://github.com/apermo/advanced-revisions.git wp-content/plugins/advanced-revisions
cd wp-content/plugins/advanced-revisions
composer install --no-dev
```

Then activate **Advanced Revisions** from the WordPress Plugins screen.

## Development

```bash
composer install
composer cs               # Run PHPCS
composer cs:fix           # Fix PHPCS violations
composer analyse          # Run PHPStan
composer test             # Run all tests
composer test:unit        # Run unit tests only
composer test:integration # Run integration tests only
npm run test:e2e          # Run Playwright E2E tests
```

### Local WordPress Environment

```bash
ddev start && ddev orchestrate
```

Uses [ddev-orchestrate](https://github.com/apermo/ddev-orchestrate) to download WordPress, create
`wp-config.php`, install, and activate the plugin.

### Git Hooks

Enable the pre-commit hook (PHPCS + PHPStan on staged files):

```bash
git config core.hooksPath .githooks
```

## Contributing

Issues and pull requests welcome. Feature planning happens in GitHub issues — please check there
before starting work.

## License

[GPL-2.0-or-later](LICENSE)
