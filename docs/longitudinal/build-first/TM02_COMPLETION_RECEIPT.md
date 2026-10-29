# BUILD-18 / TM-02 — source-only staging receipt

Prepared 2026-10-29. Not yet CI-qualified or merged; IV-01 must merge first.

Implemented versioned M17 P01 memory persistence in the existing CLI/disposable-only candidate store, preserving v1. The v2 run uses typed competitor/question, exact input/profile/engine/window digests. Readback checks stored identity, every selected lineage row, and deterministic replay. Late input appends linked successors; forks HOLD; missing reported references are NOT_REPLAYABLE.

Unit, security and disposable MariaDB test sources, candidate-only CLI previews and this ADR are supplied. No live factory, schema installation, historical-use permission, model call, purge, customer output or deployment authorization. RT-01, RT-02, V01–V11 and U03/U04 owner decisions remain pending.
