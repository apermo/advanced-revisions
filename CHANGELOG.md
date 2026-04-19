# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-04-19

### Added

- Initial project scaffolding based on `apermo/template-wordpress`
- PHP 8.1+ codebase with strict types, PSR-4 autoloading under `Apermo\AdvancedRevisions\`
- Coding standards (`apermo/apermo-coding-standards`) and static analysis (PHPStan with WordPress rules)
- PHPUnit test suites (unit + WordPress integration) and Playwright E2E scaffold
- DDEV local development environment and GitHub Actions CI (lint, analyse, test, WP beta)
- WordPress.org SVN deploy workflow (disabled until credentials are configured)
- Sibling `advanced-revisions-dev` plugin with 5 test CPTs + `wp ar-fixtures` WP-CLI seeders
- Per-post-type revision limit via Settings → Revisions
- Per-post revision retention override via edit-screen meta box
- Revision count column + "Manage revisions" row action on post list tables
- Dashboard widget showing revision stats (total, estimated DB footprint, top posts)
- Tools → Revisions admin overview + bulk revision deletion
- `revision_tag` taxonomy + `protected` term meta flag + `ProtectionService`
- `docs/testing.md` with coverage matrix and fixture scenarios

### Fixed

- PHPStan CI memory limit bumped to 1G (was crashing at 512M)
- `tests/bootstrap.php` now loads WP class stubs only when no real WP test suite is available (prevents fatal class redeclare in integration runs)
- `PostListColumn::compare_url()` links to a valid revision ID (was invalid parent ID)
- `RevisionRepository::revision_ids_for_parents()` now excludes autosaves to match the counts shown in the overview
- `RevisionLimitMetaBox` uses the `edit_post` meta capability instead of the flat `edit_others_posts` primitive (fixes page/CPT authorization)
- `OverviewPage::handle_bulk_post()` flushes `DashboardWidget` cache after deletion so stats refresh immediately
- `ProtectionService` no longer uses O(n) `in_array`; batches term-meta priming and uses O(1) set lookups
- `ContentSeeder` / `RevisionSeeder` accept an optional `now` anchor so seeded timestamps are fully deterministic per seed

[0.1.0]: https://github.com/apermo/advanced-revisions/releases/tag/v0.1.0
