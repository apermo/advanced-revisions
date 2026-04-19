# Testing

How Advanced Revisions is tested — coverage, fixtures, and the local runbook.

## Philosophy

Three layers, each with a clear remit:

| Layer        | Location                               | What lives here                                         |
|--------------|----------------------------------------|---------------------------------------------------------|
| Unit         | `tests/Unit/`, `advanced-revisions-dev/tests/Unit/` | Pure logic, hook registration, SQL shape. Brain Monkey stubs WP. |
| Integration  | `tests/Integration/`                   | Anything that touches `wp_posts`, taxonomies, meta, or WP hooks with real side effects. |
| E2E          | `e2e/`                                 | Admin-screen interactions. Playwright against real WordPress. |

Decision rule:

- If it hits `wp_posts`, it goes in integration.
- If it renders an admin screen a human clicks through, it goes in E2E.
- Everything else goes in unit.

## Running tests locally

### Prerequisites

```bash
ddev start && ddev orchestrate
composer install
```

### Unit tests

```bash
composer test:unit
```

Fast (< 1 second). No database. Uses Brain Monkey + the minimal `WP_Post` /
`WP_Query` / `WP_User` stubs in `tests/wp-class-stubs.php`.

### Integration tests

```bash
composer test:integration
```

Requires `WP_TESTS_DIR` to be set or `vendor/wp-phpunit/wp-phpunit` installed.
The bootstrap auto-detects. See `CLAUDE.md` for the full setup.

### E2E tests

Activate the dev fixtures plugin and seed a scenario before running:

```bash
ddev wp plugin activate advanced-revisions-dev
ddev wp ar-fixtures content --count=50 --seed=42
ddev wp ar-fixtures revisions --distribution=normal --seed=42
npm run test:e2e
```

## Coverage matrix

One row per v0.1.0 feature. `✓` = covered, `—` = intentionally not covered at
this layer.

| Issue | Feature                              | Unit | Integration | E2E | Fixture scenario  |
|-------|--------------------------------------|:----:|:-----------:|:---:|-------------------|
| #1    | Per-type revision limit              |  ✓   |             |     | `mixed-types`     |
| #2    | Admin overview                       |  ✓   |             |     | `heavy-site`      |
| #3    | Bulk delete from overview            |  ✓   |             |     | `heavy-site`      |
| #4    | Post list column                     |  ✓   |             |     | `normal-site`     |
| #10   | Dashboard widget                     |  ✓   |             |     | `normal-site`     |
| #11   | Per-post override                    |  ✓   |             |     | `mixed-types`     |
| #13   | Tagging + protection                 |  ✓   |             |     | `tagged-site`     |
| #14   | Dev fixtures CPTs                    |  ✓   |             |     | n/a               |
| #15   | Content seeder (WP-CLI)              |  ✓   |             |     | n/a               |
| #16   | Revision seeder (WP-CLI)             |  ✓   |             |     | n/a               |

Integration and E2E rows are intentionally empty for v0.1.0 — the test
infrastructure lands with v0.1.0; coverage fills in as the WP-PHPUnit
runner and Playwright suites are wired up on CI. Later milestones should
update this matrix as they land.

## Fixture scenarios

Named recipes for the `ar-fixtures` WP-CLI commands (from the dev plugin).
Every scenario uses `--seed=42` by default for reproducibility.

| Scenario         | Content command                                                                  | Revisions command                                                                   |
|------------------|----------------------------------------------------------------------------------|-------------------------------------------------------------------------------------|
| `tiny-site`      | `ar-fixtures content --count=10`                                                 | `ar-fixtures revisions --distribution=sparse`                                       |
| `normal-site`    | `ar-fixtures content --count=50`                                                 | `ar-fixtures revisions --distribution=normal`                                       |
| `heavy-site`     | `ar-fixtures content --count=500`                                                | `ar-fixtures revisions --distribution=heavy`                                        |
| `mixed-types`    | `ar-fixtures content --count=20 --post-types=ar_test_article,ar_test_product,ar_test_note` | `ar-fixtures revisions --distribution=normal`                              |
| `orphan-heavy`   | `ar-fixtures content --count=50`                                                 | `ar-fixtures revisions --distribution=normal --orphan-count=200`                    |
| `autosave-flood` | `ar-fixtures content --count=50`                                                 | `ar-fixtures revisions --distribution=normal --autosave-ratio=0.5`                  |
| `age-spread`     | `ar-fixtures content --count=50 --date-spread=2190`                              | `ar-fixtures revisions --distribution=normal --spread-days=2190`                    |
| `multi-author`   | `ar-fixtures content --count=50 --authors=10`                                    | `ar-fixtures revisions --distribution=normal`                                       |
| `product-meta`   | `ar-fixtures content --count=30 --post-types=ar_test_product`                    | `ar-fixtures revisions --distribution=normal`                                       |
| `tagged-site`    | `ar-fixtures content --count=30`                                                 | `ar-fixtures revisions --distribution=normal` + manual `wp_set_object_terms` calls  |
| `extreme-perf`   | `ar-fixtures content --count=5000`                                               | `ar-fixtures revisions --distribution=extreme`                                      |

Reset between scenarios:

```bash
ddev wp ar-fixtures reset-all
```

## E2E playbook

For each Playwright spec:

1. `global-setup.js` drops an MU-plugin stub into
   `.ddev/wordpress/wp-content/mu-plugins/` that forces the dev plugin active.
2. Per-suite `beforeAll`: `wp ar-fixtures reset-all` followed by the scenario
   the spec needs.
3. Auth handled by `e2e/auth.setup.js` (cached in `.auth/admin.json`).
4. No per-test teardown — fixtures are per-run.

## CI reference

| Workflow             | Trigger          | Tests                                        |
|----------------------|------------------|----------------------------------------------|
| `ci.yml`             | PR, push to main | PHPCS, PHPStan, unit tests (PHP 8.2–8.4). Uploads coverage to Codecov. |
| `integration.yml`    | PR, push to main | Integration tests, multisite matrix.         |
| `e2e.yml`            | PR, push to main | Playwright + seeded scenarios.               |
| `wp-beta.yml`        | Nightly          | Integration against WP beta / RC.            |
| `pr-validation.yml`  | PR               | Conventional-commit + CHANGELOG entry checks.|

## Gaps

Tracked for follow-up, not scoped into v0.1.0:

- Visual regression / screenshot diffing on the overview table
- Load testing beyond 125k revisions
- Accessibility testing via `@axe-core/playwright`
- Integration-test wiring for the per-post override meta box and the
  overview SQL queries

## How to use this plan

- Every feature PR updates the coverage matrix row for its issue.
- A feature shipping without coverage at a given layer should mark `—`
  explicitly, with a follow-up issue filed.
- Never remove a row — closed issues still document what's covered.
