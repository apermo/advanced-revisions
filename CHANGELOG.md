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

[0.1.0]: https://github.com/apermo/advanced-revisions/releases/tag/v0.1.0
