<?php

declare(strict_types=1);

namespace Allfather\Loopia\Ops;

use Allfather\Loopia\Core\Clock;

/**
 * The deployment evidence registry: the only thing that may make an operational claim true.
 *
 * THE PROBLEM IT SOLVES. `upstream.lock.json` carries ten booleans, and until now the foundation
 * suite treated all ten identically — every one had to stay false forever. That was correct while
 * nothing had been deployed, and it becomes dishonest the moment real staging work begins, because
 * two genuinely different kinds of statement were being held by one rule:
 *
 *   AN AUTHORITY LOCK is an owner act. `production_launch_authorized`, `external_action_authorized`,
 *   `owner_go_granted` and `customer_visible_output_authorized` are decisions a person makes and
 *   records upstream. No amount of evidence collected here can make one true, so evidence has no
 *   opinion about them and this class deliberately cannot express one.
 *
 *   AN OPERATIONAL STATE is an observation. "Staging is deployed" and "the host was qualified" are
 *   claims about the world that either happened or did not, and the honest way to hold them is to
 *   require the evidence rather than to forbid the claim. Forbidding it forever means the day the
 *   deployment actually happens, somebody has to edit a tripwire to tell the truth — and a tripwire
 *   that must be edited to record reality is a tripwire nobody will trust the next time.
 *
 * So the five operational states become EVIDENCE-BACKED: each may be true, and only while a record
 * in `deployment-evidence.json` supports it. `tests/foundation/deployment_evidence_boundary.php`
 * asserts the implication in the only direction that matters — a claim that is true must be
 * supported — so nothing has to change on the day one of them legitimately flips.
 *
 * WHY A REVIEWED JSON FILE AND NOT A TABLE. The deployment whose evidence matters most is the one
 * where the database is what failed, and a registry that needed a migration to record "the host was
 * qualified" would need the host to work in order to say the host works. A file in the repository
 * is also reviewed: adding a PASS record is a diff somebody reads, which is exactly the property a
 * claim of this weight should have. Nothing in the running application writes it —
 * `tests/foundation/no_external_action_surface.php` proves that the same way it proves nothing
 * writes the lock file.
 *
 * WHAT IT IS NOT. It is not an audit ledger, it grants no authority, it activates nothing, and a
 * record in it is a POINTER to evidence rather than the evidence itself: `evidence_ref` names where
 * the artifact was filed and `evidence_sha256` pins which bytes were meant. A registry that carried
 * the payloads would be a second evidence store competing with M05, which is the one this
 * projection already has for governed evidence.
 *
 * EXECUTION IS NOT SUCCESS. `road_test_001_executed` is supported by a ROAD_TEST_001 record of ANY
 * result, including FAIL — because "we ran one" and "it passed" are different facts and collapsing
 * them is how a failed road test becomes a green flag. Every other claim requires PASS.
 */
final class DeploymentEvidence
{
    /** The registry's own schema version. Bumped explicitly, never reinterpreted. */
    public const SCHEMA_VERSION = '1.0';

    /**
     * The closed set of evidence kinds. Alphabetical, so an addition is a visible diff rather than
     * an append nobody reads.
     *
     * @var list<string>
     */
    public const KINDS = [
        'CONFORMANCE',
        'CROSS_SITE_ISOLATION',
        'LIVE_READ_ACTIVATION',
        'LIVE_READ_STAND_DOWN',
        'LOOPIA_PROBE_001',
        'ROAD_TEST_001',
        'ROLLBACK_DRILL',
        'STAGING_DEPLOYMENT',
        'STAGING_SMOKE',
    ];

    /**
     * The closed set of results.
     *
     * `STAND_DOWN` is neither a pass nor a failure: it records that an activity was deliberately
     * withdrawn. It supports no claim, and that is the point — a stand-down must not read as a
     * quiet pass, and it must not read as a fault either.
     *
     * @var list<string>
     */
    public const RESULTS = ['PASS', 'FAIL', 'STAND_DOWN'];

    /**
     * Where a record was produced.
     *
     * `ci` is here and is NOT one of `Bootstrap::ENVIRONMENTS`, deliberately. A GitHub-hosted
     * runner is not a host claim: it proves the code, never Loopia. Keeping it in the vocabulary
     * means a CI-produced record can be FILED without being mistakable for a host record, and
     * `supportsHostClaim()` is what refuses to let it qualify one.
     *
     * @var list<string>
     */
    public const ENVIRONMENTS = ['ci', 'development', 'testing', 'staging', 'production'];

    /**
     * Environments whose records may support a claim about the real deployment target.
     *
     * @var list<string>
     */
    public const HOST_ENVIRONMENTS = ['staging', 'production'];

    /**
     * The five claims this registry can support, and the only five.
     *
     * @var list<string>
     */
    public const EVIDENCE_BACKED_CLAIMS = [
        'conformance_complete',
        'live_read_activated',
        'loopia_capability_validated',
        'road_test_001_executed',
        'staging_deployed',
    ];

    /**
     * The four an owner grants and evidence cannot. Named here so the distinction lives in one
     * place rather than being re-derived by each test that needs it.
     *
     * @var list<string>
     */
    public const PERMANENT_AUTHORITY_LOCKS = [
        'customer_visible_output_authorized',
        'external_action_authorized',
        'owner_go_granted',
        'production_launch_authorized',
    ];

    /** @var list<string> */
    public const REQUIRED_TOP_LEVEL = ['schema_version', 'projection_id', 'canonical_pin', 'records'];

    /** @var list<string> */
    public const REQUIRED_RECORD_FIELDS = [
        'record_id',
        'kind',
        'result',
        'release_ref',
        'environment',
        'recorded_at',
        'evidence_ref',
        'evidence_sha256',
    ];

    /**
     * The only fields a record may carry beyond the required ones.
     *
     * `target` names the host or site a host-specific record speaks about. `note` is one short,
     * printable line for the human reading the diff — never a payload, never a reason code, and
     * never somewhere a secret could be parked, which is why its character set is restricted.
     *
     * @var list<string>
     */
    public const OPTIONAL_RECORD_FIELDS = ['target', 'note'];

    /** @param list<array<string, mixed>> $records @param list<string> $problems */
    private function __construct(
        private readonly array $records,
        private readonly array $problems,
    ) {
    }

    /**
     * Read and validate a registry file against the identity the lock file declares.
     *
     * A MISSING FILE IS A PROBLEM, not an empty registry. An absent registry that read as "no
     * evidence" would make every unsupported claim pass its own check by deleting the thing that
     * checks it.
     *
     * @param array{projection_id: string, canonical_pin: string} $expected
     */
    public static function loadFile(string $path, array $expected): self
    {
        if (!is_file($path)) {
            return new self([], ["evidence registry is missing: {$path}"]);
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return new self([], ["evidence registry is unreadable: {$path}"]);
        }

        return self::parse($raw, $expected);
    }

    /**
     * Validate registry JSON. Collects EVERY problem rather than throwing on the first.
     *
     * An operator fixing a registry wants the whole list; a validator that stops at the first fault
     * turns one review into five.
     *
     * @param array{projection_id: string, canonical_pin: string} $expected
     */
    public static function parse(string $json, array $expected): self
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            return new self([], ['evidence registry is not valid JSON: ' . $error->getMessage()]);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return new self([], ['evidence registry must be a JSON object']);
        }

        $problems = [];

        $actualKeys = array_keys($decoded);
        sort($actualKeys, SORT_STRING);
        $wantedKeys = self::REQUIRED_TOP_LEVEL;
        sort($wantedKeys, SORT_STRING);
        if ($actualKeys !== $wantedKeys) {
            $problems[] = 'evidence registry keys must be exactly ' . implode(', ', $wantedKeys)
                . ' (found ' . implode(', ', $actualKeys) . ')';
        }

        if (($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $problems[] = 'evidence registry schema_version must be ' . self::SCHEMA_VERSION;
        }
        if (($decoded['projection_id'] ?? null) !== $expected['projection_id']) {
            $problems[] = 'evidence registry projection_id must match the lock: ' . $expected['projection_id'];
        }
        // THE PIN TIES THE REGISTRY TO THE PROJECTION IT SPEAKS FOR. Evidence collected against one
        // canonical pin says nothing about a projection re-pinned to another, and a registry that
        // silently carried over would be the quietest way to inherit a claim.
        if (($decoded['canonical_pin'] ?? null) !== $expected['canonical_pin']) {
            $problems[] = 'evidence registry canonical_pin must match upstream.lock.json '
                . 'projection_pinned_head';
        }

        $rawRecords = $decoded['records'] ?? null;
        if (!is_array($rawRecords) || !array_is_list($rawRecords)) {
            return new self([], [...$problems, 'evidence registry records must be a JSON array']);
        }

        $records = [];
        $seenIds = [];
        foreach ($rawRecords as $index => $record) {
            $recordProblems = self::validateRecord($record, $index, $seenIds);
            if ($recordProblems !== []) {
                $problems = [...$problems, ...$recordProblems];
                continue;
            }
            /** @var array<string, mixed> $record */
            $seenIds[(string) $record['record_id']] = true;
            $records[] = $record;
        }

        return new self($records, $problems);
    }

    /**
     * @param array<string, bool> $seenIds
     * @return list<string>
     */
    private static function validateRecord(mixed $record, int $index, array $seenIds): array
    {
        $at = "records[{$index}]";
        if (!is_array($record) || array_is_list($record)) {
            return ["{$at} must be a JSON object"];
        }

        $problems = [];
        foreach (self::REQUIRED_RECORD_FIELDS as $field) {
            if (!array_key_exists($field, $record)) {
                $problems[] = "{$at} is missing required field {$field}";
            }
        }
        foreach (array_keys($record) as $field) {
            if (
                !in_array((string) $field, self::REQUIRED_RECORD_FIELDS, true)
                && !in_array((string) $field, self::OPTIONAL_RECORD_FIELDS, true)
            ) {
                // UNKNOWN FIELDS ARE REFUSED, not ignored. A misspelled `result` that fell through
                // as an extra field would leave the record with no result at all, and a registry
                // that tolerated it would be answering a question nobody asked.
                $problems[] = "{$at} carries unknown field {$field}";
            }
        }
        if ($problems !== []) {
            return $problems;
        }

        $id = $record['record_id'];
        if (!is_string($id) || preg_match('/^[A-Z0-9][A-Z0-9_-]{2,63}$/D', $id) !== 1) {
            $problems[] = "{$at}.record_id must be 3-64 characters of A-Z, 0-9, _ or -";
        } elseif (isset($seenIds[$id])) {
            $problems[] = "{$at}.record_id is a duplicate: {$id}";
        }

        if (!is_string($record['kind']) || !in_array($record['kind'], self::KINDS, true)) {
            $problems[] = "{$at}.kind must be one of " . implode(', ', self::KINDS);
        }
        if (!is_string($record['result']) || !in_array($record['result'], self::RESULTS, true)) {
            $problems[] = "{$at}.result must be one of " . implode(', ', self::RESULTS);
        }
        if (!is_string($record['environment']) || !in_array($record['environment'], self::ENVIRONMENTS, true)) {
            $problems[] = "{$at}.environment must be one of " . implode(', ', self::ENVIRONMENTS);
        }
        if (!is_string($record['release_ref']) || preg_match('/^[0-9a-f]{40}$/D', $record['release_ref']) !== 1) {
            // The FULL sha, never an abbreviation. An abbreviated ref is ambiguous the day the
            // repository grows a colliding prefix, and evidence is exactly the wrong place to
            // discover that.
            $problems[] = "{$at}.release_ref must be a full 40-character lowercase git SHA";
        }
        if (!is_string($record['recorded_at']) || !Clock::isCanonical($record['recorded_at'])) {
            $problems[] = "{$at}.recorded_at must be a canonical UTC timestamp (YYYY-MM-DDTHH:MM:SS.sssZ)";
        }
        if (!is_string($record['evidence_sha256']) || preg_match('/^[0-9a-f]{64}$/D', $record['evidence_sha256']) !== 1) {
            $problems[] = "{$at}.evidence_sha256 must be a 64-character lowercase sha256 digest";
        }
        $problems = [...$problems, ...self::validateEvidenceRef($record['evidence_ref'], $at)];

        if (array_key_exists('target', $record)) {
            $target = $record['target'];
            if (!is_string($target) || preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/D', $target) !== 1) {
                $problems[] = "{$at}.target must be a lowercase host name";
            }
        }
        if (array_key_exists('note', $record)) {
            $note = $record['note'];
            if (!is_string($note) || $note === '' || strlen($note) > 200) {
                $problems[] = "{$at}.note must be a non-empty string of at most 200 characters";
            } elseif (preg_match('/^[\x20-\x7E]+$/D', $note) !== 1) {
                $problems[] = "{$at}.note must be printable ASCII on one line";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function validateEvidenceRef(mixed $ref, string $at): array
    {
        if (!is_string($ref) || $ref === '' || strlen($ref) > 200) {
            return ["{$at}.evidence_ref must be a non-empty relative path of at most 200 characters"];
        }
        // A REFERENCE, NEVER A LOCATION TO FETCH. No scheme, no absolute path, no traversal — the
        // ref names where an operator filed the artifact, and a validator that accepted a URL would
        // be inviting something later to go and get it.
        if (
            str_contains($ref, '://')
            || str_starts_with($ref, '/')
            || str_contains($ref, '\\')
            || str_contains($ref, "\0")
        ) {
            return ["{$at}.evidence_ref must be a local POSIX-style relative path"];
        }
        foreach (explode('/', $ref) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return ["{$at}.evidence_ref contains an unsafe path segment"];
            }
        }
        if (preg_match('#^[A-Za-z0-9._/-]+$#D', $ref) !== 1) {
            return ["{$at}.evidence_ref may contain only A-Z a-z 0-9 . _ - and /"];
        }

        return [];
    }

    public function isValid(): bool
    {
        return $this->problems === [];
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<array<string, mixed>> */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Which of the five evidence-backed claims this registry actually supports.
     *
     * An INVALID registry supports nothing at all. Deciding a claim from records that failed
     * validation would mean a malformed file could be the thing that grants a claim, which inverts
     * the point of validating it.
     *
     * @return array<string, bool>
     */
    public function supportedClaims(): array
    {
        if (!$this->isValid()) {
            return array_fill_keys(self::EVIDENCE_BACKED_CLAIMS, false);
        }

        return [
            'conformance_complete' => $this->hasPass('CONFORMANCE'),
            // A HOST CLAIM NEEDS A HOST RECORD. `supportsHostClaim` is what stops a CI run — which
            // proves the code and never Loopia — from qualifying the deployment target.
            'staging_deployed' => $this->hasHostPass('STAGING_DEPLOYMENT'),
            // BOTH, FOR THE SAME RELEASE. A probe against one release and a deployment of another
            // qualify nothing together: the pair is the evidence, not either half.
            'loopia_capability_validated' => $this->hasHostPassPairForOneRelease(
                'STAGING_DEPLOYMENT',
                'LOOPIA_PROBE_001',
            ),
            'live_read_activated' => $this->liveReadStandsActivated(),
            // ANY result. Execution is not success, and a failed road test is still a road test
            // that was executed — see the class docblock.
            'road_test_001_executed' => $this->hasAny('ROAD_TEST_001'),
        ];
    }

    private function hasAny(string $kind): bool
    {
        foreach ($this->records as $record) {
            if ($record['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }

    private function hasPass(string $kind): bool
    {
        foreach ($this->records as $record) {
            if ($record['kind'] === $kind && $record['result'] === 'PASS') {
                return true;
            }
        }

        return false;
    }

    private function hasHostPass(string $kind): bool
    {
        foreach ($this->records as $record) {
            if ($record['kind'] === $kind && $record['result'] === 'PASS' && $this->supportsHostClaim($record)) {
                return true;
            }
        }

        return false;
    }

    private function hasHostPassPairForOneRelease(string $first, string $second): bool
    {
        foreach ($this->releasesWithHostPass($first) as $release) {
            if (in_array($release, $this->releasesWithHostPass($second), true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function releasesWithHostPass(string $kind): array
    {
        $out = [];
        foreach ($this->records as $record) {
            if ($record['kind'] === $kind && $record['result'] === 'PASS' && $this->supportsHostClaim($record)) {
                $out[] = (string) $record['release_ref'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Whether one record may speak about the real deployment target.
     *
     * @param array<string, mixed> $record
     */
    private function supportsHostClaim(array $record): bool
    {
        return in_array((string) $record['environment'], self::HOST_ENVIRONMENTS, true);
    }

    /**
     * Whether live read currently stands activated for any release.
     *
     * LATEST WINS, PER RELEASE. An activation followed by a stand-down is not activated, and a
     * registry that answered "an activation record exists" would keep the claim true through every
     * withdrawal that came after it. Canonical timestamps sort lexicographically by construction
     * (`Core\Clock`), so ordering needs no parsing; on an exact tie the STAND_DOWN wins, because a
     * registry that cannot tell which happened first should not be the thing that says live read is
     * on.
     */
    private function liveReadStandsActivated(): bool
    {
        /** @var array<string, array{at: string, kind: string, result: string}> $latest */
        $latest = [];
        foreach ($this->records as $record) {
            $kind = (string) $record['kind'];
            if ($kind !== 'LIVE_READ_ACTIVATION' && $kind !== 'LIVE_READ_STAND_DOWN') {
                continue;
            }
            if (!$this->supportsHostClaim($record)) {
                continue;
            }
            $release = (string) $record['release_ref'];
            $at = (string) $record['recorded_at'];
            $current = $latest[$release] ?? null;
            if ($current === null || $at > $current['at']) {
                $latest[$release] = ['at' => $at, 'kind' => $kind, 'result' => (string) $record['result']];
                continue;
            }
            if ($at === $current['at'] && $kind === 'LIVE_READ_STAND_DOWN') {
                $latest[$release] = ['at' => $at, 'kind' => $kind, 'result' => (string) $record['result']];
            }
        }

        foreach ($latest as $state) {
            if ($state['kind'] === 'LIVE_READ_ACTIVATION' && $state['result'] === 'PASS') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every claim in `$claimBoundary` that is asserted true without evidence to support it.
     *
     * THE IMPLICATION RUNS ONE WAY. Evidence without a claim is fine — an operator may record a
     * successful deployment and leave the flag alone until somebody reviews it. A claim without
     * evidence is the failure.
     *
     * @param array<string, mixed> $claimBoundary
     * @return list<string>
     */
    public function unsupportedClaims(array $claimBoundary): array
    {
        $supported = $this->supportedClaims();
        $out = [];
        foreach (self::EVIDENCE_BACKED_CLAIMS as $claim) {
            if (($claimBoundary[$claim] ?? false) === true && $supported[$claim] !== true) {
                $out[] = $claim;
            }
        }

        return $out;
    }
}
