<?php

declare(strict_types=1);

use Allfather\Loopia\Workflow\{
    HistoricalUseReviewPacket,NativeCaptureHistoryCandidate,
    HistoryReviewBasis,ScheduleHistoryCoverage
};
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Core\Digest;

$v=json_decode((string)file_get_contents(dirname(__DIR__).'/fixtures/li05_offline_basis_vectors.json'),
    true,64,JSON_THROW_ON_ERROR);
$source=[
    'scope'=>$v['scope'],
    'period'=>$v['period'],
    'baseline_period'=>$v['baseline_period'],
    'baseline_null_reason'=>null,
    'knowledge_cutoff_utc'=>$v['knowledge_cutoff_utc'],
    'rows'=>$v['rows'],
    'max_references'=>8,
    'coverage'=>$v['known_coverage'],
];
$basis=HistoryReviewBasis::freeze($source);
Assert::true($basis->isOk(),'existing LI05 exact source metadata basis freezes');
$ownerReceipt=[
    'version'=>ScheduleHistoryCoverage::VERSION,
    'status'=>'COMPLETE_OWNER_SLOT_COVERAGE',
    'source_coverage_complete'=>true,
    'schedule_version_id'=>'review-fixture-schedule-v1',
    'schedule_digest'=>str_repeat('a',64),
    'receipt_digest'=>str_repeat('b',64),
];
$native=[
    'version'=>NativeCaptureHistoryCandidate::VERSION,
    'status'=>'OWNER_NATIVE_CAPTURE_METADATA_VERIFIED_U03_HOLD',
    'history_basis'=>$basis->value(),
    'proof_manifest_digest'=>str_repeat('c',64),
    'verified_capture_count'=>count($basis->value()['references']),
    'owner_provenance_verified'=>true,
    'source_coverage_proven'=>true,
    'schedule_coverage_report'=>$ownerReceipt,
    'source_bytes_returned'=>false,
    'U03_historical_use_admitted'=>false,
    'U06_model_context_authorized'=>false,
    'M17_input_set_authorized'=>false,
    'scheduler_action_authorized'=>false,
    'customer_output_authorized'=>false,
];
$packet=HistoricalUseReviewPacket::prepare($native);
Assert::true($packet->isOk(),'review packet accepts a fully bounded, unadmitted synthetic evidence claim');
$p=$packet->value();
Assert::same('READY_FOR_U03_OWNER_REVIEW_NOT_ADMITTED',$p['review_readiness'],
    'complete owner slot metadata is a review candidate, not runtime reuse permission');
Assert::same($basis->value()['manifest_digest'],$p['basis_digest'],
    'frozen v1 basis SHA bound, never reserialized as a new v2 assumption');
Assert::same('COMPLETE_WITHIN_OWNER_SCHEDULE',$p['coverage_status'],
    'single source schedule scope explicitly retained');
Assert::same(6,count($p['owner_decisions_required']),
    'separate M05, P01, M02, M10, Ops and canonical U03 owners not silently selected');
foreach ([
    'historical_purpose_granted','provider_disclosure_granted',
    'runtime_M17_persistence_granted','model_context_granted',
    'scheduler_execution_granted','customer_release_granted'
] as $flag) {
    Assert::same(false,$p[$flag],'even an apparently ready packet cannot grant '.$flag);
}
$second=HistoricalUseReviewPacket::prepare($native);
Assert::same($p['packet_digest'],$second->value()['packet_digest'],
    'review-only provenance digest deterministic on the same input');
Assert::same('LI05F_U03_HISTORICAL_USE_NOT_RATIFIED',
    HistoricalUseReviewPacket::admit($p,['owner_approved'=>true,'decision_ref'=>'FORGED'])->reasonCode(),
    'self-asserted approval cannot upgrade review to historical use');

$unknownInput=$source;
$unknownInput['coverage']=['state'=>'UNKNOWN'];
$uncertain=HistoryReviewBasis::freeze($unknownInput);
Assert::true($uncertain->isOk(),'existing selector freezes incomplete history');
$unknown=$native;
$unknown['history_basis']=$uncertain->value();
$unknown['source_coverage_proven']=false;
$unknown['schedule_coverage_report']=null;
$review=HistoricalUseReviewPacket::prepare($unknown);
Assert::true($review->isOk(),'incomplete native capture remains inspectable in a review packet');
Assert::same('HOLD_COVERAGE_OR_UNVERIFIED_SCOPE',$review->value()['review_readiness'],
    'unknown coverage cannot be mistaken for a ready review');
Assert::same(null,$review->value()['schedule_receipt_digest'],
    'unknown source schedule is not supplied a fabricated receipt');

$bad=$native;$bad['U03_historical_use_admitted']=true;
Assert::same('LI05F_OWNER_EVIDENCE_NOT_VERIFIABLE',
    HistoricalUseReviewPacket::prepare($bad)->reasonCode(),
    'claimed historical-use flag cannot be a source-owner grant');
$bad=$native;$bad['source_bytes_returned']=true;
Assert::same('LI05F_OWNER_EVIDENCE_NOT_VERIFIABLE',
    HistoricalUseReviewPacket::prepare($bad)->reasonCode(),
    'raw bytes cannot be smuggled through a review preview');
$bad=$native;$bad['proof_manifest_digest']='not-a-hash';
Assert::same('LI05F_OWNER_EVIDENCE_NOT_VERIFIABLE',
    HistoricalUseReviewPacket::prepare($bad)->reasonCode(),
    'evidence manifest must have a typed digest');
$bad=$native;$bad['owner_provenance_verified']=false;
Assert::same('LI05F_OWNER_EVIDENCE_NOT_VERIFIABLE',
    HistoricalUseReviewPacket::prepare($bad)->reasonCode(),
    'caller cannot omit owner-native capture provenance');
$bad=$native;$bad['schedule_coverage_report']['receipt_digest']='corrupt';
Assert::same('LI05F_OWNER_COVERAGE_CONTRADICTORY',
    HistoricalUseReviewPacket::prepare($bad)->reasonCode(),
    'schedule owner digest corruption blocks review readiness');
$bad=$native;$bad['source_coverage_proven']=false;
Assert::same('LI05F_OWNER_COVERAGE_CONTRADICTORY',
    HistoricalUseReviewPacket::prepare($bad)->reasonCode(),
    'a complete frozen basis cannot be downgraded to UNKNOWN by a flag only');
$bad=$native;$bad['verified_capture_count']=1;
Assert::same('LI05F_CAPTURE_PROOF_COUNT_MISMATCH',
    HistoricalUseReviewPacket::prepare($bad)->reasonCode(),
    'cannot claim more selected captures than the owner proof validated');
$bad=$native;$bad['history_basis']['manifest_digest']=str_repeat('e',64);
Assert::same('LI05F_OWNER_EVIDENCE_NOT_VERIFIABLE',
    HistoricalUseReviewPacket::prepare($bad)->reasonCode(),
    'digest tampering blocks owner-review packet creation');
