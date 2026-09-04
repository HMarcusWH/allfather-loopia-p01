-- 110_jobs_scheduler
--
-- Loopia-specific. This group has NO canonical upstream counterpart: upstream runs governed work
-- in-process under Node, while Loopia shared hosting offers no daemon, no queue broker and no
-- shell cron — only a scheduler that can request a URL.
--
-- The replacement is a durable, DB-backed finite-state job machine. Each scheduler request claims
-- a bounded number of eligible jobs under a lease, performs bounded work, commits intermediate
-- state and exits. Nothing assumes a long-running process.
--
-- The state machine is TYPED, not a generic executor: `job_type` and `state` are CHECK-pinned, and
-- the scheduler endpoint accepts no work definition from the caller. A scheduler URL that could be
-- handed an arbitrary payload would be a public execution API.

CREATE TABLE IF NOT EXISTS jobs (
  job_id              CHAR(36) NOT NULL,
  schema_version      VARCHAR(32) NOT NULL DEFAULT '1',
  tenant_id           CHAR(36) NOT NULL,
  workspace_id        CHAR(36) NOT NULL,
  job_type            VARCHAR(48) NOT NULL,
  state               VARCHAR(32) NOT NULL,
  subject_kind        VARCHAR(48) NOT NULL,
  subject_id          CHAR(36) NOT NULL,
  -- The idempotency key makes a retried claim converge on the SAME job rather than creating a
  -- second one. Duplicate capture and duplicate release are the two failure modes the whole
  -- scheduler design exists to prevent.
  idempotency_key     VARCHAR(191) NOT NULL,
  attempt_count       INT NOT NULL DEFAULT 0,
  max_attempts        INT NOT NULL DEFAULT 3,
  run_after           CHAR(24) NOT NULL,
  lease_owner         VARCHAR(191),
  lease_expires_at    CHAR(24),
  last_error_code     VARCHAR(191),
  last_error_at       CHAR(24),
  -- Correlates every record this job produced, so a replay can find them without guessing.
  correlation_id      CHAR(36) NOT NULL,
  created_at          CHAR(24) NOT NULL,
  updated_at          CHAR(24) NOT NULL,
  PRIMARY KEY (job_id),
  CHECK (job_type IN (
    'P01_SOURCE_MONITOR',
    'M15_INGESTION_CAPTURE',
    'M11_AUDIT_RUN',
    'M16_PROJECTION_REFRESH'
  )),
  -- The conceptual source-monitoring lifecycle, plus the two terminal states. A job never leaves
  -- this set; there is no free-form status column.
  CHECK (state IN (
    'PENDING',
    'PREFLIGHT',
    'FETCH',
    'CAPTURE',
    'DIFF',
    'ANALYZE',
    'ADJUDICATE',
    'RELEASE',
    'VISIBLE',
    'FAILED',
    'ABANDONED'
  )),
  CHECK (subject_kind IN ('MONITORED_SOURCE', 'INGESTION_SOURCE_POLICY', 'AUDIT_PROFILE', 'RELEASE_ARTIFACT')),
  CHECK (attempt_count >= 0),
  CHECK (max_attempts >= 1),
  CONSTRAINT fk_jobs_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT fk_jobs_workspace_id FOREIGN KEY (workspace_id) REFERENCES workspaces (workspace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One live job per (tenant, workspace, type, subject, idempotency key). This is the duplicate-work
-- pin: a re-fired scheduler request cannot create a second job for the same unit of work.
CREATE UNIQUE INDEX ux_jobs_idempotency
  ON jobs (tenant_id, workspace_id, job_type, subject_id, idempotency_key);

-- The claim query's index: eligible jobs ordered by when they became runnable.
CREATE INDEX ix_jobs_claimable ON jobs (state, run_after, lease_expires_at);
CREATE INDEX ix_jobs_scope ON jobs (tenant_id, workspace_id, state);

-- Append-only transition ledger. Every state change is recorded with the actor and reason, so a
-- job's whole history is reconstructable and a stuck job is diagnosable without live logs.
CREATE TABLE IF NOT EXISTS job_transitions (
  job_transition_id CHAR(36) NOT NULL,
  schema_version    VARCHAR(32) NOT NULL DEFAULT '1',
  job_id            CHAR(36) NOT NULL,
  from_state        VARCHAR(32),
  to_state          VARCHAR(32) NOT NULL,
  attempt_number    INT NOT NULL,
  reason_code       VARCHAR(191),
  detail_safe       TEXT,
  lease_owner       VARCHAR(191),
  transitioned_at   CHAR(24) NOT NULL,
  PRIMARY KEY (job_transition_id),
  CONSTRAINT fk_job_transitions_job_id FOREIGN KEY (job_id) REFERENCES jobs (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_job_transitions_job ON job_transitions (job_id, transitioned_at);

DROP TRIGGER IF EXISTS trg_job_transitions_no_update;
DELIMITER $$
CREATE TRIGGER trg_job_transitions_no_update
  BEFORE UPDATE ON job_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'job_transitions is append-only';
END$$
DELIMITER ;

DROP TRIGGER IF EXISTS trg_job_transitions_no_delete;
DELIMITER $$
CREATE TRIGGER trg_job_transitions_no_delete
  BEFORE DELETE ON job_transitions
  FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'job_transitions is append-only';
END$$
DELIMITER ;

-- One row per scheduler HTTP invocation. Bounds are recorded per run so an operator can prove the
-- endpoint stayed inside them, and so an abusive or looping caller is visible.
CREATE TABLE IF NOT EXISTS scheduler_runs (
  scheduler_run_id  CHAR(36) NOT NULL,
  schema_version    VARCHAR(32) NOT NULL DEFAULT '1',
  invoked_at        CHAR(24) NOT NULL,
  completed_at      CHAR(24),
  jobs_claimed      INT NOT NULL DEFAULT 0,
  jobs_advanced     INT NOT NULL DEFAULT 0,
  jobs_failed       INT NOT NULL DEFAULT 0,
  leases_recovered  INT NOT NULL DEFAULT 0,
  budget_ms         INT NOT NULL,
  elapsed_ms        INT,
  outcome           VARCHAR(32) NOT NULL,
  -- The caller is recorded by a coarse fingerprint, never by a credential.
  caller_fingerprint VARCHAR(64),
  PRIMARY KEY (scheduler_run_id),
  CHECK (outcome IN ('RUNNING', 'COMPLETED', 'BUDGET_EXHAUSTED', 'NO_WORK', 'REFUSED_UNAUTHENTICATED', 'REFUSED_RATE_LIMITED', 'FAILED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_scheduler_runs_invoked ON scheduler_runs (invoked_at);
