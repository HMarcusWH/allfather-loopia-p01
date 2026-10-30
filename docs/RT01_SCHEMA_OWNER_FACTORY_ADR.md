# BUILD-28 / RT-01 — Physical candidate DDL reconciliation and hard-disabled owner composition

Reconciled the actual twelve source SQL families with the provisional frozen schema allocations rather than overwriting historical decisions. The physical source contract now covers 38 tables and 76 append-only triggers with derived FK ordering and an exact legacy migration-900 digest check.

HistorySchemaPlan generates a deterministic source-hashed, dependency-ordered, versioned candidate install plan. HistorySchemaFixtureInstaller positively executes the same physical DDL only on independently disposable, test-named MariaDB 10.6/10.11 with an explicit CLI fixture marker. A one-table partial install and a missing trigger are hard HOLDs, not auto-repaired. No added native migration ID, live-schema startup dependency or real SQL/host operation is introduced.

HistoryReviewFactory gains a positive typed fixture composition of the exact AN-03/AN-04/AQ-02 lineage and fourteen static module owners; real forRuntime remains unchanged and refused. RuntimeInvariantProbe gains only a read-only source-index status. The operator script is a source-only plan with no customer, provider, scheduler, network or migration action.

**Remaining owner gates:** U01–U10 not adopted. Source build success does not prove source rights, real model execution or quality, human factual/delivery decisions, M10 current reliance, P04/M16 output, populated restore, Railway staging, Loopia hosting or customer launch. RT-02 and V01–V11 remain separate.
