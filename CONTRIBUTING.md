# Contributing

## Code quality tooling

This repo ships configuration for [`craftcms/ecs`](https://github.com/craftcms/ecs),
[`phpstan/phpstan`](https://github.com/phpstan/phpstan) (^2.2) and
[`craftcms/rector`](https://github.com/craftcms/rector) (`dev-craft6`, Rector 2).

```sh
composer install
composer check
```

`composer check` runs ECS, PHPStan (level 8), Rector dry-run, and unit tests.
Individual scripts:

| Script | Purpose |
|---|---|
| `composer check-cs` / `composer fix-cs` | Easy Coding Standard |
| `composer phpstan` | Static analysis |
| `composer rector` / `composer rector:fix` | Rector dry-run / apply |
| `composer test:unit` | Unit tests (no Craft boot) |
| `composer test:integration` | Integration tests (needs Craft + DB) |

All of ECS, PHPStan and Rector must pass clean before a release. Pull requests
should keep `composer check` green.

## Tests

```sh
composer test:unit
composer test:integration
```

Unit tests run without booting Craft. Integration tests boot a real Craft
application and exercise the plugin against real records, so they need a
configured test database.

**CI now runs integration tests too**, in the `integration` job in
`.github/workflows/ci.yml`. It pulls a pre-built, pre-migrated Craft +
Commerce image from `ghcr.io/kernpfad/craft-test-base` (built and
maintained in [`kernpfad/craft-test-images`](https://github.com/kernpfad/craft-test-images)),
links this plugin into it via a Composer path repository, and runs
`composer test:integration` against it — no plugin-specific setup lives in
that shared image; this plugin's own tests create whatever fixtures they
need (see `tests/integration/SourceFilteringTest.php`), except for the
second site multi-site testing requires, which the CI job provisions itself
(the shared image is deliberately single-site, and creating a persistent
second site — plus the edition change multi-site requires on a fresh
install — is treated as environment setup, not something a test should do
on its own; see the doc comment atop `SourceFilteringTest.php`). The pulled
image is pinned by digest
(`ghcr.io/kernpfad/craft-test-base:craft5-commerce5-php8.3@sha256:...` in
`ci.yml`'s `integration` job), never `:latest` and never the moving tag
alone — see `kernpfad/craft-test-images`' README for why. Bumping the
digest to a newer build is a normal, reviewable PR that Renovate proposes
automatically (see `renovate.json`), not something that happens silently.

Locally, `CRAFT_TEST_SITE_PATH` is still how you point `test:integration`
at a working Craft install (see below), and it's still what
`.githooks/pre-push` checks for before running integration tests on push —
the shared CI image doesn't replace either, it just means you no longer
need a local multi-site install to get integration coverage on a PR.

## Local development

Install the plugin into a Craft 5 site through a Composer path repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../craft-site-volumes" }
    ]
}
```

```sh
composer require kernpfad/craft-site-volumes:@dev
php craft plugin/install site-volumes
```

Local checks run automatically via a git hook that `composer install`
wires up for you (`git config core.hooksPath .githooks`):

- `.githooks/pre-commit` runs `composer check` on every commit that touches
  PHP/tooling files.
- `.githooks/pre-push` runs `composer test:integration` if
  `CRAFT_TEST_SITE_PATH` is set, against the shared local Craft + Commerce
  site described in `craft-plugin-blueprint`'s `BLUEPRINT.md`:

  ```sh
  export CRAFT_TEST_SITE_PATH=~/projects/kernpfad/craft-test-site
  ```

## Branches

Trunk-based: `main` is always release-ready. No long-lived `develop` branch.

| Prefix | Use for |
|---|---|
| `feature/…` | New behaviour |
| `fix/…` | Bug fixes |
| `chore/…` | Tooling, docs, cleanup |
| `release/x.y.z` | Parallel release work only when needed |

Name form: `prefix/kurz-kebab-case` (e.g. `feature/list-fields`, `fix/bulk-catalog-timeout`).
Prefer `feature/` over `feat/`. Temporary Cloud Agent branches may use `cursor/…`; rename before opening the PR when practical.

Open a PR into `main`. Squash-merge is the only enabled merge method; GitHub deletes the head branch after merge.

## Pull requests

Use the PR template. Update `CHANGELOG.md` when behaviour changes. Keep `composer check` green; CI runs Quality on PHP 8.2–8.4.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md).
