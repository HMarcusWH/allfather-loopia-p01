# BF-00 — Build-first source, decision and schema checkpoint
Prepared: 28 October 2026. **Build-only candidate** from `f0a622f5ae12d52438a654ef82eed34f84c47e77`, Git tree `bfed569a5611e29902809f6554f8fbdc0a0e4da3`. This is an immutable historical source observation, not a live-host poll or operational authorization.

## Separate identities, separate claims
| Plane | Evidence available at source freeze | Ceiling |
|---|---|---|
| PHP source | `f0a622f5ae12d52438a654ef82eed34f84c47e77`, tree `bfed569a5611e29902809f6554f8fbdc0a0e4da3` | Initial BUILD-01 base; future heads must be checked separately |
| Canonical source inspected | `78d5a06929a760f432d56bd63adafbdd40035ec4` | Inspection does NOT update the globally adopted pin |
| Global adopted pin | `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d` | Unchanged; no silent promotion |
| GitHub CI | [40574362613](https://github.com/HMarcusWH/allfather-loopia-p01/actions/runs/40574362613), 5/5 successful on BASE | Not a BF-00 PR CI receipt |
| Railway staging | Deployment `9460b6d5-99ae-4c5d-a4a9-69f1f35c779e` reports SUCCESS for BASE | Not BF-00 deployed; no model-quality, U03 rights or production claim |
| Runtime execution grants | NOT QUERIED | Cannot be inferred from Git source |
| New schema families 260–350 | 12 candidate allocations | No DDL or migration applied |
| Customer release / external action | Separate owner GO, not supplied | None granted by these registries |

## Contract files

- [U01–U10 owner decision matrix](BUILD_FIRST_DECISION_MATRIX.json) keeps selected options distinct from six-party U03 ratification, upstream adoption, PHP implementation and live permission. U04 option selection in canonical PR #378 is **not** the missing cross-owner runtime approval. U01 PR #379 and U03 PR #382 remain proposal/docket work.
- [Capability matrix](build-first/CAPABILITY_MATRIX.json) freezes 29 build milestones, their exact dependencies, owner seams, default-inert factory bindings and the full status vocabulary. A status may become BUILT_INERT after a qualifying PR, but does not create execution authority.
- [Schema family registry](build-first/SCHEMA_FAMILY_REGISTRY.json) assigns all 12 exact **proposed** table-name families, semantic owners, column-level **proposed/unratified** FK targets/indexes, mutability, M05 source-byte ownership and VA-03 recovery requirements. Column-level FK/index candidates are supplied now as proposed designs, explicitly **unratified and not executable DDL**. RT-01 must resolve actual owner identities, cycles, locking and physical order without editing old applied SQL.

The source-plan SHA-256 is `e7c2f7d2366e81a2ab623fa998314ee264d9ef8818c843cb1171da70750d17b2` for `Execution_Manifest_v2.json` in the provided October 27 build-plan archive. Historical `docs/longitudinal/LONGITUDINAL_INTELLIGENCE_STATE.md` and `STATUS_LEDGER_2026-10-25.json` are unchanged; their old "as-of" statuses are not substituted for this record.

## Namespace discipline

September `P00–P12` were **engineering completion work-package IDs**, *not* thirteen product modules. `P01/P02/P03/P04/P05/P07-A` are bounded product owners. `BF/CR/OB/HS/TM/DU/IV/AN/AQ/UI/VA/RT` are build-package names. U01–U10 are domain-owner decision requests, never executable permission flags.

## Exit and next dependencies

Run `php tests/run.php --layer=foundation --filter=build_first_registry --strict` and `php tests/run.php --layer=unit --filter=build_first_registry --strict` for deterministic positive/negative registry checks. Both run inside the normal GitHub CI test layers. No migrations, secrets, provider calls, source fetches, user-role modifications, live schedules, customer endpoints or Railway deployments are performed by BF-00.

BF-01 follows with actual typed consumer contracts and fixture harness. VA-00 freezes evaluation inputs. RT-01 later resolves column-level FK/index details and tests fresh/upgrade/no-op/restore before any authorized schema installation; **migration 900 is byte-immutable and is never replayed for the new candidate families**. Full V01–V11 system testing follows RT-02.

Only `main` is Railway-connected. The integration branch is a build workspace, not an independently authorized deployment. The current GitHub branch ruleset does not enforce the `code qualification` check; explicit fresh CI verification remains a merge requirement until the owner corrects that setting.
