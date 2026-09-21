# Security policy

## The private route

Report a vulnerability through GitHub's private vulnerability reporting on this
repository (the *Security* tab, then *Report a vulnerability*). No security email
address is published.

## Response expectation

| Stage | Commitment |
|---|---|
| Acknowledgement | within 3 working days |
| Initial assessment | within 10 working days |
| Fix or a written plan | a defect in a repository-owned surface gets a fix or a dated plan |
| Disclosure | coordinated with the reporter, after the fix or after 90 days, whichever comes first |
| Credit | offered, never assumed |

## What is in scope in this repository

- Identifier validation: a table, column or index name that reaches DDL.
- Every statement the ledger issues, and the placeholders it binds.
- The migration plan, and the guarantee that a refusal runs before the first
  statement.
- The run paths, and the capability, request-kind and version gates on each.
- The `ENGINE=InnoDB` assertion, which is what makes a later transaction
  boundary meaningful rather than decorative.

A defect in a different repository of the family belongs in that repository. In
particular, the transaction boundary and the field write path are
`iniznet/mahout-fields`.
