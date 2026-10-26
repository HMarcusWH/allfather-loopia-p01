<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

/**
 * LI-06A: closed, offline vocabulary only. Canonical M17 is ARCHITECTURE_SPEC_ONLY.
 * These strings confer no M17 implementation authorization, source right or M13 calendar.
 */
final class Vocabulary
{
    public const VERSION = 'li06a.offline.vocabulary.v1';
    public const SUPPORTED_CLASSES = ['SOURCE_DIFF', 'COMPANY_SIGNAL'];
    public const INERT_CLASSES = [
        'ENTITY_FACT', 'ENTITY_RELATIONSHIP', 'OFFERING_CLAIM', 'FINANCIAL_METRIC',
        'WEBSITE_CHANGE', 'SEO_SIGNAL', 'SALES_ACTIVITY', 'CUSTOMER_FEEDBACK',
        'LEGAL_RISK', 'CYBER_SECURITY_SIGNAL', 'MARKET_CONTEXT', 'OPERATOR_NOTE',
        'RELEASE_EVENT', 'AUDIT_FINDING', 'PROMPT_RUN', 'PROMPT_FEEDBACK',
        'PROMPT_EXPERIMENT', 'TENANT_ADAPTATION_EVENT', 'CODE_ADAPTATION_EVENT',
        'EXECUTION_OUTCOME',
    ];
    public const WINDOW_KINDS = ['DAY', 'WEEK', 'MONTH'];
    public const NOT_IMPLEMENTED_KINDS = [
        'HOUR', 'QUARTER', 'YEAR', 'ROLLING', 'CALENDAR', 'CAMPAIGN_PERIOD',
        'FISCAL_PERIOD', 'EVENT_BOUNDED',
    ];
    public const LEVELS = [
        'LOCKED_LAW', 'MODULE_DEFAULT', 'MODALITY_DEFAULT', 'TENANT', 'WORKSPACE',
        'ENTITY', 'DATA_CLASS_OVERRIDE', 'SOURCE_CLASS_OVERRIDE', 'RISK_OVERRIDE',
        'OPERATOR_OVERRIDE',
    ];
    public const CANDIDATE_LEVELS = [
        'TENANT', 'WORKSPACE', 'DATA_CLASS_OVERRIDE', 'RISK_OVERRIDE', 'OPERATOR_OVERRIDE',
    ];
    public const AGGRESSIVENESS = ['NONE', 'LOW', 'MEDIUM', 'HIGH'];
    public const LINEAGE = ['EVERY_INPUT_REF', 'REPRESENTATIVE_REFS_PLUS_COUNTS'];
    public const POLARITY = ['SUPPORT', 'CONTRARY', 'UNKNOWN'];
    public const FLOORS = [
        'LEGAL_HOLD', 'AUDIT_HOLD', 'EXECUTION_AUDIT_HOLD',
        'PROMPT_PROMOTION_AUDIT_HOLD', 'ADAPTATION_DEPLOYMENT_AUDIT_HOLD',
        'SOURCE_USE_CEILING', 'CUSTOMER_OUTPUT_CEILING', 'EVIDENCE_LINEAGE_MINIMUM',
        'PRIVACY_RETENTION', 'TENANT_ISOLATION',
    ];

    public static function id(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value) === 1;
    }

    /** @param array<string,mixed> $value @param list<string> $names */
    public static function exactKeys(array $value, array $names): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($names, SORT_STRING);
        return $actual === $names;
    }

    public static function utc(mixed $value): bool
    {
        if (!is_string($value) || preg_match(
            '/^(\d{4})-(\d\d)-(\d\d)T(\d\d):(\d\d):(\d\d)Z$/D', $value, $m,
        ) !== 1) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
    }

    /** A closed P01 metadata scope, not a P02 twin or a permission grant. */
    public static function scope(mixed $value): bool
    {
        if (!is_array($value) || !self::exactKeys($value, [
            'tenant_id', 'workspace_id', 'competitor_id', 'question_id',
        ])) {
            return false;
        }
        foreach ($value as $id) {
            if (!self::id($id)) {
                return false;
            }
        }
        return true;
    }
}
