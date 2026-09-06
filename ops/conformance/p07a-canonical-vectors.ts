// Generate the P07-A cross-runtime conformance vectors by running the CANONICAL TypeScript.
//
// This file lives in the PROJECTION repository and imports the canonical package by path. It does
// not modify canonical, and canonical does not know it exists.
//
// WHAT IT DOES. Opens a real canonical SQLite database, drives a real outreach through the real
// canonical governed path to an ISSUED single-use authorization, then calls the REAL
// `dispatchOutreachSynthetic` — the same function the PHP port projects — and dumps what the M10
// ledger actually recorded: the decision record's own fields, its input references, and the
// `record_payload_digest` the canonical encoder produced.
//
// WHY IT RUNS CANONICAL RATHER THAN DESCRIBING IT. A vector hand-copied from reading the source
// proves the reader's understanding, not the runtime's behaviour, and it agrees with whatever the
// reader also wrote on the PHP side. The digest below comes out of
// `packages/allfather-substrate-core/src/digest.ts` via `computeRecordPayloadDigest` in
// `packages/allfather-control-substrate/src/m10/ledger.ts`, with no PHP involved at any point.
//
// The object ids are randomly generated per run and therefore cannot be pinned. The DIGEST is
// pinned, and the conformance test recomputes it after substituting its own run's ids into the
// canonical field shape — so a divergence in any NON-id field (the summary wording, a reason code,
// an object type's casing, a reference role, a stray version digest, severity, the incident flag,
// an extra proposal id) changes the digest and fails.
//
// Usage, from the canonical checkout so the workspace packages resolve:
//   cd <canonical> && node --import tsx <projection>/ops/conformance/p07a-canonical-vectors.ts

import { openAfbDatabase } from "../../../Allfatherbusinessintelligence/packages/allfather-control-substrate/src/db/open.js";
import {
  computeRecordPayloadDigest,
} from "../../../Allfatherbusinessintelligence/packages/allfather-control-substrate/src/m10/ledger.js";
import {
  dispatchOutreachSynthetic,
  type SyntheticDeliveryOutcome,
} from "../../../Allfatherbusinessintelligence/packages/allfather-control-substrate/src/p07/outreach-delivery.js";
import {
  driveOutreachToAuthorized,
  p07Scaffold,
} from "../../../Allfatherbusinessintelligence/packages/allfather-control-substrate/src/p07/test-support.js";

type Row = Record<string, unknown>;

const OUTCOMES: readonly SyntheticDeliveryOutcome[] = ["DELIVERED", "FAILED", "UNKNOWN"];

const transcripts = OUTCOMES.map((simulatedOutcome) => {
  const afb = openAfbDatabase();
  const scaffold = p07Scaffold(afb);
  const authorized = driveOutreachToAuthorized(afb, scaffold);

  const dispatched = dispatchOutreachSynthetic(afb.db, {
    tenantId: scaffold.scope.tenantId,
    workspaceId: scaffold.scope.workspaceId,
    outreachExecutionAuthorizationId: authorized.outreachExecutionAuthorizationId,
    observedPayloadDigest: authorized.payloadDigest,
    simulatedOutcome,
  });
  if (dispatched.outcome !== "DISPATCHED") {
    throw new Error(`canonical dispatch did not succeed: ${JSON.stringify(dispatched)}`);
  }

  const record = afb.db
    .prepare(
      `SELECT record_type, materiality_class, primary_outcome_status, mission_id, proposal_id,
              proposal_submission_digest, correlation_id, safe_decision_summary, reason_codes,
              uncertainty_and_limitation_summary, severity, incident_evaluation_required,
              supersedes_or_corrects_decision_record_id, current_reliance_status,
              record_sequence_number, record_payload_digest, recorded_by_service_identity_id
       FROM decision_records WHERE decision_record_id = ?`,
    )
    .get(dispatched.decisionRecordId) as Row;

  const references = afb.db
    .prepare(
      `SELECT owner_module_id, input_object_type, input_object_id, input_object_version_or_digest,
              reference_role, verification_status_at_record_time
       FROM decision_input_references WHERE decision_record_id = ?
       ORDER BY reference_role`,
    )
    .all(dispatched.decisionRecordId) as Row[];

  const delivery = afb.db
    .prepare(
      `SELECT delivery_outcome, outcome_detail_safe FROM delivery_outcome_records
       WHERE delivery_outcome_record_id = ?`,
    )
    .get(dispatched.deliveryOutcomeRecordId) as Row;

  const authorizationStatus = afb.db
    .prepare(
      `SELECT authorization_status FROM outreach_execution_authorizations
       WHERE outreach_execution_authorization_id = ?`,
    )
    .get(authorized.outreachExecutionAuthorizationId) as Row;

  const artifactStatus = afb.db
    .prepare(
      `SELECT artifact_status FROM frozen_outreach_artifacts WHERE frozen_outreach_artifact_id = ?`,
    )
    .get(authorized.frozenOutreachArtifactId) as Row;

  // The SAME encoder the append used, re-run over the stored fields. If this disagrees with the
  // stored digest the canonical runtime itself is inconsistent, and the vector is worthless.
  const recomputed = computeRecordPayloadDigest(
    {
      recordType: record.record_type as never,
      materialityClass: record.materiality_class as never,
      primaryOutcomeStatus: record.primary_outcome_status as never,
      missionId: record.mission_id as string | null,
      proposalId: record.proposal_id as string | null,
      proposalSubmissionDigest: record.proposal_submission_digest as string | null,
      correlationId: record.correlation_id as string | null,
      safeDecisionSummary: record.safe_decision_summary as string,
      reasonCodes: JSON.parse(record.reason_codes as string) as string[],
      uncertaintyAndLimitationSummary: record.uncertainty_and_limitation_summary as string | null,
      severity: record.severity as never,
      incidentEvaluationRequired: Boolean(record.incident_evaluation_required),
      supersedesOrCorrectsDecisionRecordId:
        record.supersedes_or_corrects_decision_record_id as string | null,
      currentRelianceStatus: record.current_reliance_status as never,
      recordSequenceNumber: record.record_sequence_number as number,
    },
    references.map((r) => ({
      ownerModuleId: r.owner_module_id as never,
      inputObjectType: r.input_object_type as string,
      inputObjectId: r.input_object_id as string,
      inputObjectVersionOrDigest: r.input_object_version_or_digest as string | null,
      referenceRole: r.reference_role as never,
      verificationStatusAtRecordTime: r.verification_status_at_record_time as never,
    })),
  );
  if (recomputed !== record.record_payload_digest) {
    throw new Error("canonical stored digest does not match the canonical encoder");
  }

  // THE IDENTIFIERS ARE PER-RUN, so a digest over them cannot be pinned. Substitute FIXED ids and a
  // FIXED sequence number into the canonical field shape and re-run the canonical encoder over that.
  // The result IS pinnable, and it is still a canonical value: it comes out of canonical's
  // `computeRecordPayloadDigest`, over the field shape canonical's `dispatchOutreachSynthetic` just
  // produced, with nothing hand-written in between.
  const FIXED: Record<string, string> = {
    VERDICT_BASIS: "00000000-0000-4000-8000-0000000000v1",
    APPROVAL_BASIS: "00000000-0000-4000-8000-0000000000a1",
    AUTHORIZATION_BASIS: "00000000-0000-4000-8000-0000000000z1",
    ARTIFACT_BASIS: "00000000-0000-4000-8000-0000000000f1",
    OUTCOME_BASIS: "00000000-0000-4000-8000-0000000000d1",
  };
  const FIXED_SEQUENCE = 7;

  const fixedReferences = references.map((r) => ({
    ownerModuleId: r.owner_module_id as never,
    inputObjectType: r.input_object_type as string,
    inputObjectId: FIXED[r.reference_role as string] as string,
    inputObjectVersionOrDigest: r.input_object_version_or_digest as string | null,
    referenceRole: r.reference_role as never,
    verificationStatusAtRecordTime: r.verification_status_at_record_time as never,
  }));
  const pinnedDigest = computeRecordPayloadDigest(
    {
      recordType: record.record_type as never,
      materialityClass: record.materiality_class as never,
      primaryOutcomeStatus: record.primary_outcome_status as never,
      missionId: record.mission_id as string | null,
      proposalId: record.proposal_id as string | null,
      proposalSubmissionDigest: record.proposal_submission_digest as string | null,
      correlationId: record.correlation_id as string | null,
      safeDecisionSummary: record.safe_decision_summary as string,
      reasonCodes: JSON.parse(record.reason_codes as string) as string[],
      uncertaintyAndLimitationSummary: record.uncertainty_and_limitation_summary as string | null,
      severity: record.severity as never,
      incidentEvaluationRequired: Boolean(record.incident_evaluation_required),
      supersedesOrCorrectsDecisionRecordId:
        record.supersedes_or_corrects_decision_record_id as string | null,
      currentRelianceStatus: record.current_reliance_status as never,
      recordSequenceNumber: FIXED_SEQUENCE,
    },
    fixedReferences,
  );

  return {
    simulated_outcome: simulatedOutcome,
    dispatch_outcome: dispatched.outcome,
    authorization_status_after: authorizationStatus.authorization_status,
    artifact_status_after: artifactStatus.artifact_status,
    delivery_outcome: delivery.delivery_outcome,
    record: {
      record_type: record.record_type,
      materiality_class: record.materiality_class,
      primary_outcome_status: record.primary_outcome_status,
      mission_id: record.mission_id,
      proposal_id: record.proposal_id,
      proposal_submission_digest: record.proposal_submission_digest,
      correlation_id: record.correlation_id,
      safe_decision_summary: record.safe_decision_summary,
      reason_codes: JSON.parse(record.reason_codes as string),
      uncertainty_and_limitation_summary: record.uncertainty_and_limitation_summary,
      severity: record.severity,
      incident_evaluation_required: Number(record.incident_evaluation_required),
      supersedes_or_corrects_decision_record_id:
        record.supersedes_or_corrects_decision_record_id,
      current_reliance_status: record.current_reliance_status,
    },
    recorded_by_service_identity_id: record.recorded_by_service_identity_id,
    // Every reference, keyed by role. Order is not part of the digest — canonical sorts them — so
    // the fixture states the SET and the PHP side is free to build it in its own order.
    references_by_role: Object.fromEntries(
      references.map((r) => [
        r.reference_role as string,
        {
          owner_module_id: r.owner_module_id,
          input_object_type: r.input_object_type,
          input_object_version_or_digest: r.input_object_version_or_digest,
          verification_status_at_record_time: r.verification_status_at_record_time,
        },
      ]),
    ),
    fixed_reference_ids: FIXED,
    fixed_record_sequence_number: FIXED_SEQUENCE,
    // THE PINNED VALUE. Reproduced by the PHP encoder over the same fixed shape, or the two
    // runtimes do not agree about what this record IS.
    pinned_record_payload_digest: pinnedDigest,
  };
});

process.stdout.write(
  JSON.stringify(
    {
      generated_from: "HMarcusWH/Allfatherbusinessintelligence",
      canonical_head: process.env.CANONICAL_HEAD ?? "unset",
      canonical_source: "packages/allfather-control-substrate/src/p07/outreach-delivery.ts",
      encoder: "packages/allfather-substrate-core/src/digest.ts via m10/ledger.ts computeRecordPayloadDigest",
      note:
        "Produced by RUNNING canonical dispatchOutreachSynthetic against a real canonical SQLite " +
        "database. No PHP participated in producing any value in this file.",
      transcripts,
    },
    null,
    2,
  ) + "\n",
);
