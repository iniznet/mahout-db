# ADR-0005 — Shared analyzer config, the lockfile, and the translation scope

Status: accepted, except the lockfile clause, which is superseded by `iniznet/mahout-devtools` ADR-0008

The shared-configuration decision stands. The lockfile half does not: the
family's repositories are published, each manifest declares its family
requirements as committed VCS repositories, and each lock resolves over them, so
a fresh clone installs. `PathRepositoryCheck` fails a lock that pins a `path`
dist.

## Context

Three small decisions have nothing to do with the schema and everything to do
with the repository.

1. The analyzer rules live in `mahout-devtools`. A repository that copies them
   has diverged; a repository whose root config does not include the shared one
   silently stops running the rules.
2. `mahout-devtools` is not published on Packagist yet, so a development
   checkout resolves it through an uncommitted `composer.dev.json` that declares
   a `path` repository. A committed `path` repository would make a fresh clone
   unresolvable.
3. `composer i18n:check` requires a generated POT. This package's translatable
   strings are zero.

## Decision

- `phpstan.neon` includes `vendor/iniznet/mahout-devtools/phpstan.neon` and names
  no rule of its own; `psalm.xml`, `rector.php` and `.php-cs-fixer.dist.php` are
  the shared ones. `composer stan` and `composer arch` therefore run the same
  config — the architecture rules are part of it, not a second pass.
- The committed `composer.lock` is resolved through the uncommitted
  `composer.dev.json`. The lock records the pinned dependency graph; the
  `path` repository that produced it is never committed.
- Exception messages are not passed through a translation function. They are
  developer-facing diagnostics: each one names a condition, carries typed context
  and is recorded through `Diagnostics` with a support reference when it
  surfaces to a user. The user-facing text belongs to the layer that renders it,
  and translating a schema error for a developer reading a stack trace serves
  nobody. The generated POT is therefore header-only, and `composer i18n:check`
  passes on it.

## Consequences

- A fork pull request runs the same `composer check`, because `mahout-devtools`
  resolves over VCS with no secret.
- The quality workflow is triggered by `pull_request`, never
  `pull_request_target`.
- The first user-visible string this package ever produces must come with a
  decision record that says why the exception-message rule does not apply to it.
