# SCHEMA_REACHABILITY

**Plane:** Ops — schema derivation record.
**Canonical schema authority:** `packages/allfather-control-substrate/src/db/schema.ts` @ `c6d9cf9a7a1001b001ca6118999e9cb75c831c4d`
**Target:** MariaDB 10.6+ / InnoDB (developed and tested against 10.11.14)

The canonical SQLite schema is **structure and invariant authority, not copy-paste SQL**. This
document records how the MariaDB schema was derived and which upstream tables are in or out.

---

## 1. Derivation method

1. **Enumerate the canonical table set.** `CREATE TABLE` names in `db/schema.ts` → **225 tables**.
2. **Enumerate selected functions.** Every non-test source file under the selected module
   directories: `p01 p02 p03 p04 p05 p07 m01 m02 m03 m04 m05 m06 m07 m08 m09 m10 m11 m12
   ingestion release-output`.
3. **Extract table references** — every identifier following `FROM`, `INTO`, `UPDATE`, `JOIN` or
   `TABLE` in those sources, intersected with the canonical table set.
4. **Close over authority parents.** Any table referenced by an included table's FK or scope check
   is included even where no selected function names it directly.
5. **Apply policy exclusions.** A table that is mechanically reachable but sits in a family the
   current owner gate excludes is dropped and recorded in §4 with its reason.
6. **Group into ordered migrations** by dependency depth.

**Result: 192 of 225 canonical tables mechanically reachable; 188 included after policy exclusion.**

---

## 2. Included tables by migration group

Consuming-module columns are the mechanical result of step 3 and are the reason each table is
included. `M16` appears as `RELEASE-OUTPUT`, `M15` as `INGESTION`.

### `001_identity_scope`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `access_evaluation_records` | M02 | `access_evaluation_records` (InnoDB) |
| `data_scopes` | M03 | `data_scopes` (InnoDB) |
| `operators` | M02, M12 | `operators` (InnoDB) |
| `permissions` | M02 | `permissions` (InnoDB) |
| `role_permissions` | INGESTION, M02, M12 | `role_permissions` (InnoDB) |
| `roles` | INGESTION, M02, M12 | `roles` (InnoDB) |
| `service_identities` | INGESTION, M02 | `service_identities` (InnoDB) |
| `subscription_tier_permissions` | M06, P01 | `subscription_tier_permissions` (InnoDB) |
| `subscription_tiers` | M06, P01, P07 | `subscription_tiers` (InnoDB) |
| `tenant_subscriptions` | M06, P01 | `tenant_subscriptions` (InnoDB) |
| `tenants` | M01, M02 | `tenants` (InnoDB) |
| `workspace_memberships` | INGESTION, M02, M12 | `workspace_memberships` (InnoDB) |
| `workspaces` | M01, M02, M10, P01, P02, P07 | `workspaces` (InnoDB) |

### `010_governance_core`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `arbiter_check_records` | M08 | `arbiter_check_records` (InnoDB) |
| `arbiter_conditions` | M10 | `arbiter_conditions` (InnoDB) |
| `arbiter_evaluation_runs` | M08 | `arbiter_evaluation_runs` (InnoDB) |
| `arbiter_invalidations` | M08, M09, M10, P07 | `arbiter_invalidations` (InnoDB) |
| `arbiter_policy_rules` | M08 | `arbiter_policy_rules` (InnoDB) |
| `arbiter_policy_sets` | M08, P07 | `arbiter_policy_sets` (InnoDB) |
| `arbiter_verdicts` | M08, M09, M10, M11, P05, P07 | `arbiter_verdicts` (InnoDB) |
| `artifact_integrity_verifications` | M09 | `artifact_integrity_verifications` (InnoDB) |
| `audit_check_records` | INGESTION, M11, P01, P02, P03, P04, P05, P07, RELEASE-OUTPUT | `audit_check_records` (InnoDB) |
| `audit_findings` | INGESTION, M11, P01, P02, P03, P04, P05, P07, RELEASE-OUTPUT | `audit_findings` (InnoDB) |
| `audit_profiles` | INGESTION, M11, P01, P02, P03, P04, P05, P07, RELEASE-OUTPUT | `audit_profiles` (InnoDB) |
| `audit_runs` | INGESTION, M11, P01, P02, P03, P04, P05, P07, RELEASE-OUTPUT | `audit_runs` (InnoDB) |
| `capability_contracts` | M03, P02, P05, P07 | `capability_contracts` (InnoDB) |
| `console_command_intents` | M12 | `console_command_intents` (InnoDB) |
| `console_command_result_references` | M12 | `console_command_result_references` (InnoDB) |
| `console_command_surface_definitions` | M12 | `console_command_surface_definitions` (InnoDB) |
| `console_decision_timeline_projections` | M12 | `console_decision_timeline_projections` (InnoDB) |
| `console_display_representation_references` | M12 | `console_display_representation_references` (InnoDB) |
| `console_navigation_entitlement_projections` | M12 | `console_navigation_entitlement_projections` (InnoDB) |
| `console_redaction_policy_projections` | M08, P04 | `console_redaction_policy_projections` (InnoDB) |
| `console_refresh_statuses` | M12 | `console_refresh_statuses` (InnoDB) |
| `console_release_readiness_projections` | M12 | `console_release_readiness_projections` (InnoDB) |
| `console_workspace_context_projections` | M12 | `console_workspace_context_projections` (InnoDB) |
| `containment_acknowledgements` | M11 | `containment_acknowledgements` (InnoDB) |
| `containment_recommendations` | M11 | `containment_recommendations` (InnoDB) |
| `customer_output_projections` | M12, P01, RELEASE-OUTPUT | `customer_output_projections` (InnoDB) |
| `decision_input_references` | M10, M11, RELEASE-OUTPUT | `decision_input_references` (InnoDB) |
| `decision_ledger_streams` | M10, M11, M12 | `decision_ledger_streams` (InnoDB) |
| `decision_records` | M08, M10, M11, M12, P01, P02, P05, P07, RELEASE-OUTPUT | `decision_records` (InnoDB) |
| `decision_supersession_records` | M08, M10, RELEASE-OUTPUT | `decision_supersession_records` (InnoDB) |
| `forbidden_action_clauses` | M03 | `forbidden_action_clauses` (InnoDB) |
| `gap_records` | M11 | `gap_records` (InnoDB) |
| `governed_admission_events` | M01, M06 | `governed_admission_events` (InnoDB) |
| `governed_change_proposals` | M11 | `governed_change_proposals` (InnoDB) |
| `governed_execution_claims` | M01 | `governed_execution_claims` (InnoDB) |
| `governed_tool_operations` | M01, M06 | `governed_tool_operations` (InnoDB) |
| `governed_tools` | M01, M06 | `governed_tools` (InnoDB) |
| `hmwh_assessments` | M07 | `hmwh_assessments` (InnoDB) |
| `hmwh_dissent_records` | M07 | `hmwh_dissent_records` (InnoDB) |
| `hmwh_eligibility_checks` | M07 | `hmwh_eligibility_checks` (InnoDB) |
| `hmwh_participant_assignments` | M07 | `hmwh_participant_assignments` (InnoDB) |
| `hmwh_results` | M07, M08 | `hmwh_results` (InnoDB) |
| `hmwh_review_sessions` | M07 | `hmwh_review_sessions` (InnoDB) |
| `hmwh_risk_veto_records` | M07 | `hmwh_risk_veto_records` (InnoDB) |
| `incident_cases` | M11 | `incident_cases` (InnoDB) |
| `incident_evaluation_requests` | M11 | `incident_evaluation_requests` (InnoDB) |
| `incident_timeline_entries` | M11 | `incident_timeline_entries` (InnoDB) |
| `integration_health_records` | M01 | `integration_health_records` (InnoDB) |
| `ledger_integrity_links` | M10 | `ledger_integrity_links` (InnoDB) |
| `ledger_verification_runs` | M10 | `ledger_verification_runs` (InnoDB) |
| `mission_assignments` | M04, M06, P07 | `mission_assignments` (InnoDB) |
| `mission_review_paths` | M08 | `mission_review_paths` (InnoDB) |
| `mission_status_transitions` | M04 | `mission_status_transitions` (InnoDB) |
| `missions` | M04, M06, P01, P02, P03, P04, P05, P07 | `missions` (InnoDB) |
| `output_access_rules` | RELEASE-OUTPUT | `output_access_rules` (InnoDB) |
| `output_class_bindings` | M03, P02, P07 | `output_class_bindings` (InnoDB) |
| `output_visibility_profiles` | RELEASE-OUTPUT | `output_visibility_profiles` (InnoDB) |
| `proposal_envelopes` | M04, M05, M07, M08, M09, M10, M12, P01, P02, P03, P04, P05, P07 | `proposal_envelopes` (InnoDB) |
| `proposal_status_transitions` | M04, M07, M08, M10 | `proposal_status_transitions` (InnoDB) |
| `proposal_submission_records` | M04 | `proposal_submission_records` (InnoDB) |
| `replay_cases` | M11 | `replay_cases` (InnoDB) |
| `replay_profiles` | M11 | `replay_profiles` (InnoDB) |
| `replay_results` | M11 | `replay_results` (InnoDB) |
| `review_conditions` | M03 | `review_conditions` (InnoDB) |
| `runtime_adapter_instances` | M01 | `runtime_adapter_instances` (InnoDB) |
| `runtime_host_bindings` | M01 | `runtime_host_bindings` (InnoDB) |
| `runtime_host_execution_refs` | M01 | `runtime_host_execution_refs` (InnoDB) |
| `runtime_host_instances` | M01 | `runtime_host_instances` (InnoDB) |
| `runtime_host_tool_refs` | INGESTION, M01, M06 | `runtime_host_tool_refs` (InnoDB) |
| `tool_authorization_decisions` | M01, M05, M06 | `tool_authorization_decisions` (InnoDB) |
| `tool_denial_escalation_records` | M06 | `tool_denial_escalation_records` (InnoDB) |
| `tool_dispatch_records` | M01, M06 | `tool_dispatch_records` (InnoDB) |
| `tool_execution_outcomes` | M05, M06, M10 | `tool_execution_outcomes` (InnoDB) |
| `tool_gate_checks` | M01, M06 | `tool_gate_checks` (InnoDB) |
| `tool_idempotency_records` | M06 | `tool_idempotency_records` (InnoDB) |
| `tool_requests` | M01, M05, M06 | `tool_requests` (InnoDB) |
| `tool_rights` | M03 | `tool_rights` (InnoDB) |
| `usage_metering_records` | P01 | `usage_metering_records` (InnoDB) |
| `valkyrie_authority_evaluations` | M03 | `valkyrie_authority_evaluations` (InnoDB) |
| `valkyrie_revocations` | M03 | `valkyrie_revocations` (InnoDB) |
| `valkyries` | M03, M07, P05, P07 | `valkyries` (InnoDB) |

### `020_sources_evidence`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `evidence_bundle_items` | M05 | `evidence_bundle_items` (InnoDB) |
| `evidence_bundles` | M05, M07 | `evidence_bundles` (InnoDB) |
| `evidence_missingness_records` | M05 | `evidence_missingness_records` (InnoDB) |
| `evidence_objects` | INGESTION, M05, M08, P01, P02, P03, P05, P07, RELEASE-OUTPUT | `evidence_objects` (InnoDB) |
| `raw_artifacts` | INGESTION, M05 | `raw_artifacts` (InnoDB) |
| `source_license_cards` | INGESTION, M08, P01, P03, RELEASE-OUTPUT | `source_license_cards` (InnoDB) |
| `source_references` | INGESTION, M05, M08, P01, P02, P03, P04, P05 | `source_references` (InnoDB) |
| `source_terms_reviews` | INGESTION, P01, P03 | `source_terms_reviews` (InnoDB) |

### `030_p01_monitor`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `battlecard_briefs` | P01, RELEASE-OUTPUT | `battlecard_briefs` (InnoDB) |
| `company_signals` | INGESTION, M08, P01, P03, RELEASE-OUTPUT | `company_signals` (InnoDB) |
| `competitor_status_transitions` | P01 | `competitor_status_transitions` (InnoDB) |
| `competitors` | INGESTION, M08, P01 | `competitors` (InnoDB) |
| `monitored_sources` | M08, P01, RELEASE-OUTPUT | `monitored_sources` (InnoDB) |
| `released_briefings` | M08, P01, P04, P05, RELEASE-OUTPUT | `released_briefings` (InnoDB) |
| `source_capture_reservations` | P01 | `source_capture_reservations` (InnoDB) |
| `source_diffs` | M08, P01, RELEASE-OUTPUT | `source_diffs` (InnoDB) |
| `source_snapshots` | P01 | `source_snapshots` (InnoDB) |

### `040_p02_entities`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `entity_exposure_summaries` | P02 | `entity_exposure_summaries` (InnoDB) |
| `entity_relationship_edges` | INGESTION, P02 | `entity_relationship_edges` (InnoDB) |
| `entity_relationship_evidence_links` | M08, P02, RELEASE-OUTPUT | `entity_relationship_evidence_links` (InnoDB) |
| `entity_relationship_state_change_proposals` | INGESTION, M08, P02 | `entity_relationship_state_change_proposals` (InnoDB) |
| `entity_relationship_state_change_status_transitions` | P02 | `entity_relationship_state_change_status_transitions` (InnoDB) |
| `entity_relationship_status_history` | P02 | `entity_relationship_status_history` (InnoDB) |
| `entity_relationship_versions` | M08, P02, RELEASE-OUTPUT | `entity_relationship_versions` (InnoDB) |
| `entity_twin_ledger_anchors` | M08, P02 | `entity_twin_ledger_anchors` (InnoDB) |
| `entity_twin_source_links` | M08, P02, RELEASE-OUTPUT | `entity_twin_source_links` (InnoDB) |
| `entity_twin_state_change_proposals` | INGESTION, M08, P02 | `entity_twin_state_change_proposals` (InnoDB) |
| `entity_twin_state_change_status_transitions` | P02 | `entity_twin_state_change_status_transitions` (InnoDB) |
| `entity_twin_state_summaries` | INGESTION, M08, P02 | `entity_twin_state_summaries` (InnoDB) |
| `entity_twin_status_history` | P02 | `entity_twin_status_history` (InnoDB) |
| `entity_twin_uncertainty_summaries` | M08, P02, RELEASE-OUTPUT | `entity_twin_uncertainty_summaries` (InnoDB) |
| `entity_twin_versions` | INGESTION, M08, P02, RELEASE-OUTPUT | `entity_twin_versions` (InnoDB) |
| `entity_twins` | INGESTION, M08, P02, RELEASE-OUTPUT | `entity_twins` (InnoDB) |
| `released_entity_relationship_snapshots` | M08, P02, RELEASE-OUTPUT | `released_entity_relationship_snapshots` (InnoDB) |
| `released_entity_twin_snapshots` | M08, P02, RELEASE-OUTPUT | `released_entity_twin_snapshots` (InnoDB) |
| `stakeholder_role_attributes` | P02 | `stakeholder_role_attributes` (InnoDB) |
| `stakeholder_role_profiles` | P02 | `stakeholder_role_profiles` (InnoDB) |

### `050_p03_catalogue_icp`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `customer_product_catalogue_status_transitions` | P03 | `customer_product_catalogue_status_transitions` (InnoDB) |
| `customer_product_catalogues` | M08, P03 | `customer_product_catalogues` (InnoDB) |
| `customer_products` | M08, P03 | `customer_products` (InnoDB) |
| `fit_hypotheses` | M08, P03 | `fit_hypotheses` (InnoDB) |
| `icp_profile_versions` | M08, P03 | `icp_profile_versions` (InnoDB) |
| `icp_profiles` | M08, P03 | `icp_profiles` (InnoDB) |
| `monitored_prospect_status_transitions` | P03 | `monitored_prospect_status_transitions` (InnoDB) |
| `monitored_prospects` | P03 | `monitored_prospects` (InnoDB) |
| `priority_outreach_queue_item_status_transitions` | P03 | `priority_outreach_queue_item_status_transitions` (InnoDB) |
| `priority_outreach_queue_items` | P03 | `priority_outreach_queue_items` (InnoDB) |
| `prospect_battlecard_briefs` | M12, P03 | `prospect_battlecard_briefs` (InnoDB) |
| `released_prospect_outputs` | M08, M12, P03, P04, P05 | `released_prospect_outputs` (InnoDB) |

### `060_p04_distribution`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `distribution_acknowledgements` | P04 | `distribution_acknowledgements` (InnoDB) |
| `distribution_routing_records` | P04 | `distribution_routing_records` (InnoDB) |
| `released_distribution_outputs` | P04 | `released_distribution_outputs` (InnoDB) |
| `role_distribution_briefing_status_transitions` | P04 | `role_distribution_briefing_status_transitions` (InnoDB) |
| `role_distribution_briefings` | M08, P04 | `role_distribution_briefings` (InnoDB) |
| `stakeholder_role_observation_status_transitions` | P04 | `stakeholder_role_observation_status_transitions` (InnoDB) |
| `stakeholder_role_observations` | P04 | `stakeholder_role_observations` (InnoDB) |

### `070_p05_marketing`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `marketing_asset_draft_status_transitions` | P05 | `marketing_asset_draft_status_transitions` (InnoDB) |
| `marketing_asset_drafts` | M08, P05 | `marketing_asset_drafts` (InnoDB) |
| `marketing_asset_variants` | P05 | `marketing_asset_variants` (InnoDB) |
| `marketing_claim_citations` | M08, P05 | `marketing_claim_citations` (InnoDB) |
| `released_marketing_assets` | P05 | `released_marketing_assets` (InnoDB) |

### `080_p07a_synthetic`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `action_execution_acknowledgements` | M09, M10 | `action_execution_acknowledgements` (InnoDB) |
| `approval_decisions` | M09, P07 | `approval_decisions` (InnoDB) |
| `approval_requests` | M09, M10, P07 | `approval_requests` (InnoDB) |
| `consent_registry` | M08, P07 | `consent_registry` (InnoDB) |
| `delivery_outcome_records` | P07 | `delivery_outcome_records` (InnoDB) |
| `execution_authorization_consumptions` | M09 | `execution_authorization_consumptions` (InnoDB) |
| `execution_authorizations` | M06, M09, M10 | `execution_authorizations` (InnoDB) |
| `frozen_action_artifacts` | M09 | `frozen_action_artifacts` (InnoDB) |
| `frozen_outreach_artifacts` | P07 | `frozen_outreach_artifacts` (InnoDB) |
| `outreach_channel_binding_status_transitions` | P07 | `outreach_channel_binding_status_transitions` (InnoDB) |
| `outreach_channel_bindings` | P07 | `outreach_channel_bindings` (InnoDB) |
| `outreach_execution_authorizations` | P07 | `outreach_execution_authorizations` (InnoDB) |
| `outreach_execution_proposal_status_transitions` | P07 | `outreach_execution_proposal_status_transitions` (InnoDB) |
| `outreach_execution_proposals` | M08, P07 | `outreach_execution_proposals` (InnoDB) |
| `recipient_pseudonym_mappings` | P07 | `recipient_pseudonym_mappings` (InnoDB) |

### `090_m15_ingestion`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `ingestion_admission_links` | INGESTION | `ingestion_admission_links` (InnoDB) |
| `ingestion_candidate_evidence_links` | INGESTION | `ingestion_candidate_evidence_links` (InnoDB) |
| `ingestion_candidate_status_transitions` | INGESTION | `ingestion_candidate_status_transitions` (InnoDB) |
| `ingestion_candidates` | INGESTION | `ingestion_candidates` (InnoDB) |
| `ingestion_capture_reservations` | INGESTION | `ingestion_capture_reservations` (InnoDB) |
| `ingestion_connector_descriptors` | INGESTION | `ingestion_connector_descriptors` (InnoDB) |
| `ingestion_entity_aliases` | INGESTION | `ingestion_entity_aliases` (InnoDB) |
| `ingestion_entity_resolution_attempts` | INGESTION | `ingestion_entity_resolution_attempts` (InnoDB) |
| `ingestion_fetch_plans` | INGESTION | `ingestion_fetch_plans` (InnoDB) |
| `ingestion_normalization_runs` | INGESTION | `ingestion_normalization_runs` (InnoDB) |
| `ingestion_raw_captures` | INGESTION | `ingestion_raw_captures` (InnoDB) |
| `ingestion_source_policies` | INGESTION | `ingestion_source_policies` (InnoDB) |

### `100_m16_release_output`

| Upstream table | Consuming module(s) | MariaDB mapping |
| --- | --- | --- |
| `release_artifact_evidence_links` | RELEASE-OUTPUT | `release_artifact_evidence_links` (InnoDB) |
| `release_artifact_status_transitions` | RELEASE-OUTPUT | `release_artifact_status_transitions` (InnoDB) |
| `release_artifacts` | M12, P01, RELEASE-OUTPUT | `release_artifacts` (InnoDB) |
| `release_lineage_events` | RELEASE-OUTPUT | `release_lineage_events` (InnoDB) |
| `release_source_use_profiles` | RELEASE-OUTPUT | `release_source_use_profiles` (InnoDB) |
| `release_visibility_activations` | M12, P01, RELEASE-OUTPUT | `release_visibility_activations` (InnoDB) |


---

## 3. MariaDB-specific translation rules

Applied uniformly; deviations are recorded per table in the migration files.

| Canonical (SQLite) | MariaDB | Rationale |
| --- | --- | --- |
| `TEXT` primary keys (UUID) | `CHAR(36)` `utf8mb4_bin` | fixed width, byte-exact comparison, index-friendly |
| `TEXT` free text | `VARCHAR(n)` where bounded, `TEXT`/`MEDIUMTEXT` where not | InnoDB index key limit is 3072 bytes |
| `TEXT` ISO-8601 timestamps | `CHAR(24)` `utf8mb4_bin`, canonical `YYYY-MM-DDTHH:MM:SS.sssZ` | preserves upstream string semantics and lexicographic ordering exactly; avoids `DATETIME` rounding and timezone reinterpretation |
| `INTEGER` 0/1 flags | `TINYINT(1) NOT NULL` + `CHECK (col IN (0,1))` | preserves CHECK-pinned invariants such as `external_side_effects_allowed = 0` |
| `CHECK (x IN (...))` enums | `CHECK (x IN (...))` **and** application-side validation | MariaDB 10.2+ enforces CHECK; the application layer duplicates it so the invariant holds even where a constraint is dropped by a migration tool |
| `UNIQUE` idempotency keys | `UNIQUE KEY` | preserved verbatim — these are the duplicate-release/duplicate-capture protections |
| implicit rowid ordering | explicit `(created_at, <pk>)` total order | closes the upstream TF7-09 `created_at DESC LIMIT 1` ambiguity in the ported selectors |
| no engine concept | `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` | transactions, FKs, row locking for job leases |
| FK enforcement optional | `FOREIGN KEY` always declared | tenant/workspace authority chains must not be application-only |

### Append-only protections

Upstream append-only ledgers (`*_status_transitions`, `*_status_history`, `decision_records`,
`evidence_objects`, released snapshots, `consent_registry`, audit ledgers) are enforced in MariaDB by:

- `BEFORE UPDATE` triggers that `SIGNAL SQLSTATE '45000'` on any attempt to mutate a frozen column;
- `BEFORE DELETE` triggers that `SIGNAL SQLSTATE '45000'` unconditionally;
- application-layer repositories that expose no update/delete method for those tables.

Both halves are required: the trigger is the storage pin, the repository is the reviewable surface.

### Tenant / workspace scope

Every tenant-owned table carries `tenant_id` and (where the upstream object is workspace-scoped)
`workspace_id` as `NOT NULL`, with a composite index leading on `(tenant_id, workspace_id)`. No
repository method issues `WHERE <pk> = ?` alone against a tenant-owned table; the scope columns are
part of every predicate unless the object is globally authoritative by design (`permissions`,
`roles`, `subscription_tiers`, `governed_tools`, `console_command_surface_definitions`).

---

## 4. Excluded adjacent tables

### 4.1 Excluded by owner gate (reachable, but not promoted upstream)

| Table | Reached via | Exclusion authority |
| --- | --- | --- |
| `offering_twins` | P02 | P02 cp11 gate — offering twins **EXCLUDED from approval scope** (V1-GAP-097); `PARTIAL_IMPLEMENTATION_NOT_PROMOTED` |
| `offering_versions` | P02 | same |
| `offering_claims` | P02 | same |
| `released_offering_twin_snapshots` | P02 | same |

The MVP products/offers authority is therefore the **P03 customer catalogue**
(`customer_product_catalogues` / `customer_products`), exactly as the historical MVP decision
recorded and as the current P02 gate still requires.

### 4.2 Excluded by non-reachability

Not referenced by any selected function, and no included table depends on them:

| Table family | Members |
| --- | --- |
| M13/M14 adaptive configuration | `adaptive_config_proposals`, `adaptive_config_versions`, `adaptive_config_proposal_decision_links`, `adaptive_config_proposal_evidence_links`, `compiled_config_bundles`, `config_closure_reports`, `config_resolver_results`, `config_rollback_plans` |
| Economy kernel projection | `economy_projection_profiles`, `economy_projection_profile_decision_links` |
| Trait inference | `trait_definitions`, `trait_inference_runs`, `trait_inference_run_inputs`, `trait_inference_run_outputs`, `trait_value_candidates`, `trait_value_decision_links`, `trait_value_evidence_links` |
| M15 entity anchoring | `entity_anchor_candidates`, `entity_anchor_evidence_links`, `entity_anchor_decision_links`, `entity_relation_anchor_candidates`, `entity_relation_anchor_evidence_links`, `entity_relation_anchor_decision_links` |
| Host session/event detail | `runtime_host_events`, `runtime_host_session_refs`, `session_context_references` |
| Other | `console_object_detail_projections`, `customer_product_status_transitions`, `proposal_relation_links`, `valkyrie_inventory_snapshots`, `valkyrie_runtime_bindings`, `schema_versions` |

`schema_versions` is replaced by this repository's own `schema_migrations` ledger — the projection
tracks *its* migration state, not upstream's baseline numbering.

`customer_product_status_transitions` is excluded as unreached at the pinned SHA; if a ported P03
path is found to need it, it must be added by an explicit migration with the consuming function
named, not by assumption.

### 4.3 Excluded whole families

`sev_*`, `gsmc_*`, `polymorphic-db`, PromptOps, meta-validation, MCM-HMWH **diagnostic**
(`src/mcm-hmwh-mvp-*.ts` — distinct from the M07 HMWH Council, which is included), and the MVP
pilot/road-test ceremony machinery contribute no tables to this projection.

---

## 5. Verification obligation

`tests/integration/` must prove, against real MariaDB:

1. every migration applies cleanly from empty and is idempotent;
2. every CHECK-pinned invariant rejects its violating value;
3. every append-only trigger rejects UPDATE and DELETE;
4. every FK rejects an orphan;
5. cross-tenant and cross-workspace reads return nothing;
6. every unique/idempotency key rejects its duplicate;
7. job leases are exclusive under concurrent claim.

A migration without its constraint test is not conformed.
