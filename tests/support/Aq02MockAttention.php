<?php

declare(strict_types=1);

namespace Allfather\Loopia\Tests;

use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\Product\P01\HistoricalFindingAdapter;
use Allfather\Loopia\Product\P04\AttentionPolicy;
use Allfather\Loopia\Workflow\{NativeReleaseProposal,HistoryReviewOutcomes};

/**
 * Fixed source-only positive inputs. None is a real released briefing,
 * owner-admitted M10 record, human judgment or current source-use decision.
 */
final class Aq02MockAttention
{
    public static function input(?array $scope=null):Outcome
    {
        $basis=An03MockFinding::inputs($scope);
        if(!$basis->isOk())return Outcome::refuse('AQ02_AN03_FIXTURE_MISSING');
        $finding=HistoricalFindingAdapter::propose($basis->value());
        if(!$finding->isOk())return Outcome::refuse('AQ02_FINDING_FIXTURE_MISSING');
        $native=NativeReleaseProposal::fromFinding($finding->value());
        if(!$native->isOk())return Outcome::refuse('AQ02_NATIVE_PROPOSAL_FIXTURE_MISSING');
        $annotation=HistoryReviewOutcomes::propose([
            'finding'=>$finding->value(),
            'prior_annotation'=>null,
            'observer'=>[
                'actor_type'=>'SYNTHETIC_FIXTURE',
                'actor_id'=>'aq02_fixed_reporter',
            ],
            'disposition'=>'STILL_UNRESOLVED',
            'new_evidence'=>[],
            'inspected_at_utc'=>'2026-10-29T12:06:00Z',
            'deadline_utc'=>'2026-11-06T00:00:00Z',
            'next_check_code'=>'WAIT_NEXT_OBSERVATION',
        ]);
        if(!$annotation->isOk())return Outcome::refuse('AQ02_AN04_FIXTURE_MISSING');
        $realScope=$finding->value()['scope'];
        $policy=AttentionPolicy::freeze([
            'scope'=>$realScope,
            'policy_key'=>'aq02_test_policy',
            'target_org_role'=>'SALES',
            'reason_allowlist'=>AttentionPolicy::REASONS,
            'max_digest_items'=>4,
            'cooldown_seconds'=>3600,
            'redaction_profile_digest'=>hash('sha256','aq02.fixed.redaction.policy'),
            'valid_from_utc'=>'2026-10-28T00:00:00Z',
            'valid_until_utc'=>'2026-11-10T00:00:00Z',
        ]);
        if(!$policy->isOk())return Outcome::refuse('AQ02_POLICY_FIXTURE_MISSING');
        return Outcome::ok([
            'policy'=>$policy->value(),
            'released_output'=>Aq02FixtureAttentionOwner::releasedOutput($realScope),
            'native_release_proposal'=>$native->value(),
            'outcome_annotation'=>$annotation->value(),
            'queue_watermarks'=>[
                'queue_candidate_digest'=>null,'pending_reviews'=>0,
                'oldest_pending_at_utc'=>null,
                'last_human_reviewed_at_utc'=>null,
            ],
            'at_utc'=>'2026-10-30T12:00:00Z',
            'reason_code'=>'ADMITTED_FINDING_REVIEW',
            'operator_choice'=>'SHOW',
            'defer_until_utc'=>null,
            'existing_dedup_keys'=>[],
        ]);
    }
}
