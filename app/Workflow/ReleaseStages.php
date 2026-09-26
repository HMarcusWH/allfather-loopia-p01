<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\{Clock,Outcome};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Product\{P01,P04};
use Allfather\Loopia\Substrate\M02\AccessGuard;
use Allfather\Loopia\Substrate\M04\Proposals;
use Allfather\Loopia\Substrate\M05\{EvidenceSpine,Readiness};
use Allfather\Loopia\Substrate\M07\Council;
use Allfather\Loopia\Substrate\M08\Arbiter;
use Allfather\Loopia\Substrate\M10\DecisionLedger;
use Allfather\Loopia\Substrate\M16\ReleaseOutput;

/** Composition only: M07 reviews, M08 admits, M10 releases and M16 freezes the internal artifact. */
final class ReleaseStages
{
    private readonly Council $council;
    private readonly P01\CompanySignals $signals;
    private readonly P01\Battlecards $battlecards;
    private readonly P01\Release $p01;
    private readonly P04\Distribution $distribution;
    private readonly P04\DistributionAdmission $routing;
    private readonly P04\Release $p04;

    public function __construct(private readonly Database $db,private readonly Clock $clock)
    {
        $proposals=new Proposals($db,$clock); $evidence=new EvidenceSpine($db,$clock);
        $readiness=new Readiness($db,$clock); $this->council=new Council($db,$clock);
        $arbiter=new Arbiter($db,$clock,$readiness); $ledger=new DecisionLedger($db,$clock);
        $this->signals=new P01\CompanySignals($db,$clock,$proposals,$evidence,$readiness,$this->council);
        $this->battlecards=new P01\Battlecards($db,$clock,$arbiter);
        $this->p01=new P01\Release($db,$clock,$ledger,new ReleaseOutput($db,$clock,$ledger));
        $this->distribution=new P04\Distribution($db,$clock,$ledger);
        $this->routing=new P04\DistributionAdmission($db,$clock,$evidence,$readiness,$proposals,$this->council,$arbiter,$ledger);
        $this->p04=new P04\Release($db,$clock,$ledger,new AccessGuard($db,$clock),new P01\Entitlements($db,$clock));
    }

    public function releaseP01(array $scope,array $run,array $classifier,array $draft,array $critic): Outcome
    {
        $d=$run['definition']; $s=self::scope($scope);
        if (($critic['verdict']??'')!=='ACCEPT_FOR_HUMAN_REVIEW' || ($draft['status']??'')!=='DRAFT' || ($classifier['status']??'')!=='FINDING') { return Outcome::refuse('MONITOR_CANDIDATE_NOT_RELEASABLE'); }
        $signal=$this->signals->createFromDiff($s+[
            'monitored_source_id'=>$d['monitored_source_id'],'source_diff_id'=>$run['cursor']['diff']['source_diff_id'],
            'signal_class'=>$classifier['signal_class'],'interpretation_kind'=>'HYPOTHESIS','confidence_band'=>self::confidenceFloor($classifier,$draft),
            'summary_safe'=>$draft['summary'],
        ]);
        if (!$signal->isOk()) { return $signal; }
        $signalId=$signal->value()['company_signal_id'];
        $submitted=$this->signals->submitBrief($s+[
            'company_signal_id'=>$signalId,'context_evidence_ids'=>$d['context_selection']['profile_evidence_ids'],
            'uncertainty_summary_safe'=>implode(' ',[...$classifier['limitations'],...$draft['uncertainties']]),
            'governance'=>array_merge($d['release_governance'],['submitted_by_actor_type'=>'OPERATOR','submitted_by_actor_id'=>$scope['operator_id']]),
        ]);
        if (!$submitted->isOk()) { return $submitted; }
        // The upstream context-lineage extension must actually be present, not silently ignored.
        if (array_diff($d['context_selection']['profile_evidence_ids'],$submitted->value()['cited_evidence_ids'])!==[]) { return Outcome::refuse('MONITOR_CONTEXT_LINEAGE_UNAVAILABLE'); }
        $opened=$this->signals->openRelevanceReview($s+['company_signal_id'=>$signalId,'initiated_by_actor_type'=>'OPERATOR','initiated_by_actor_id'=>$scope['operator_id']]);
        if (!$opened->isOk()) { return $opened; }
        $review=$this->recordCritic($run,$opened->value()['review_session_id'],$critic,$submitted->value()['cited_evidence_ids']);
        if (!$review->isOk()) { return $review; }
        $verdict=$this->battlecards->issueVerdict($s+['company_signal_id'=>$signalId,'arbiter_policy_set_id'=>$d['p01_policy_id'],'content'=>$draft['content'],'correlation_id'=>$run['monitor_run_id']]);
        if (!$verdict->isOk()) { return $verdict; }
        if (($verdict->value()['outcome']??'')!=='ADMITTED') { return Outcome::refuse('MONITOR_P01_ADMISSION_REFUSED',['verdict'=>$verdict->value()['verdict']??null,'reason_codes'=>$verdict->value()['reason_codes']??[]]); }
        $released=$this->p01->releaseBriefing($s+['battlecard_brief_id'=>$verdict->value()['battlecard_brief_id']]);
        return !$released->isOk()?$released:Outcome::ok($released->value()+['company_signal_id'=>$signalId,'review_session_id'=>$opened->value()['review_session_id']]);
    }

    public function prepareRouting(array $scope,array $run,array $draft): Outcome
    {
        $d=$run['definition']; $s=self::scope($scope);
        if (!in_array($d['target_org_role'],$draft['recommended_internal_roles'],true)) { return Outcome::refuse('MONITOR_ROUTING_ROLE_NOT_REVIEWED'); }
        $composed=$this->distribution->composeRoleDistributionBriefing($s+[
            'target_org_role'=>$d['target_org_role'],'source_kind'=>'P01_BRIEFING',
            'source_id'=>$run['cursor']['p01_release']['released_briefing_id'],'briefing_summary_safe'=>$draft['summary'],
        ]);
        if (!$composed->isOk()) { return $composed; }
        $id=$composed->value()['role_distribution_briefing_id'];
        $submitted=$this->routing->submitRoutingProposal($s+[
            'role_distribution_briefing_id'=>$id,'sensitivity_class'=>'ROUTINE',
            'governance'=>array_merge($d['release_governance'],['submitted_by_actor_type'=>'OPERATOR','submitted_by_actor_id'=>$scope['operator_id']]),
        ]);
        if (!$submitted->isOk()) { return $submitted; }
        $opened=$this->routing->openSensitivityReview($s+['role_distribution_briefing_id'=>$id,'initiated_by_actor_type'=>'OPERATOR','initiated_by_actor_id'=>$scope['operator_id']]);
        return !$opened->isOk()?$opened:Outcome::ok($opened->value()+['role_distribution_briefing_id'=>$id,'target_org_role'=>$d['target_org_role'],'briefing_summary_safe'=>$draft['summary'],'released_briefing_id'=>$run['cursor']['p01_release']['released_briefing_id'],'source_decision_record_id'=>$run['cursor']['p01_release']['decision_record_id']]);
    }

    public function releaseRouting(array $scope,array $run,array $critic): Outcome
    {
        $routing=$run['cursor']['routing']; $d=$run['definition']; $s=self::scope($scope);
        $bundleId=$this->db->scalar('SELECT evidence_bundle_id FROM proposal_envelopes WHERE proposal_id=?',[$routing['proposal_id']]);
        $evidenceIds=array_column($this->db->all('SELECT evidence_id FROM evidence_bundle_items WHERE evidence_bundle_id=?',[$bundleId]),'evidence_id');
        $review=$this->recordCritic($run,$routing['review_session_id'],$critic,$evidenceIds);
        if (!$review->isOk()) { return $review; }
        $admitted=$this->routing->admitRouting($s+['role_distribution_briefing_id'=>$routing['role_distribution_briefing_id'],'arbiter_policy_set_id'=>$d['p04_policy_id'],'correlation_id'=>$run['monitor_run_id']]);
        if (!$admitted->isOk()) { return $admitted; }
        if (($admitted->value()['outcome']??'')!=='ADMITTED') { return Outcome::refuse('MONITOR_ROUTING_ADMISSION_REFUSED',['verdict'=>$admitted->value()['verdict']??null,'reason_codes'=>$admitted->value()['reason_codes']??[]]); }
        return $this->p04->releaseRoleDistribution($s+['role_distribution_briefing_id'=>$routing['role_distribution_briefing_id'],'correlation_id'=>$run['monitor_run_id']]);
    }

    private function recordCritic(array $run,string $sessionId,array $critic,array $evidenceIds): Outcome
    {
        if (($critic['verdict']??'')!=='ACCEPT_FOR_HUMAN_REVIEW') { return Outcome::refuse('MONITOR_CRITIC_REQUIRES_HOLD'); }
        $criticId=$run['definition']['model_governance']['critic']['valkyrie_id'];
        $assigned=$this->council->assignParticipant($sessionId,$criticId,'EVIDENCE_CRITIC','INDEPENDENT_DECLARED_REVIEWER');
        if (!$assigned->isOk()) { return $assigned; }
        $summary=($run['execution_mode']==='FIXTURE'?'Synthetic fixture critic; ':'Model critic advisory; ')
            . implode(' ',array_column($critic['findings'],'explanation'));
        $assessment=$this->council->recordAssessment($sessionId,$assigned->value()['participant_assignment_id'],$criticId,'SUPPORT_PROCEED_TO_ARBITER',$summary,$evidenceIds,[],'MEDIUM','SUFFICIENT_FOR_REVIEW');
        if (!$assessment->isOk()) { return $assessment; }
        // Human decision is a separate, digest-bound run artifact. Never attribute it to this agent.
        return $this->council->freezeResult($sessionId,'PROCEED_TO_ARBITER',$summary);
    }
    private static function scope(array $s): array { return ['tenant_id'=>$s['tenant_id'],'workspace_id'=>$s['workspace_id']]; }
    private static function confidenceFloor(array $classifier,array $draft): string
    {
        $bands=[...array_column($classifier['observed_changes'],'confidence'),...array_column($draft['claims'],'confidence')];
        // Composition cannot upgrade an uncertain model claim. HIGH remains capped at MEDIUM.
        return $bands===[] || in_array('LOW',$bands,true)?'LOW':'MEDIUM';
    }
}
