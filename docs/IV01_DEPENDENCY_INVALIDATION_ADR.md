# IV-01 — Scoped dependency index, bounded invalidation and retention-safe holds

**Package:** Wave 07 IV-01 / LI-15 / U03 / U10.
**Depends on:** merged HS-02. Implementation remains source-only, candidate schema 350 stays outside runtime migrations. This is not M05/M10 owner authorization, erasure or production activation.

## Typed dependency graph

HistoryDependencyIndex.v1 creates a deterministic metadata-only chain across CAPTURE, SECTION_DERIVATIVE, ASSESSMENT, HISTORICAL_BASIS, TEMPORAL_MEMORY, ANALYSIS, FINDING and ATTENTION. Each candidate node stores only its type, safe identity and SHA-256 metadata reference, with a common tenant/workspace/P01 competitor/question. Approved edges are forward DERIVED_FROM or same-kind SUCCESSOR_OF; reverse ownership is a refusal. Cap: 256 nodes, 512 edges and 64 direct dependents per parent. Node and edge order is canonical. Repeated identical input yields an identical graph digest. Cyclic successor provenance or excess fanout produces explicit HOLD rather than recursion, hidden branch resolution or a false complete history.

Graph identity is not M02/P01/M05 reference authentication. No raw source payload, model candidate, source URL, P02 state, customer description or independent publisher/event inference is entered. Owner source/retention checks must be re-run at actual read/use time.

## Invalidation causes and synchronous HOLD

HistoryInvalidation.v1 distinguishes SOURCE_RIGHTS_REVOKED, CAPTURE_BYTES_MISSING, CONTRADICTORY_EVIDENCE, ASSESSMENT_SUPERSEDED, RETENTION_EXPIRED and LAWFUL_ERASURE. It freezes a cause reference/digest/time but marks owner_cause_authenticated=false. There are no caller-granted rights or source activity tokens. A named cause can be reasoned over using the candidate graph, but only actual M05/M10/retention owners can authenticate a cause or perform the corresponding legal correction.

inspectReliance returns HOLD immediately for affected downstream nodes, independent of whether the full cascade has been processed. Unknown/unscanned dependencies also HOLD; an uncompleted batch or candidate graph can never be used as proof that downstream output remains safe. The real runtime must still perform HS-02 and M10 synchronous current-source/reliance checks at preview, pre-dispatch, post-return, pre-release and customer read; this source package does not register such a runtime adapter.

## Bounded resumable propagation

An invalidation has an immutable identity bound to scoped graph digest and exact typed cause digest. The iterative traversal has bounded maximum nodes and 1–32 selected items per batch; affected references are visited at most once. The prior receipt must match its checksum, cause, graph, exact processed prefix and next cursor. Arbitrary cursor jumps, false progress, mutated event contents and cycles refuse or HOLD. A completed traversal remains COMPLETE_CANDIDATE_HOLD, not a source-use grant or M10 product release.

Candidate family 350 proposes three tables: dependency graph candidates, invalidation events and immutable batch receipts. Six database UPDATE/DELETE triggers enforce append-only metadata. HistoryInvalidationStore is available only on CLI in an explicitly disposable TEST schema; every graph/cause/receipt operation is transactional and replayable, and a repeated cursor+time returns NO_WRITE. Same cursor with changed content conflicts. No production migration, scheduler, provider call, source fetch or customer notification results.

## Retention and erasure safety

Append-only audit metadata must never be used to justify retaining prohibited raw source bytes forever. The source/retention owner still decides what to quarantine, redact, physically delete or key-destroy, including what is lawfully eligible to remain as an audit digest. This candidate copies no bodies or derived narratives, and cannot delete bytes. Restores and backup policies must independently avoid resurrecting erased material. A lawful-erasure cause in the graph is a HOLD/proposed reconciliation, not an executed erase or legal determination.

## Inspection and verification

ops/history/invalidation-candidate.php has only CLI-safe status and a guarded synthetic preview. It cannot target a real tenant, source, database, provider or deletion command. Unit tests cover a complete capture-to-attention chain, a three-batch exact continuation, changing scope/cause, contrary/fanout/cycle stress, immediate reliance hold and malformed future causes. Security tests reject spoofed authentication, self-hashed permission expansion and direct side-effect APIs. Disposable MariaDB 10.6/10.11 tests 350 DDL, graph/event/receipt idempotence, cross-tenant denial, no-skip cursor, append-only triggers and absence of runtime migrations.

**Deferred:** U03/U10 multi-owner adoption, actual source dependency completeness, current rights, lawful retention/delete/backup restore, M10 current reliance/release, RT-01/RT-02 and final V01–V11 security/human/host/production GO. All source code is BUILT_INERT, not deployed or owner-admitted.
