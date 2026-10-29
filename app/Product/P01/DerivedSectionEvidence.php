<?php

declare(strict_types=1);

namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\{Clock,Digest,Ids,Outcome};

/**
 * CR-04: typed read-only candidate composition over EXISTING CR-01/CR-02/
 * OB-02/OB-03 owners. It accepts no URL and performs no HTML network
 * acquisition, M05 evidence read/write, prompt execution or release.
 *
 * The caller supplies projections already held under a narrow synthetic
 * fixture contract. No callable native M05 adapter/source grant is invented.
 * Future native integration must check M02/P01/M05 and U01/U02/U03/U06 at
 * actual dispatch and use (not infer authorization from source-looking IDs).
 */
final class DerivedSectionEvidence
{
    public const VERSION='cr04.derived-section-p01-context.v1';
    public const MAX_CONTEXT_BYTES=16384;
    public const MAX_ROWS=SemanticDelta::MAX_DELTAS;
    private const HEX='/^[0-9a-f]{64}$/D';
    private const ROW_KEYS=[
        'change_kind','alignment','before_type','after_type',
        'before_ordinal','after_ordinal',
        'before_locator_digest','after_locator_digest',
        'before_text_sha256','after_text_sha256',
        'sensitive_token_kinds','uncertainty_codes',
        'source_excerpt_exported','original_byte_offsets_verified',
    ];
    private const ROOT_KEYS=[
        'schema_version','type','status','scope','capture_binding','source_versions',
        'provenance_digests','observations','observation_count',
        'source_count_distinctions','time_bounds','structural_classification',
        'untrusted_input_flags','unknown_dimensions',
        'business_materiality','provider_token_estimate','interpretation_only',
        'source_excerpt_exported','source_bytes_exported',
        'current_source_rights_authenticated','historical_reuse_admitted',
        'model_context_authorized','customer_output_authorized',
        'runtime_persistence_authorized','production_activation_authorized',
        'context_digest',
    ];

    /**
     * @param array<string,mixed> $assessment Existing immutable OB-02 legacy metadata.
     * @param array<string,mixed> $before Existing bounded CR-01-shaped projection.
     * @param array<string,mixed> $after Existing bounded CR-01-shaped projection.
     * @param list<array<string,mixed>> $captures Existing LI-04 candidate capture class refs.
     * @param ?array<string,mixed> $boundedInput Optional supplied bytes only for
     *        LI-04 cross-check; the returned context never contains those bytes.
     */
    public static function compose(
        array $assessment,array $before,array $after,array $captures,
        ?array $boundedInput=null,
    ): Outcome {
        $delta=SemanticDelta::compare($before,$after);
        if(!$delta->isOk()){
            return $delta;
        }
        $d=$delta->value();
        $feature=ObservationFeatureSet::compose(
            $assessment,$d,$captures,$boundedInput,
        );
        if(!$feature->isOk()){
            return $feature;
        }
        $f=$feature->value();
        if(!ObservationFeatureSet::verify($f)
            || $f['section_delta_count']!==$d['delta_count']
            || $f['source_lineage']['semantic_delta_digest']!==$d['delta_digest']
            || $f['source_lineage']['assessment_metadata_digest']!==$assessment['lineage_digest']
            || $f['current_source_use_admitted']!==false
            || $f['model_or_external_action_authorized']!==false
            || $f['customer_release_authorized']!==false
            || $f['independent_corroboration_count']!==null
            || $f['distinct_real_world_event_count']!==null
            || $d['commercial_significance']!=='NOT_ASSESSED'
            || $d['source_use_rights_rechecked']!==false) {
            return Outcome::refuse('CR04_UPSTREAM_CANDIDATE_AUTHORITY_OR_LINEAGE_INVALID');
        }
        if(!is_array($d['deltas'])||!array_is_list($d['deltas'])
            || count($d['deltas'])>self::MAX_ROWS) {
            return Outcome::refuse('CR04_SECTION_ROW_LIMIT');
        }
        $rows=[];
        foreach($d['deltas'] as $row){
            if(!is_array($row)
                || !is_string($row['change_kind']??null)
                || !is_string($row['alignment']??null)
                || !is_array($row['sensitive_token_kinds']??null)
                || !array_is_list($row['sensitive_token_kinds'])
                || !is_array($row['uncertainty_codes']??null)
                || !array_is_list($row['uncertainty_codes'])
                || ($row['source_excerpt_not_exported']??null)!==true
                || ($row['original_byte_offsets_verified']??null)!==false){
                return Outcome::refuse('CR04_DERIVED_SECTION_NOT_SAFE');
            }
            $rows[]=[
                'change_kind'=>$row['change_kind'],
                'alignment'=>$row['alignment'],
                'before_type'=>$row['before_type'],
                'after_type'=>$row['after_type'],
                'before_ordinal'=>$row['before_ordinal'],
                'after_ordinal'=>$row['after_ordinal'],
                // DOM locators may contain attacker-controlled attributes:
                // store their digests, never selector/excerpt/source text.
                'before_locator_digest'=>self::locator($row['before_selector']),
                'after_locator_digest'=>self::locator($row['after_selector']),
                'before_text_sha256'=>$row['before_text_sha256'],
                'after_text_sha256'=>$row['after_text_sha256'],
                'sensitive_token_kinds'=>$row['sensitive_token_kinds'],
                'uncertainty_codes'=>$row['uncertainty_codes'],
                'source_excerpt_exported'=>false,
                'original_byte_offsets_verified'=>false,
            ];
        }
        $lineage=$f['source_lineage'];
        $summary=[
            'schema_version'=>self::VERSION,
            'type'=>'CR04_OFFLINE_OBSERVATION_CONTEXT_CANDIDATE',
            'status'=>'HOLD_SOURCE_USE_AND_MODEL_PURPOSE_NOT_ADOPTED',
            'scope'=>$f['scope'],
            'capture_binding'=>[
                'before_snapshot_id'=>$lineage['before_snapshot_id'],
                'after_snapshot_id'=>$lineage['after_snapshot_id'],
                'before_evidence_id'=>$lineage['before_evidence_id'],
                'after_evidence_id'=>$lineage['after_evidence_id'],
                'source_card_sha256'=>$d['source_scope']['source_card_sha256'],
                'p01_canonical_content_hash'=>$lineage['p01_canonical_hash_not_raw_html'],
                'before_raw_html_sha256'=>$lineage['before_raw_html_sha256'],
                'after_raw_html_sha256'=>$lineage['after_raw_html_sha256'],
            ],
            'source_versions'=>[
                'document_projection'=>DocumentProjectionContract::VERSION,
                'parser'=>DocumentProjectionContract::PARSER_VERSION,
                'normalizer'=>DocumentProjectionContract::NORMALIZER_VERSION,
                'semantic_delta'=>SemanticDelta::VERSION,
                'delta_algorithm'=>SemanticDelta::ALGORITHM_VERSION,
                'observation_features'=>ObservationFeatureSet::VERSION,
            ],
            'provenance_digests'=>[
                'before_projection'=>$d['before']['projection_digest'],
                'after_projection'=>$d['after']['projection_digest'],
                'semantic_delta'=>$d['delta_digest'],
                'observation_features'=>$f['feature_digest'],
                'assessment_metadata'=>$assessment['lineage_digest'],
            ],
            'observations'=>$rows,
            'observation_count'=>count($rows),
            'source_count_distinctions'=>[
                'capture_count'=>$f['byte_class_summary']['capture_count'],
                'exact_byte_classes'=>$f['byte_class_summary']['distinct_exact_payload_count'],
                'exact_repeats'=>$f['byte_class_summary']['exact_byte_repeat_count'],
                'real_world_event_count'=>null,
                'independent_origin_count'=>null,
            ],
            'time_bounds'=>[
                'before_observed'=>$d['before']['observed_at'],
                'after_observed'=>$d['after']['observed_at'],
                'before_known'=>$d['before']['known_at'],
                'after_known'=>$d['after']['known_at'],
            ],
            'structural_classification'=>$d['structural_classification'],
            'untrusted_input_flags'=>[
                'html_and_jsonld_untrusted'=>true,
                'locators_are_derived_not_original_offsets'=>true,
                'source_instructions_are_data_not_model_roles'=>true,
                'redaction_and_current_use_not_authenticated'=>true,
            ],
            'unknown_dimensions'=>$d['unknown_dimensions'],
            'business_materiality'=>'NOT_ASSESSED',
            'provider_token_estimate'=>null,
            'interpretation_only'=>true,
            'source_excerpt_exported'=>false,
            'source_bytes_exported'=>false,
            'current_source_rights_authenticated'=>false,
            'historical_reuse_admitted'=>false,
            'model_context_authorized'=>false,
            'customer_output_authorized'=>false,
            'runtime_persistence_authorized'=>false,
            'production_activation_authorized'=>false,
        ];
        $summary['context_digest']=Digest::canonicalDigest($summary);
        if(strlen(Digest::canonicalize($summary))>self::MAX_CONTEXT_BYTES){
            return Outcome::refuse('CR04_BOUNDED_CONTEXT_BYTE_LIMIT');
        }
        return Outcome::ok($summary);
    }

    /** Integrity and authority checks; not original M05 source authentication. */
    public static function verify(array $value): Outcome
    {
        if(!self::exact($value,self::ROOT_KEYS)
            || ($value['schema_version']??null)!==self::VERSION
            || ($value['type']??null)!=='CR04_OFFLINE_OBSERVATION_CONTEXT_CANDIDATE'
            || ($value['status']??null)!=='HOLD_SOURCE_USE_AND_MODEL_PURPOSE_NOT_ADOPTED'
            || ($value['business_materiality']??null)!=='NOT_ASSESSED'
            || $value['provider_token_estimate']!==null
            || ($value['interpretation_only']??null)!==true
            || !is_string($value['context_digest'])
            || preg_match(self::HEX,$value['context_digest'])!==1
            || !is_array($value['scope'])
            || !is_array($value['capture_binding'])
            || !is_array($value['source_versions'])
            || !is_array($value['provenance_digests'])
            || !is_array($value['time_bounds'])
            || !is_array($value['source_count_distinctions'])
            || !is_array($value['untrusted_input_flags'])
            || !is_array($value['unknown_dimensions'])
            || !array_is_list($value['unknown_dimensions'])
            || !is_array($value['observations'])
            || !array_is_list($value['observations'])
            || count($value['observations'])>self::MAX_ROWS
            || ($value['observation_count']??null)!==count($value['observations'])){
            return Outcome::refuse('CR04_CONTEXT_CONTRACT_INVALID');
        }
        foreach([
            'source_excerpt_exported','source_bytes_exported',
            'current_source_rights_authenticated','historical_reuse_admitted',
            'model_context_authorized','customer_output_authorized',
            'runtime_persistence_authorized','production_activation_authorized',
        ] as $flag){
            if($value[$flag]!==false) {
                return Outcome::refuse('CR04_CONTEXT_AUTHORITY_FORGED');
            }
        }
        if($value['untrusted_input_flags']!==[
            'html_and_jsonld_untrusted'=>true,
            'locators_are_derived_not_original_offsets'=>true,
            'source_instructions_are_data_not_model_roles'=>true,
            'redaction_and_current_use_not_authenticated'=>true,
        ] || $value['source_count_distinctions']['real_world_event_count']!==null
            || $value['source_count_distinctions']['independent_origin_count']!==null){
            return Outcome::refuse('CR04_CONTEXT_CLAIM_CEILING_INVALID');
        }
        if(count($value['unknown_dimensions'])>32){
            return Outcome::refuse('CR04_UNKNOWN_DIMENSION_LIMIT');
        }
        foreach($value['unknown_dimensions'] as $code){
            if(!is_string($code)||preg_match('/^[A-Z0-9_]{1,96}$/D',$code)!==1){
                return Outcome::refuse('CR04_UNTRUSTED_UNKNOWN_DIMENSION');
            }
        }
        if(!self::exact($value['scope'],[
            'tenant_id','workspace_id','competitor_id',
            'monitored_source_id','source_ref_id','source_card_id',
        ]) || !self::exact($value['capture_binding'],[
            'before_snapshot_id','after_snapshot_id',
            'before_evidence_id','after_evidence_id',
            'source_card_sha256','p01_canonical_content_hash',
            'before_raw_html_sha256','after_raw_html_sha256',
        ]) || !self::exact($value['provenance_digests'],[
            'before_projection','after_projection','semantic_delta',
            'observation_features','assessment_metadata',
        ]) || !self::exact($value['time_bounds'],[
            'before_observed','after_observed','before_known','after_known',
        ]) || !self::exact($value['source_versions'],[
            'document_projection','parser','normalizer',
            'semantic_delta','delta_algorithm','observation_features',
        ]) || !self::exact($value['source_count_distinctions'],[
            'capture_count','exact_byte_classes','exact_repeats',
            'real_world_event_count','independent_origin_count',
        ])){
            return Outcome::refuse('CR04_CONTEXT_TYPED_BINDING_INVALID');
        }
        foreach($value['scope'] as $id){
            if(!Ids::isValid($id)){
                return Outcome::refuse('CR04_SCOPE_ID_INVALID');
            }
        }
        foreach($value['capture_binding'] as $key=>$valuePart){
            if(str_ends_with($key,'_id')){
                if(!Ids::isValid($valuePart)){
                    return Outcome::refuse('CR04_CAPTURE_ID_INVALID');
                }
            }elseif(!is_string($valuePart)||preg_match(self::HEX,$valuePart)!==1){
                return Outcome::refuse('CR04_CAPTURE_DIGEST_INVALID');
            }
        }
        foreach($value['provenance_digests'] as $hash){
            if(!is_string($hash)||preg_match(self::HEX,$hash)!==1){
                return Outcome::refuse('CR04_PROVENANCE_DIGEST_INVALID');
            }
        }
        $versions=$value['source_versions'];
        if($versions['document_projection']!==DocumentProjectionContract::VERSION
            || $versions['parser']!==DocumentProjectionContract::PARSER_VERSION
            || $versions['normalizer']!==DocumentProjectionContract::NORMALIZER_VERSION
            || $versions['semantic_delta']!==SemanticDelta::VERSION
            || $versions['delta_algorithm']!==SemanticDelta::ALGORITHM_VERSION
            || $versions['observation_features']!==ObservationFeatureSet::VERSION){
            return Outcome::refuse('CR04_VERSION_MISMATCH');
        }
        $time=$value['time_bounds'];
        foreach($time as $clock){
            if(!Clock::isCanonical($clock)){
                return Outcome::refuse('CR04_SOURCE_CLOCK_INVALID');
            }
        }
        if($time['before_observed']>=$time['after_observed']
            || $time['before_known']>$time['after_known']
            || $time['before_observed']>$time['before_known']
            || $time['after_observed']>$time['after_known']){
            return Outcome::refuse('CR04_SOURCE_CLOCK_ORDER_INVALID');
        }
        $counts=$value['source_count_distinctions'];
        foreach(['capture_count','exact_byte_classes','exact_repeats'] as $counter){
            if(!is_int($counts[$counter])||$counts[$counter]<0){
                return Outcome::refuse('CR04_CAPTURE_COUNT_INVALID');
            }
        }
        if($counts['capture_count']<1
            || $counts['exact_byte_classes']>$counts['capture_count']
            || $counts['exact_repeats']!==$counts['capture_count']-$counts['exact_byte_classes']){
            return Outcome::refuse('CR04_CAPTURE_COUNT_INVALID');
        }
        if(!is_string($value['structural_classification'])
            || preg_match('/^[A-Z0-9_]{1,64}$/D',$value['structural_classification'])!==1){
            return Outcome::refuse('CR04_STRUCTURAL_CODE_INVALID');
        }
        foreach($value['observations'] as $row){
            if(!is_array($row)
                || !self::exact($row,self::ROW_KEYS)
                || !is_string($row['change_kind'])
                || preg_match('/^[A-Z0-9_]{1,64}$/D',$row['change_kind'])!==1
                || !in_array($row['alignment'],[
                    'EXACT_DERIVED_SELECTOR','WEAK_UNIQUE_TYPE_AND_BODY','UNPAIRED',
                ],true)
                || ($row['before_type']!==null
                    && !in_array($row['before_type'],DocumentProjectionContract::TYPES,true))
                || ($row['after_type']!==null
                    && !in_array($row['after_type'],DocumentProjectionContract::TYPES,true))
                || ($row['before_ordinal']!==null
                    && (!is_int($row['before_ordinal'])||$row['before_ordinal']<0))
                || ($row['after_ordinal']!==null
                    && (!is_int($row['after_ordinal'])||$row['after_ordinal']<0))
                || !is_array($row['sensitive_token_kinds'])
                || !array_is_list($row['sensitive_token_kinds'])
                || count($row['sensitive_token_kinds'])>16
                || !is_array($row['uncertainty_codes'])
                || !array_is_list($row['uncertainty_codes'])
                || count($row['uncertainty_codes'])>16
                || ($row['source_excerpt_exported']??null)!==false
                || ($row['original_byte_offsets_verified']??null)!==false){
                return Outcome::refuse('CR04_UNSAFE_CONTEXT_ROW');
            }
            foreach(array_merge($row['sensitive_token_kinds'],$row['uncertainty_codes']) as $code){
                if(!is_string($code)||preg_match('/^[A-Z0-9_]{1,96}$/D',$code)!==1) {
                    return Outcome::refuse('CR04_UNTRUSTED_CONTEXT_CODE');
                }
            }
            foreach(['before_locator_digest','after_locator_digest',
                     'before_text_sha256','after_text_sha256'] as $key){
                if($row[$key]!==null
                    && (!is_string($row[$key])||preg_match(self::HEX,$row[$key])!==1)){
                    return Outcome::refuse('CR04_CONTEXT_ROW_DIGEST_INVALID');
                }
            }
        }
        try{
            if(strlen(Digest::canonicalize($value))>self::MAX_CONTEXT_BYTES){
                return Outcome::refuse('CR04_BOUNDED_CONTEXT_BYTE_LIMIT');
            }
            $digest=$value['context_digest'];unset($value['context_digest']);
            if(!hash_equals($digest,Digest::canonicalDigest($value))){
                return Outcome::refuse('CR04_CONTEXT_DIGEST_MISMATCH');
            }
        }catch(\Throwable){
            return Outcome::refuse('CR04_CONTEXT_ENCODING_INVALID');
        }
        return Outcome::ok(['integrity'=>'SELF_HASHED_CANDIDATE_ONLY',
            'model_context_authorized'=>false]);
    }

    /** Existing live provider boundaries are NOT upgraded by this projection. */
    public static function forProviderContext(array $context):Outcome
    {
        return Outcome::refuse('CR04_U01_U02_U03_U06_NATIVE_CONTEXT_NOT_ADOPTED');
    }

    private static function locator(mixed $selector):?string
    {
        return is_string($selector) ? hash('sha256',$selector) : null;
    }

    /** @param list<string> $keys */
    private static function exact(array $value,array $keys):bool
    {
        $actual=array_keys($value);
        sort($actual,SORT_STRING);sort($keys,SORT_STRING);
        return $actual===$keys;
    }
}
