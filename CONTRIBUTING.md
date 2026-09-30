# Contributing

Thank you for improving the OpenHandle PHP SDK.

## Development

Install PHP 8.2 or newer and Composer, then run:

```bash
composer install
composer generate
composer test
```

Run `composer generate` after changing the pinned OpenAPI document or the
generator. Generated files under `src/Models`, `src/Resources`, and
`src/Internal/Operations.php` must be committed with their source changes.

Quality checks:

```bash
composer lint
composer test
```

`composer lint` verifies that generated files are current, checks formatting
with PHP-CS-Fixer, and runs PHPStan at the maximum level. `composer test` runs
PHPUnit and the agent usability eval.

## Commits

Use Conventional Commits. `feat` changes produce minor releases, `fix` changes
produce patch releases, and a `!` or `BREAKING CHANGE` footer produces a major
release.

API contract changes normally arrive as automated pull requests. Runtime,
typing, documentation, and example improvements are welcome directly.
