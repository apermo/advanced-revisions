# Advanced Revisions

[![PHP CI](https://github.com/apermo/advanced-revisions/actions/workflows/ci.yml/badge.svg)](https://github.com/apermo/advanced-revisions/actions/workflows/ci.yml)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](LICENSE)

Advanced features for WordPress revisions: configurable limits per post type, a dashboard widget,
an admin overview, bulk deletion, and tag-based protection.

## Features

- **Per-post-type revision limit** — configure via **Settings → Revisions**, overrides `WP_POST_REVISIONS`
- **Per-post retention override** — meta box on the post edit screen; -1 unlimited, 0 disables for that post
- **Post list column** — revision count + "Manage revisions" row action linking to the native compare screen
- **Dashboard widget** — site-wide totals, estimated DB footprint, top-five heaviest posts (cached)
- **Tools → Revisions overview** — paginated table of posts with stored revisions, heaviest first
- **Bulk revision deletion** — with per-parent capability filtering and tag-based protection
- **Revision tagging + protection** — `revision_tag` taxonomy with a `protected` flag that every cleanup path honours

### Capability model

- Overview + bulk delete: `delete_others_posts` by default (filterable via `advanced_revisions_bulk_delete_capability`); per-parent `edit_post` enforced inside the handler
- Per-post retention override: `edit_post` (`$post_id`)

### Roadmap

- Scheduled/automated cleanup of old revisions (v0.2)
- Orphan-revision sweep after parent deletion (v0.2)
- Autosave/revision separation (v0.2)
- Post-meta revisioning UI (v0.3)
- WP-CLI commands for listing and cleaning revisions (v0.3)

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
