# ADR-0004 — The connection is read where WordPress keeps it

Status: accepted, and revisited by the contract change that gave the kernel container a key it is
resolved by (mahout-kernel ADR-0006).

## Context

`DbProvider` needs a database connection and a `Diagnostics`. A kernel provider is instantiated by
class name with no constructor arguments, so it cannot be handed a collaborator through its
constructor. Whichever way it is resolved, the connection has to be obtained from the process, and
WordPress publishes exactly one of them: `$wpdb`.

## Decision

- `DbProvider::register()` reads the connection through `WpdbConnection::inWordPress()`, the
  package's composition-root named constructor. It resolves no collaborator: it reads the one global
  WordPress publishes and throws `ConnectionMissing` when it is absent.
- `Internal\WpdbConnection` is the only class in the package that names `$wpdb`, and
  `DbProvider` and `Search\MatchClause::fromWordPress()` are the only classes that name
  `WpdbConnection`. Both facts are greppable in one file each. The second of the two was
  added by ADR-0007, which moved the indexed search path into this package; the reason is
  the same one recorded above — a composition root built by class name has to read the
  connection from the process.
- Every collaborator the provider declares is declared under the `Contracts` interface a consumer
  depends on — `SqlConnection`, `MigrationStore`, `SchemaVersionStore` — and every lookup resolves by
  that interface. Nothing outside the package names an `Internal` class to receive one, and the
  provider's own composition root no longer names an implementation class either.
- There is no fallback: a request without a database fails loudly at registration rather than
  degrading.

## The revisit

The original version of this decision gave the container as part of its reason: the kernel's container
keyed a service by its own class name only, so a service could not be declared under an interface key.
That limitation is gone — `Container::set()` takes the key it registers under, defaulting to the
service's own class name — and the sentence has been removed rather than left to mislead a reader.

Reading the connection here survives the fix, because it was never a workaround for the container: a
provider built by class name cannot be handed a connection it must read from the process, so the
connection is still read through the package's own boundary. What the fix changes is everything the
provider *declares*: those are contract keys now, so the `Contracts`/`Internal` split is enforced at
the composition root instead of only by convention.

## Consequences

- The composition root is one line (`$kernel->provider(DbProvider::class)`), and the theme never names
  an `@internal` class and never names a concrete implementation to receive a collaborator.
- The provider builds its own ledger declaration from the connection's prefix and charset/collation,
  so neither value is ever read at query time or configured twice.
- The run-path tests cannot inject a connection. They do not need to: every statement is observable
  through `$wpdb`'s own buffer, so "the lazy gate wrote nothing" is proved against the real connection
  rather than against a fake one.
