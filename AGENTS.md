# AGENTS.md — the discipline contract

This file is the working contract for anyone — human or agent — writing code in
this repository. It is the shared mahout contract plus a short package section at
the end recording whether this package violates it anywhere and why.

**Stack:** PHP 8.4+ · WordPress 7.1+ · PHPUnit · PHPStan max · Psalm (taint) ·
Rector · PHP-CS-Fixer

---

## The six laws

1. **Explicit over implicit.** Every dependency, hook, registration and side
effect is greppable from the composition root.
2. **One way to do a thing.** No alternative paths kept "just in case".
3. **Fail fast and loud.** No silent fallback, no degraded mode, no
environment-dependent behaviour switches.
4. **Small public surface.** `Contracts` plus a documented handful of concrete
classes. Everything else is `@internal`.
5. **Tooling enforces what prose promises.** A gate existing only in a markdown
file does not exist.
6. **Layer neutrality.** The system is correct and complete with no object
cache, no page cache and no CDN, and gets faster as each is added — with no
configuration change and no code change. It never depends on a layer existing,
never caps a site because a layer appeared, and has no scale mode.

---

## What this is not

The system is a **modular monolith**. One process, one database, one deployable
artefact. Module boundaries are enforced by `Contracts`, `@internal` and the
architecture rules — never by a network.

Runtime distribution — services, an internal RPC layer, a message broker beyond
`wp_cron`, separately deployed module processes — is a non-goal. The package set
is a publishing and contribution model, not a deployment topology.

---

## Before you write code

1. **Where does this go?** Use the layers below. Do not improvise a directory.
2. **Will it be queried?** That decides `StorageTarget` at field declaration
   time (the themes and the fields package only).
3. **Which hook emits this?** Hooks come from Providers and Modules only.
4. **Which editor writes this?** A `Table` field is written only through the
   field panel or the field REST route. A `Meta` field may also be written
   through `register_post_meta`.
5. **Who may see it?** That decides the Surface's `Cacheability`.

---

## Layers — where code goes

| Layer | Location | Owns | Must not |
|---|---|---|---|
| Domain | `app/Features/<Name>/`, `src/` domain classes | queries, repositories, schema, hooks, business rules | render HTML |
| Presentation | `app/Components/`, `app/Features/*/Components/` | rendering typed props to HTML | fetch data, touch globals, fire hooks |
| Composition | `app/Render/` | resolving a request to a Surface | contain domain rules |
| Infrastructure | `app/Providers/` | hook attachment, assets, REST, admin | contain domain rules |

```
Providers --> Modules --> Repositories --> Mapper --> Data (DTO)
                              |
                              v
Surfaces --> Components --> Data (DTO)
```

Arrows point one way only. A Component never imports a Repository. A Repository
never imports a Component. In this package the same direction holds in
miniature: `DbProvider` builds the stores and the runner, the runner talks to
`Contracts` only, and `DdlEmitter` talks to nothing at all.

---

## Banned — these fail the build

| Banned | Why |
|---|---|
| `extract()` | Variables appear from nowhere |
| `meta_query` in a public API | No `meta_value` index; one join per clause |
| `posts_per_page => -1` | Unbounded cost |
| Raw hook-name strings | Bypasses the `Hooks` constants |
| `get_post_meta()` / `get_user_meta()` on a registered field | Fields are read through the field layer only |
| Components calling repositories | Breaks the layer contract |
| Components referencing `WP_*` types | Components must be testable without WordPress |
| `template_include` routing | WordPress owns resolution |
| Reflection, service locators, facades | Untraceable dependencies |
| Static access to a service — repository, field reader, field query builder, registry, container | A value constructor is not a service locator; inject the collaborator |
| `__get`, `__set`, `__call`, dynamic properties | Invisible to static analysis |
| Trait properties, or `$this->` from a trait calling undeclared members | Concealed dependencies |
| `new \Exception(...)` or a public exception constructor | Untraceable, message drift |
| `error_log()` outside `Diagnostics` | Production noise |
| Superglobals outside the request boundary | No request boundary |
| Inline `<script>` or `<style>` echo | CSP hygiene and cacheability |
| PHPStan baselines, `@phpstan-ignore` without a reason | Hides problems instead of fixing them |
| `mixed` where a union is expressible | Static analysis stops working |
| `START TRANSACTION`, `COMMIT` or `ROLLBACK` outside the db gateway | One owner for the transaction boundary |
| `wp_cache_flush()`, and any `wp_cache_flush_group()` outside the gated cache service | Core returns `false` when the backend reports no support |
| `setcookie()` or `setrawcookie()` | The theme sets no cookie |
| `WP_List_Table` subclasses, quick-edit or bulk-edit field writes | Neither carries the save lifecycle or a post lock |
| A canonical, `robots` or `description` meta tag, and any hand-set security header | Another party owns those |
| A dispatch arm with no `Cacheability` declaration | The argument has no default; an undeclared arm fails a test |
| A nonce, per-user or per-role value in a `Shared` Surface | A shared cache would replay one visitor's token to another |
| A cache key whose parts are not enumerable from the site's content graph | A key space an anonymous visitor can invent is a cache they can fill |
| A `$wpdb` statement against a howdah table with neither a `LIMIT` nor a primary-key equality | An unbounded statement is a scan |
| A schema query such as `information_schema` on a request path | A schema fact is read once into a non-autoloaded option |
| `LIKE` with a leading wildcard over `post_title`, `post_excerpt` or `post_content` | Measured at 150× to 450× the indexed path |
| `sleep()`, `usleep()`, `set_time_limit()`, or a wait-for-lock loop on a request path | A waiting PHP worker is unavailable to every other request |
| A per-request log line, or query logging, in production | A cost that grows linearly with traffic |
| Core's formatting-sensitive schema function, anywhere | It cannot express a drop and it hides the statement's intent |
| A `CREATE TABLE` that does not name its engine | `get_charset_collate()` never sets the engine, so the server's default is inherited |

---

## Conventions

### DTOs

`final readonly class`, promoted and fully typed, `list<T>` in docblocks, enums
for closed sets, value objects for domain scalars. No `__get`, no `ArrayAccess`,
no `toArray()`, no `JsonSerializable`.

### Value objects

Enforce invariants at construction, in the constructor or a named constructor,
so an invalid value cannot exist. `Identifier` refuses a name outside the SQL
grammar; `Table` refuses an inconsistent declaration; `Column` refuses a
`varchar` over the index cap.

### Exceptions

`final`, private constructor, static named constructors, extend the most
specific SPL exception, implement the package marker interface, carry typed
context getters. Name the condition, not the throw site.

### Shells and shapes

`final` by default, and `final readonly` when every property is readonly.
Interfaces named for the role with no `Interface` suffix. `#[Override]` on every
override. Named arguments for optional parameters. No boolean flags.

### DDL

One emitter, one statement shape. A statement is joined with a newline literal,
never `PHP_EOL`, so the emitted text is byte-identical on every platform and a
golden assertion is meaningful. The prefix is applied at declaration time and
never at query time.

---

## Hooks

Names are `public const` on a `Hooks` class. Never inline.

```
mahout/{package}/{event}      # library
howdah/{domain}/{event}       # theme
```

The two core hooks this package observes (`after_switch_theme`, `admin_init`)
are constants on the same class, because the rule that bans a raw hook name
applies to a core hook as much as to a mahout one.

- Actions never return. Filters always return the first argument.
- Filters pass values and arrays, never mutable WordPress objects.
- Emit only from Providers and Modules.
- `wp_head` and `wp_footer` fire inside the `Document` component. Do not remove.

| Priority | Meaning |
|---|---|
| 5 | pre-empt |
| 10 | default |
| 20 | post-process |
| `PHP_INT_MAX` | enforcement |

New hooks are documented first, then added to the inventory. `composer
hooks:check` fails if the generated reference is stale.

---

## Storage

> `wp_postmeta` is a load-with-the-entity store, not a query store.

| Need | `StorageTarget` |
|---|---|
| Read with the entity, never filtered | `Meta` |
| Filtered, sorted, aggregated or counted | `Table` |
| Repeater, display-only | `Meta`, versioned JSON |
| Repeater, queried or unbounded | `Table` items table |
| Option-context field | `Meta` always |

`storage` is required on every field. No default. Repeater payloads are
versioned (`{"v":1,"items":[...]}`) and encoded by a dedicated codec, never by
the DTO. `JSON_THROW_ON_ERROR` always.

A `Table` field cannot be bound as a block attribute. **Sensitive values** go in
neither target: constants or environment only.

### Data integrity

- Any transaction statement belongs to the table gateway in
  `iniznet/mahout-fields`. This package declares no transaction method on
  `SqlConnection` and executes none.
- Every howdah table declares `ENGINE=InnoDB`. The emitter refuses anything
  else before the statement exists, and a test asserts the engine the server
  reports after the migration.
- Every migration implements `up()` and `down()`, and declares an
  `irreversibleReason()` that agrees with what `down()` does. An irreversible
  reversal blocks the whole rollback run before any statement executes.
- Migrations run from `wp mahout migrate`, from `after_switch_theme`, or lazily
  on `admin_init` for a user who can manage options. Never on the front end,
  never under Ajax, never under cron.

---

## Cacheability and throughput

- **Every statement is bounded.** A `LIMIT`, a primary-key equality, a unique-key
  equality, or an aggregate over an indexed column. The ledger's rows are bounded
  by the number of registered migrations.
- **A schema fact is read once** into a non-autoloaded option rather than
  queried per request. The gate is read on the three run paths and never on the
  front end.
- A plan, a reversal plan and a status write nothing at all, including the
  schema version option.

---

## Performance

- The lazy run path reads one option before it reads anything else, so a request
  whose schema is current touches no table.
- The ledger's declaration is built once, from the connection's prefix and
  charset/collation, and carried as a value. No statement is assembled by
  concatenating an identifier at query time.
- Options this package writes are stored with autoload disabled.

---

## Static access

**Permitted:** named constructors and codecs that hold no state and resolve no
collaborator — `Identifier::fromString()`, `MigrationLedgerSchema::table()`,
`RunContext::lazy()`, `CliExitCode::forFailure()` — plus enums and `*::class`
constants.

**Banned:** static access to anything that queries, caches, mutates or resolves
a collaborator — repositories, field readers, field query builders, registries,
containers.

**Boundary and composition-root exceptions, and nothing else:** the theme's
`Request::fromSuperglobals()`, `Bootstrap::run()`, `Bootstrap::services()`,
`Surfaces::resolve()`, the kernel's `Kernel::inWordPress()` and
`Environment::fromWordPress()`, and this package's
`WpdbConnection::inWordPress()`. The test is not "is it static" but "does it
resolve a collaborator".

---

## Errors and security

- **Failures are loud; output is defined.** A thrown exception is recorded
  through `Diagnostics` at `critical` when it belongs to a run. Development
  rethrows it. No silent fallback, no substituted data.
- A migration failure is recorded, fired as `mahout/db/migration_failed`, and
  rethrown as `MigrationFailed` carrying the cause. Nothing is retried and
  nothing is compensated.
- **Identifiers are never parameters.** A caller reads one from a schema object;
  `SqlConnection` never accepts a caller-supplied identifier string. Every value
  goes through a placeholder, always.
- **A refusal is not a failure.** It is decided before the first statement runs
  and it has its own exit code.

---

## Gates

```bash
composer format      # PHP-CS-Fixer, @PSR12 + @Symfony
composer stan        # PHPStan, max level, no baseline
composer psalm       # Psalm taint
composer arch        # the architecture rules (the shared PHPStan config)
composer rector      # Rector dry-run
composer test        # PHPUnit
composer hooks:check # generated hook reference is current
composer i18n:check  # generated POT is current
composer doctor      # installation assembly
composer config:check# analyzer config and artifact set are referenced, not copied
composer check       # all of the above
```

`composer check` must pass before every commit. No exceptions, no `--no-verify`.

---

## Required tests

| Must have a test |
|---|
| Every value object's invariant, including rejection |
| Every exception's named constructor |
| The emitted DDL, byte for byte, including the engine clause and the charset/collation clause |
| The engine check from both sides: every created table is InnoDB, and a non-InnoDB declaration never reaches the database |
| A migration plan that writes nothing, including the ledger and the version option |
| The ledger's bootstrap, its uniqueness on the migration name, and its batch membership |
| A migration that throws: a critical record, a named hook, no ledger row and no recorded version |
| Reversibility: up() then down() returns the schema to its prior state, on a table and on an index |
| Reversibility agreement: every declared `irreversibleReason()` matches what `down()` does |
| An irreversible migration refusing the whole batch before a single statement runs |
| A rollback walking one batch and two batches, in the reverse of application order |
| Index presence after migration, read from the server, and its absence after reversal |
| The schema version gate performing no ledger read |
| The three run paths, and the refusal of a front-end, Ajax, cron or incapable-user request |
| The engine check on the test connection before any transactional test runs |
| The architecture rules firing on a violation fixture and not on a clean one |
| No identifier token anywhere naming core's schema function |
| No string literal anywhere being a transaction statement |

No coverage target. Coverage rewards testing getters.

---

## Simplicity rules

- If a class has one public method and one responsibility, that is correct.
- Four repeated lines of boilerplate are cheaper than a trait that hides a
dependency.
- If the composition root needs a config file, the indirection is wrong.
- If you need `@phpstan-ignore`, you need a different design.
- If a name needs explaining at the call site, name it better.

---

## This package's section

`mahout-db` implements the contract above. It records the following, and only
the following, deviations. Each is an ADR, not an edit to the contract.

| Deviation | Why | ADR |
|---|---|---|
| `DbProvider` reads the connection through `WpdbConnection::inWordPress()` instead of receiving it from the theme | A provider is instantiated by class name with no constructor arguments, so it cannot be handed a collaborator; the connection is WordPress's own, and this is the same shape as `Kernel::inWordPress()`. Everything the provider *declares* is declared under a `Contracts` interface and resolved by it | 0004 |
| `composer stan` and `composer arch` run the same shared PHPStan config | A consumer's root config must include the shared one, which already carries the rules | 0005 |
| The committed `composer.lock` is resolved through the uncommitted path repository | `mahout-devtools` is not published yet, and a committed `path` repository would make a fresh clone unresolvable | 0005 |
| Exception messages are not translated | They are developer-facing diagnostics; the generated POT is header-only | 0005 |

No other rule in this document is relaxed. In particular: no reflection, no
service locator reached for statically, no trait, no dynamic property, no
`error_log()` outside `Diagnostics`, no `mixed` in a public signature, no
`@phpstan-ignore`, no `$wpdb` outside `WpdbConnection`, and no transaction
statement.

### Deliberate scope boundary

The search index over `wp_posts` is **declared** here — `IndexKind::FullText`
exists so the clause is emitted rather than hand-built — but it is not created
by this package. Creating it belongs to the slice that owns the tokeniser and
the fallback, and this package adds no index to core's tables. A test asserts
both halves of that: the declaration names exactly the `MATCH` columns, and core's
post table carries no `howdah_search` index.

### Notes for the next slice

- The transaction boundary, the typed table gateway, the search index and the
  orphan collection are `iniznet/mahout-fields` and the theme. `SqlConnection`
  carries no transaction method on purpose.
- `CliExitCode` is the contract; the WP-CLI binding lives in the theme's CLI
  provider, because `WP_CLI` is not a WordPress symbol and the analyzer's stubs
  are generated from core alone.

---

## Reference

The canonical planning corpus records the reasoning, the rejected alternatives
and the delivery roadmap. It is private and is not published with this
repository, so this document stands alone on purpose. The package-scoped
decisions are under `docs/decisions/`; the generated hook reference is
`docs/reference/hooks.md`.
