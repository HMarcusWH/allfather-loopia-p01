# Runtime Storage

This directory is reserved for **runtime-generated private storage** and is not committed.

Planned evidence policy:

- content-addressed artifacts;
- append-only application semantics;
- digest bound back to governed database records;
- no evidence mutation API;
- no WordPress storage sharing;
- no secrets committed to Git.

The Loopia capability probe must verify the final private filesystem location before production use.
