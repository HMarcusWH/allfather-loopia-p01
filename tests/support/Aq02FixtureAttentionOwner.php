<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\{Digest,Outcome};
use Allfather\Loopia\Product\P04\{AttentionReadPort,Distribution};
use Allfather\Loopia\Substrate\M17\Vocabulary;

/** Explicitly synthetic, fixed CLI test owner. Never a source of live M10 authority. */
final class Aq02FixtureAttentionOwner implements AttentionReadPort
{
    public const VERSION='aq02.fixed-fixture-owner-inspection.v1';
    public const CASES=['CURRENT','STALE','REVOKED','ROLE_DENIED','REDACTION_INVALID','UNKNOWN_RIGHTS'];

    /** @var list<string> */
    public array $stages=[];

    public function __construct(private readonly string $caseId='CURRENT')
    {
        if(!in_array($caseId,self::CASES,true)){
            throw new \DomainException('AQ02_UNKNOWN_SYNTHETIC_OWNER_CASE');
        }
    }

    public static function releasedOutput(array $scope):array
    {
        return [
            'version'=>'aq02.synthetic-released-output-ref.v1',
            'scope'=>$scope,
            'source_kind'=>'P01_BRIEFING',
            'source_id'=>'aq02_fixture_p01_briefing',
            'decision_record_id'=>'aq02_fixture_m10_decision',
            'release_digest'=>hash('sha256','aq02.synthetic.released.metadata'),
            'raw_source_bytes_included'=>false,
            'synthetic_only'=>true,
        ];
    }

    public function inspectForAttention(
        array $source,array $scope,string $role,string $redactionDigest,
        string $stage,
    ):Outcome {
        $this->stages[]=$stage;
        if(PHP_SAPI!=='cli' || !Vocabulary::scope($scope)
            || !in_array($role,Distribution::TARGET_ORG_ROLES,true)
            || !in_array($stage,['PREPARE','RENDER'],true)
            || !is_string($redactionDigest)
            || preg_match('/^[a-f0-9]{64}$/D',$redactionDigest)!==1
            || $source!==self::releasedOutput($scope)){
            return Outcome::refuse('AQ02_FIXTURE_READ_SCOPE_OR_RELEASE_INVALID');
        }
        $v=[
            'version'=>self::VERSION,
            'stage'=>$stage,
            'scope'=>$scope,
            'source_kind'=>$source['source_kind'],
            'source_id'=>$source['source_id'],
            'decision_record_id'=>$source['decision_record_id'],
            'source_release_digest'=>$source['release_digest'],
            'target_org_role'=>$role,
            'redaction_profile_digest'=>$redactionDigest,
            'current_reliance'=>match($this->caseId){
                'STALE'=>'SUPERSEDED',default=>'CURRENT',
            },
            'source_rights'=>match($this->caseId){
                'REVOKED'=>'REVOKED',
                'UNKNOWN_RIGHTS'=>'UNKNOWN',
                default=>'INTERNAL_DERIVED_ALLOWED',
            },
            'role_entitled'=>$this->caseId!=='ROLE_DENIED',
            'redaction_eligible'=>$this->caseId!=='REDACTION_INVALID',
            'synthetic_only'=>true,
            'actual_owner_authenticated'=>false,
            'real_m10_record_queried'=>false,
            'customer_visibility_authorized'=>false,
        ];
        $v['receipt_digest']=Digest::canonicalDigest($v);
        return Outcome::ok($v);
    }
}
