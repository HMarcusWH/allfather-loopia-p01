<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Storage\EvidenceStore;

/** Private execution artifacts, not admissible evidence or a release. Scope is checked by the caller. */
final class RunArtifacts
{
    public function __construct(private readonly EvidenceStore $store) {}
    public function save(array $run, string $kind, array $payload): Outcome
    {
        return $this->store->put(Digest::canonicalize([
            'monitor_run_id'=>$run['monitor_run_id'], 'tenant_id'=>$run['tenant_id'],
            'workspace_id'=>$run['workspace_id'], 'kind'=>$kind, 'payload'=>$payload,
        ]));
    }
    /** A model candidate envelope may only cite an already-durable provider candidate. */
    public function saveCandidate(array $run, string $kind, array $payload): Outcome
    {
        foreach (['role','context_digest','request_digest','candidate_digest','provider_call_id','model_attempt_id','model_result_id','candidate_artifact_digest','execution_mode'] as $key) {
            if (!is_string($payload[$key] ?? null) || $payload[$key] === '') {
                return Outcome::refuse('MONITOR_CANDIDATE_ARTIFACT_INVALID');
            }
        }
        if (!is_array($payload['candidate'] ?? null)
            || !hash_equals(Digest::canonicalDigest($payload['candidate']), $payload['candidate_digest'])
            || !hash_equals($payload['candidate_digest'], $payload['candidate_artifact_digest'])) {
            return Outcome::refuse('MONITOR_CANDIDATE_ARTIFACT_INVALID');
        }

        return $this->save($run, $kind, $payload);
    }
    public function read(array $run, string $digest, string $kind): Outcome
    {
        $stored=$this->store->get($digest);
        if (!$stored->isOk()) { return $stored; }
        try { $value=json_decode($stored->value(),true,128,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return Outcome::refuse('MONITOR_ARTIFACT_INVALID'); }
        foreach (['monitor_run_id','tenant_id','workspace_id'] as $key) {
            if (($value[$key]??null)!==$run[$key]) { return Outcome::refuse('MONITOR_ARTIFACT_SCOPE_MISMATCH'); }
        }
        if (($value['kind']??null)!==$kind || !is_array($value['payload']??null)) { return Outcome::refuse('MONITOR_ARTIFACT_INVALID'); }
        return Outcome::ok($value['payload']);
    }
}
