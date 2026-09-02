# P01 Conformance Suite

The conformance suite compares governed outcomes, not implementation technology.

Each fixture will have:

- a stable fixture ID;
- input facts and authority state;
- an expected normalized governed transcript;
- explicit refusal/release reason codes;
- upstream provenance.

Initial required cases:

1. unapproved source -> REFUSE;
2. prohibited license -> REFUSE;
3. missing evidence -> REFUSE RELEASE;
4. M08 HOLD -> NO RELEASE;
5. uncertain interpretation -> HYPOTHESIS, not FACT;
6. approved evidence + admissible verdict -> RELEASE ELIGIBLE;
7. cross-tenant access -> REFUSE.

Implementation-specific IDs and timestamps must be normalized before comparison.
