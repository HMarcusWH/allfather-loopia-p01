-- 900_deferred_foreign_keys
--
-- Derived from the canonical SQLite schema at
-- packages/allfather-control-substrate/src/db/schema.ts @ c6d9cf9a7a1001b001ca6118999e9cb75c831c4d
-- Translation rules: docs/SCHEMA_REACHABILITY.md §3. Reachability: §2.
--
-- Structure and invariants are canonical authority; the SQL itself is derived, not copied.


-- Foreign keys whose target table is created in a later migration (or which are
-- self-references). Deferring them keeps every earlier migration applicable on its own
-- against a fresh database, rather than requiring a whole-schema apply to succeed.

ALTER TABLE arbiter_policy_sets ADD CONSTRAINT fk_arbiter_policy_sets_supersedes_policy_set_id FOREIGN KEY (supersedes_policy_set_id) REFERENCES arbiter_policy_sets (arbiter_policy_set_id);

ALTER TABLE artifact_integrity_verifications ADD CONSTRAINT fk_artifact_integrity_verifications_artifact_id FOREIGN KEY (artifact_id) REFERENCES frozen_action_artifacts (artifact_id);

ALTER TABLE capability_contracts ADD CONSTRAINT fk_capability_contracts_supersedes_contract_id FOREIGN KEY (supersedes_contract_id) REFERENCES capability_contracts (capability_contract_id);

ALTER TABLE customer_output_projections ADD CONSTRAINT fk_customer_output_projections_release_artifact_id FOREIGN KEY (release_artifact_id) REFERENCES release_artifacts (release_artifact_id);

ALTER TABLE data_scopes ADD CONSTRAINT fk_data_scopes_capability_contract_id FOREIGN KEY (capability_contract_id) REFERENCES capability_contracts (capability_contract_id);

ALTER TABLE decision_records ADD CONSTRAINT fk_decision_records_supersedes_or_corrects_decision_record_id FOREIGN KEY (supersedes_or_corrects_decision_record_id) REFERENCES decision_records (decision_record_id);

ALTER TABLE evidence_bundles ADD CONSTRAINT fk_evidence_bundles_supersedes_bundle_id FOREIGN KEY (supersedes_bundle_id) REFERENCES evidence_bundles (evidence_bundle_id);

ALTER TABLE evidence_objects ADD CONSTRAINT fk_evidence_objects_supersedes_evidence_id FOREIGN KEY (supersedes_evidence_id) REFERENCES evidence_objects (evidence_id);

ALTER TABLE hmwh_review_sessions ADD CONSTRAINT fk_hmwh_review_sessions_evidence_bundle_id FOREIGN KEY (evidence_bundle_id) REFERENCES evidence_bundles (evidence_bundle_id);

ALTER TABLE missions ADD CONSTRAINT fk_missions_supersedes_mission_id FOREIGN KEY (supersedes_mission_id) REFERENCES missions (mission_id);

ALTER TABLE output_access_rules ADD CONSTRAINT fk_output_access_rules_supersedes_output_access_rule_id FOREIGN KEY (supersedes_output_access_rule_id) REFERENCES output_access_rules (output_access_rule_id);

ALTER TABLE proposal_envelopes ADD CONSTRAINT fk_proposal_envelopes_supersedes_proposal_id FOREIGN KEY (supersedes_proposal_id) REFERENCES proposal_envelopes (proposal_id);

ALTER TABLE release_artifacts ADD CONSTRAINT fk_release_artifacts_supersedes_release_artifact_id FOREIGN KEY (supersedes_release_artifact_id) REFERENCES release_artifacts (release_artifact_id);

ALTER TABLE released_entity_relationship_snapshots ADD CONSTRAINT fk_released_entity_relationship_snapshots_supersedes_release_id FOREIGN KEY (supersedes_release_id) REFERENCES released_entity_relationship_snapshots (released_relationship_snapshot_id);

ALTER TABLE released_entity_twin_snapshots ADD CONSTRAINT fk_released_entity_twin_snapshots_supersedes_release_id FOREIGN KEY (supersedes_release_id) REFERENCES released_entity_twin_snapshots (released_entity_twin_snapshot_id);

ALTER TABLE runtime_host_bindings ADD CONSTRAINT fk_runtime_host_bindings_supersedes_binding_id FOREIGN KEY (supersedes_binding_id) REFERENCES runtime_host_bindings (binding_id);
