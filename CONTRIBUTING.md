# Contributing

## The short path

1. Install the toolchain: `composer install`.
2. Make the change.
3. Run `composer check` until it is green. A gate never passes silently; a
   missing prerequisite fails loudly.
4. Open a pull request against the repository that owns the surface.

The pull request is licensed under GPL-2.0-or-later by the act of opening it.
No contributor licence agreement is required and no copyright assignment is
requested.

## The change-routing rule

Route a change by the kind of change, not by the file path.

| Kind of change | Repository |
|---|---|
| A `Contracts/` interface, a hook constant, or a documented public concrete class | the package that declares it |
| `Internal/` code no contract names | the package that owns it |
| An architecture rule, the self-generated stubs, the test bootstrap, `doctor`, or a CI job shared by every repository | `iniznet/mahout-devtools` |
| A field type, sanitiser, control, save lifecycle or REST route | `iniznet/mahout-fields` |
| A table, index, migration or transaction gateway | `iniznet/mahout-db` |
| A content type, taxonomy, rewrite rule or REST helper | `iniznet/mahout-content` |
| Asset resolution, the build manifest or entry contexts | `iniznet/mahout-assets` |
| Boot order, the service map, diagnostics or hook registration | `iniznet/mahout-kernel` |
| A scaffold flag, preset tree or stub | `iniznet/mahout-scaffold` |
| A Surface, Component, feature repository, mapper, DTO, CSS, JS, admin registration or the request adapter | `iniznet/howdah` |
| A licence, contribution, conduct, security or template document | every repository, in one coordinated change |

**A change that cannot be made in one repository is a contract change.** It is
filed as one change per repository and is not merged until every consumer is on
the new surface.

## The cross-repository procedure

1. Record the decision.
2. Introduce the new surface additively.
3. Deprecate the old surface in the same change.
4. Tag a minor release.
5. Adopt in dependency order and tag a minor per consumer.
6. Remove the old surface at the producer's next major, with a changelog entry
   naming the removal.

A consumer pinned to `^1.0` cannot install a `2.0`, so a removal is blocked by
the dependency resolver rather than by the reviewer.

## What a schema change must include

- `composer check` green.
- A migration that declares both directions, and an `irreversibleReason()` that
  agrees with what `down()` does.
- `ENGINE=InnoDB` on every new table, stated explicitly and never inherited.
- A test that the index exists after the migration, read from the server rather
  than from the declaration that produced it.
- A decision record when the change decides something.
- The affected documentation corrected in the same change.

## Code of conduct

This project follows the [Contributor Covenant](./CODE_OF_CONDUCT.md). Report
unacceptable behaviour through the contact stated there.
