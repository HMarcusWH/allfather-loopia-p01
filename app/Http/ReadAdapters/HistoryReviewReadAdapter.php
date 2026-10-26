<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\Ids;

/**
 * LI-16 R2 private, metadata-only historical review readback *candidate*.
 *
 * NOT wired to an HTTP route. $ownerRead MUST be an M02/owner-authorized
 * read-only closure; this presenter itself does not grant evidence.read.
 * It refuses unverified provenance, customer-facing release and raw source
 * text. A future route needs distinct owner approval and operator session.
 */
final class HistoryReviewReadAdapter
{
    public const VERSION='li16.r2.inert-temporal-readback.v1';

    /** @param \Closure(array<string,string>,int):array<string,mixed> $ownerRead */
    public function __construct(private readonly \Closure $ownerRead) {}

    /**
     * @param array<string,mixed>|null $session
     * @return array<string,mixed>
     */
    public function list(?array $session,string $competitorId,string $questionId,int $limit=20):array
    {
        if ($session===null
            || !Ids::isValid($session['tenant_id']??null)
            || !Ids::isValid($session['active_workspace_id']??null)
            || !Ids::isValid($session['operator_id']??null)) {
            return ['decision'=>'NO_ACTIVE_WORKSPACE','reason_codes'=>['NO_ACTIVE_WORKSPACE']];
        }
        if (!Ids::isValid($competitorId)||!Ids::isValid($questionId)
            || $limit<1||$limit>50) {
            return ['decision'=>'NOT_FOUND','reason_codes'=>['HISTORY_READ_SELECTOR_INVALID']];
        }
        $scope=[
            'tenant_id'=>$session['tenant_id'],
            'workspace_id'=>$session['active_workspace_id'],
            'actor_type'=>'OPERATOR','actor_id'=>$session['operator_id'],
            'competitor_id'=>$competitorId,'question_id'=>$questionId,
        ];
        try {
            $result=($this->ownerRead)($scope,$limit);
            if (!is_array($result)) return self::unavailable();
            if (($result['decision']??null)==='DENIED') {
                return ['decision'=>'DENIED','required_permission'=>'evidence.read',
                    'reason_codes'=>['HISTORY_READ_NOT_AUTHORIZED']];
            }
            if (($result['decision']??null)==='NOT_FOUND') {
                return ['decision'=>'NOT_FOUND','required_permission'=>'evidence.read',
                    'reason_codes'=>['HISTORY_READ_NOT_FOUND']];
            }
            if (($result['decision']??null)!=='VISIBLE'
                || ($result['required_permission']??null)!=='evidence.read'
                || !is_array($result['data']??null)) return self::unavailable();
            $d=$result['data'];
            if (($d['read_only']??null)!==true
                || ($d['candidate_only']??null)!==true
                || ($d['source_rights']??null)!=='U03_NOT_ADOPTED'
                || !is_array($d['rows']??null) || !array_is_list($d['rows'])
                || count($d['rows'])>$limit) return self::unavailable();
            $rows=[];
            foreach($d['rows'] as $row){
                if (!is_array($row)
                    || !Ids::isValid($row['history_review_basis_id']??null)
                    || !is_string($row['basis_digest']??null)
                    || preg_match('/^[a-f0-9]{64}$/D',$row['basis_digest'])!==1
                    || !in_array($row['head_status']??null,[
                        'NONE_NOT_ADMITTED','SINGLE_CANDIDATE_NOT_ADMITTED',
                        'AMBIGUOUS_HEAD_HOLD',
                    ],true)
                    || !in_array($row['due_status']??null,[
                        'DUE_CANDIDATE_HUMAN_REVIEW_ONLY','NO_NEW_BASIS_CANDIDATE',
                        'NOT_DUE_CANDIDATE','DEFERRED_COOLDOWN',
                        'DEFERRED_BACKPRESSURE','DEFERRED_COALESCE_REVIEW',
                        'HOLD_CURRENT_RIGHTS',
                    ],true)
                    || !is_int($row['selected_count']??null)
                    || $row['selected_count']<0||$row['selected_count']>256
                    || !is_int($row['excluded_count']??null)
                    || $row['excluded_count']<0||$row['excluded_count']>256) {
                    return self::unavailable();
                }
                // Exact whitelist; never copy owner-provided source URL, text,
                // tokens, prompt, model candidate or credentials.
                $rows[]=[
                    'history_review_basis_id'=>$row['history_review_basis_id'],
                    'basis_digest'=>$row['basis_digest'],
                    'selected_count'=>$row['selected_count'],
                    'excluded_count'=>$row['excluded_count'],
                    'head_status'=>$row['head_status'],
                    'due_status'=>$row['due_status'],
                    'rights_status'=>'NOT_ADOPTED',
                ];
            }
            return ['decision'=>'VISIBLE','required_permission'=>'evidence.read',
                'reason_codes'=>['GRANTED_METADATA_ONLY'],'data'=>[
                    'read_only'=>true,'candidate_only'=>true,
                    'scope'=>$scope,'rows'=>$rows,
                    'source_rights'=>'U03_NOT_ADOPTED',
                    'runtime_actions_available'=>false,
                    'historical_analysis_available'=>false,
                    'projection_version'=>self::VERSION,
                ]];
        } catch(\Throwable) {return self::unavailable();}
    }

    private static function unavailable():array
    {
        return ['decision'=>'UNAVAILABLE','reason_codes'=>['HISTORY_READBACK_NOT_AVAILABLE']];
    }
}
