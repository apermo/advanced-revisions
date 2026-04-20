# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-04-20

### Added

- Initial project scaffolding based on `apermo/template-wordpress`
- PHP 8.1+ codebase with strict types, PSR-4 autoloading under `Apermo\AdvancedRevisions\`
- Coding standards (`apermo/apermo-coding-standards` ^2.9) and static analysis (PHPStan with WordPress rules)
- PHPUnit test suites (unit + WordPress integration) and Playwright E2E scaffold
- DDEV local development environment and GitHub Actions CI (lint, analyse, test, WP beta)
- WordPress.org SVN deploy workflow (disabled until credentials are configured)
- Sibling `advanced-revisions-dev` plugin with 5 test CPTs + `wp ar-fixtures` WP-CLI seeders
- Per-post-type revision limit via Settings → Revisions
- Per-post revision retention override via edit-screen meta box
- Revision count column + "Manage revisions" row action on post list tables
- Dashboard widget showing revision stats (total, estimated DB footprint, top posts)
- Tools → Revisions admin overview + bulk revision deletion with per-parent capability filtering
- `revision_tag` taxonomy + `protected` term meta flag + `ProtectionService`
- `advanced_revisions_bulk_delete_capability` filter for overriding the bulk-delete capability
- Screen-reader labels on the overview's select-all and per-row checkboxes
- `docs/testing.md` with coverage matrix and fixture scenarios

### Changed

- Overview's required capability raised from `edit_others_posts` to `delete_others_posts` to match the destructive action; filterable via `advanced_revisions_bulk_delete_capability`
- `RevisionLimitMetaBox::register_post_meta()` runs on `wp_loaded` instead of `init` so CPTs registered at any init priority are picked up

### Fixed

- PHPStan CI memory limit bumped to 1G (was crashing at 512M)
- `tests/bootstrap.php` now loads WP class stubs only when no real WP test suite is available (prevents fatal class redeclare in integration runs)
- `PostListColumn::compare_url()` links to a valid revision ID (was invalid parent ID); latest revision IDs now batch-load with the count query, eliminating N+1 `wp_get_post_revisions()` calls on list-table renders
- `RevisionRepository::revision_ids_for_parents()` excludes autosaves to match the counts shown in the overview
- `RevisionRepository::total_parents()` joins the parent row and applies the same `post_type`/`post_status` filter as `paginated()` so the paginator denominator matches; orphan revisions and parents in trash/auto-draft no longer inflate the page count
- `RevisionRepository::paginated()` and `DashboardWidget::compute()` include every non-aggregated column in `GROUP BY` for portability to MySQL configurations with `ONLY_FULL_GROUP_BY` that don't honor functional-dependency detection
- `DashboardWidget::compute()` excludes autosaves from all three aggregate queries; widget totals now match the overview page and the list-table column
- `DashboardWidget` split strings rewritten via `_n()` + `wp_kses()` so translators see full sentences with the `<strong>` wrapping as a single translatable unit, and plural forms render correctly
- `RevisionLimitMetaBox` uses the `edit_post` meta capability with `$post_id` instead of the flat `edit_others_posts` primitive (fixes page/CPT authorization)
- `RevisionLimitMetaBox` `auth_callback` signature widened to the 6-arg shape WP's `map_meta_cap()` invokes and now honors an earlier filter's strict-`false` denial
- `OverviewPage::handle_bulk_post()` filters submitted parent IDs through per-parent `current_user_can('edit_post', $id)`, reports unauthorized IDs as `ar_denied`, and terminates with `exit()` after every redirect
- `OverviewPage::handle_bulk_post()` flushes `DashboardWidget` cache after deletion so stats refresh immediately
- `ProtectionService` no longer uses O(n) `in_array`; batches revision→term fetches via `wp_get_object_terms()` with `all_with_object_id` and primes termmeta cache with `update_termmeta_cache()` before the per-term lookup
- `ContentSeeder` / `RevisionSeeder` accept an optional `now` anchor so seeded timestamps are fully deterministic per seed

[0.1.0]: https://github.com/apermo/advanced-revisions/releases/tag/v0.1.0
