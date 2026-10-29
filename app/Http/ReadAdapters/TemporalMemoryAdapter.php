<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\Ids;
use Allfather\Loopia\Substrate\M17\Vocabulary;

/**
 * UI-01: closed operator-only P01 temporal memory candidate metadata.
 *
 * NOT registered in HTTP. The injected owner MUST recheck native M02
 * permissions, P01 scoped memory identity and current source rights on every
 * read/page. This presenter cannot attest U03 rights or synthesize findings.
 */
final class TemporalMemoryAdapter
{
    public const VERSION='ui01.inert-p01-temporal-memory.v1';
    public const MAX_PAGE_SIZE=50;
    private const HEX='/^[a-f0-9]{64}$/D';
    private const DATA_CLASSES=['SOURCE_DIFF','COMPANY_SIGNAL'];
    private const WINDOW_KINDS=['DAY','WEEK','MONTH'];
    private const HEAD_STATES=['SINGLE_CANDIDATE_NOT_ADMITTED','AMBIGUOUS_HEAD_HOLD'];
    private const REPLAY_STATES=[
        'MATCH_METADATA_ONLY','NOT_REPLAYABLE','DIVERGED','NOT_EVALUATED',
    ];

    /**
     * @param \Closure(array<string,string>,int,?string):array<string,mixed> $ownerRead
     */
    public function __construct(private readonly \Closure $ownerRead){}

    /** @param ?array<string,mixed> $session */
    public function list(
        ?array $session,string $competitorId,string $questionId,
        int $pageSize=20,?string $beforeMemoryId=null,
    ):array {
        if($session===null
            || !self::id($session['tenant_id']??null)
            || !self::id($session['active_workspace_id']??null)
            || !self::id($session['operator_id']??null)){
            return ['decision'=>'NO_ACTIVE_WORKSPACE',
                'reason_codes'=>['NO_ACTIVE_WORKSPACE']];
        }
        if(!self::id($competitorId)||!self::id($questionId)
            || $pageSize<1||$pageSize>self::MAX_PAGE_SIZE
            || ($beforeMemoryId!==null&&!self::id($beforeMemoryId))){
            return ['decision'=>'NOT_FOUND',
                'reason_codes'=>['TEMPORAL_MEMORY_SELECTOR_INVALID']];
        }
        $scope=[
            'tenant_id'=>$session['tenant_id'],
            'workspace_id'=>$session['active_workspace_id'],
            'actor_type'=>'OPERATOR','actor_id'=>$session['operator_id'],
            'competitor_id'=>$competitorId,
            'question_id'=>$questionId,
        ];
        try{
            $r=($this->ownerRead)($scope,$pageSize,$beforeMemoryId);
            if(!is_array($r))return self::unavailable();
            if(($r['decision']??null)==='DENIED')
                return ['decision'=>'DENIED',
                    'required_permission'=>'evidence.read',
                    'reason_codes'=>['TEMPORAL_METADATA_ACCESS_DENIED']];
            if(($r['decision']??null)==='NOT_FOUND')
                return ['decision'=>'NOT_FOUND',
                    'required_permission'=>'evidence.read',
                    'reason_codes'=>['TEMPORAL_METADATA_NOT_FOUND']];
            if(($r['decision']??null)!=='VISIBLE'
                || ($r['required_permission']??null)!=='evidence.read'
                || !is_array($r['data']??null)) return self::unavailable();
            $data=$r['data'];
            if(($data['read_only']??null)!==true
                || ($data['candidate_only']??null)!==true
                || ($data['source_rights']??null)!=='U03_NOT_ADOPTED'
                || ($data['runtime_actions_available']??null)!==false
                || ($data['page_size']??null)!==$pageSize
                || !is_array($data['rows']??null)
                || !array_is_list($data['rows'])
                || count($data['rows'])>$pageSize){
                return self::unavailable();
            }
            $rows=[];$last=null;$seen=[];
            foreach($data['rows'] as $row){
                if(!is_array($row)
                    || !self::id($row['temporal_memory_id']??null)
                    || isset($seen[$row['temporal_memory_id']])
                    || !self::hex($row['v2_run_identity_digest']??null)
                    || !self::hex($row['rollup_digest']??null)
                    || !in_array($row['data_class']??null,self::DATA_CLASSES,true)
                    || !in_array($row['window_kind']??null,self::WINDOW_KINDS,true)
                    || !Vocabulary::utc($row['period_start_utc']??null)
                    || !Vocabulary::utc($row['period_end_utc']??null)
                    || $row['period_start_utc']>=$row['period_end_utc']
                    || !Vocabulary::utc($row['knowledge_cutoff_utc']??null)
                    || !is_int($row['selected_ref_count']??null)
                    || $row['selected_ref_count']<1
                    || $row['selected_ref_count']>64
                    || !is_int($row['contrary_ref_count']??null)
                    || $row['contrary_ref_count']<0
                    || $row['contrary_ref_count']>$row['selected_ref_count']
                    || !in_array($row['head_status']??null,self::HEAD_STATES,true)
                    || !in_array($row['replay_status']??null,self::REPLAY_STATES,true)
                    || ($row['rights_status']??null)!=='NOT_ADMITTED'
                    || ($row['source_bytes_verified']??null)!==false
                    || ($row['historical_reuse_admitted']??null)!==false
                    || ($row['customer_release_authorized']??null)!==false){
                    return self::unavailable();
                }
                $id=$row['temporal_memory_id'];$seen[$id]=true;$last=$id;
                // Never pass opaque DB columns, untrusted model prose or
                // source labels along to a browser, even if the owner adds them.
                $rows[]=[
                    'temporal_memory_id'=>$id,
                    'v2_run_identity_digest'=>$row['v2_run_identity_digest'],
                    'rollup_digest'=>$row['rollup_digest'],
                    'data_class'=>$row['data_class'],
                    'window_kind'=>$row['window_kind'],
                    'period_start_utc'=>$row['period_start_utc'],
                    'period_end_utc'=>$row['period_end_utc'],
                    'knowledge_cutoff_utc'=>$row['knowledge_cutoff_utc'],
                    'selected_ref_count'=>$row['selected_ref_count'],
                    'contrary_ref_count'=>$row['contrary_ref_count'],
                    'head_status'=>$row['head_status'],
                    'replay_status'=>$row['replay_status'],
                    'rights_status'=>'NOT_ADMITTED',
                    'source_bytes_verified'=>false,
                    'historical_reuse_admitted'=>false,
                    'customer_release_authorized'=>false,
                ];
            }
            $next=$data['next_cursor']??null;
            if($next!==null&&(!self::id($next)||$next!==$last)){
                return self::unavailable();
            }
            return [
                'decision'=>'VISIBLE','required_permission'=>'evidence.read',
                'reason_codes'=>['GRANTED_METADATA_CANDIDATE_ONLY'],
                'data'=>[
                    'projection_version'=>self::VERSION,
                    'scope'=>[
                        'competitor_id'=>$competitorId,
                        'question_id'=>$questionId,
                    ],
                    'rows'=>$rows,
                    'page_size'=>$pageSize,'next_cursor'=>$next,
                    'read_only'=>true,'candidate_only'=>true,
                    'source_rights'=>'U03_NOT_ADOPTED',
                    'runtime_actions_available'=>false,
                    'historical_analysis_available'=>false,
                    'customer_output_authorized'=>false,
                ],
            ];
        }catch(\Throwable){return self::unavailable();}
    }

    private static function id(mixed $value):bool
    {
        return is_string($value)&&Ids::isValid($value);
    }
    private static function hex(mixed $value):bool
    {
        return is_string($value)&&preg_match(self::HEX,$value)===1;
    }
    private static function unavailable():array
    {
        return ['decision'=>'UNAVAILABLE',
            'reason_codes'=>['TEMPORAL_MEMORY_OWNER_READ_UNAVAILABLE']];
    }
}
