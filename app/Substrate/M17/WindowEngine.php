<?php

declare(strict_types=1);

namespace Allfather\Loopia\Substrate\M17;

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Core\Outcome;

/**
 * LI-06A pure DAY/WEEK/MONTH assignment and offline resolution candidate.
 * No M13 calendar is read, no run is scheduled, and no memory is persisted.
 */
final class WindowEngine
{
    public const VERSION = 'li06a.offline-window-engine.v1';
    public const SUPPORTED_ZONES = ['UTC', 'Europe/Stockholm'];

    /** @param array<string,mixed> $input */
    public static function assign(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, ['kind','observation_at_utc','calendar_config'])
            || !in_array($input['kind'], Vocabulary::WINDOW_KINDS, true)
            || !Vocabulary::utc($input['observation_at_utc'])) {
            return Outcome::refuse('M17_WINDOW_CONTRACT_INVALID');
        }
        $config = $input['calendar_config'];
        $zoneName = 'UTC';
        $origin = 'UTC_ISO_FALLBACK';
        $version = timezone_version_get();
        if ($config !== null) {
            if (!is_array($config) || !Vocabulary::exactKeys($config, ['timezone','tzdb_version'])
                || !in_array($config['timezone'], self::SUPPORTED_ZONES, true)
                || !is_string($config['tzdb_version']) || $config['tzdb_version'] !== $version) {
                return Outcome::refuse('M17_CALENDAR_NOT_VERIFIED_OR_SUPPORTED');
            }
            $zoneName = $config['timezone'];
            $origin = 'CALLER_DECLARED_NOT_M13_VERIFIED';
        }
        try {
            $utc = new \DateTimeZone('UTC');
            $localZone = new \DateTimeZone($zoneName);
            $observed = new \DateTimeImmutable($input['observation_at_utc'], $utc);
            $local = $observed->setTimezone($localZone);
            $start = $local->setTime(0,0,0);
            $end = match ($input['kind']) {
                'DAY' => $start->modify('+1 day'),
                'WEEK' => $start->modify('-' . ((int) $start->format('N') - 1) . ' days')
                    ->modify('+1 week'),
                'MONTH' => $start->modify('first day of this month')->modify('+1 month'),
            };
            if ($input['kind'] === 'WEEK') {
                $start = $start->modify('-' . ((int) $start->format('N') - 1) . ' days');
            } elseif ($input['kind'] === 'MONTH') {
                $start = $start->modify('first day of this month');
            }
            $format = 'Y-m-d\TH:i:s\Z';
            $startUtc = $start->setTimezone($utc)->format($format);
            $endUtc = $end->setTimezone($utc)->format($format);
            if ($input['observation_at_utc'] < $startUtc || $input['observation_at_utc'] >= $endUtc) {
                return Outcome::refuse('M17_WINDOW_BOUNDARY_ERROR');
            }
            $candidate = [
                'version'=>self::VERSION, 'kind'=>$input['kind'],
                'observation_at_utc'=>$input['observation_at_utc'],
                'start_inclusive_utc'=>$startUtc, 'end_exclusive_utc'=>$endUtc,
                'calendar_timezone'=>$zoneName, 'calendar_origin'=>$origin,
                'tzdb_version'=>$version,
                'start_offset_seconds'=>$start->getOffset(),
                'end_offset_seconds'=>$end->getOffset(),
                'duration_seconds'=>$end->getTimestamp() - $start->getTimestamp(),
                'historical_reuse_admitted'=>false,
            ];
            return Outcome::ok([...$candidate, 'window_digest'=>Digest::canonicalDigest($candidate)]);
        } catch (\Throwable) {
            return Outcome::refuse('M17_WINDOW_ASSIGNMENT_FAILED');
        }
    }

    /**
     * An explicit resolution *proposal* from a hash-bound offline profile and fixed UTC time.
     * DAY→WEEK@14d→MONTH@90d for SOURCE_DIFF, 30/180 for COMPANY_SIGNAL. HIGH or UNKNOWN
     * volatility holds the finest window (conservative), never authorizes a model/cadence.
     * @param array<string,mixed> $input
     */
    public static function choose(array $input): Outcome
    {
        if (!Vocabulary::exactKeys($input, [
            'data_class', 'observation_at_utc', 'evaluation_time_utc',
            'profile_candidate', 'volatility_level',
        ]) || !in_array($input['data_class'], Vocabulary::SUPPORTED_CLASSES, true)
            || !Vocabulary::utc($input['observation_at_utc'])
            || !Vocabulary::utc($input['evaluation_time_utc'])
            || !in_array($input['volatility_level'], ['HIGH','LOW','UNKNOWN'], true)
            || !is_array($input['profile_candidate'])
            || !ProfileResolver::verify($input['profile_candidate'])->isOk()
            || ($input['profile_candidate']['data_class'] ?? null) !== $input['data_class']
            || ($input['profile_candidate']['status'] ?? null) !== 'OFFLINE_NOT_ADMITTED'
            || ($input['profile_candidate']['evaluation_time_utc'] ?? null) !== $input['evaluation_time_utc']) {
            return Outcome::refuse('M17_RESOLUTION_CONTRACT_INVALID');
        }
        if (($input['profile_candidate']['event_level_hold'] ?? null) !== false) {
            return Outcome::refuse('M17_HOLD_REQUIRES_UNIMPLEMENTED_EVENT_WINDOW');
        }
        $start = strtotime($input['observation_at_utc']);
        $now = strtotime($input['evaluation_time_utc']);
        if ($start === false || $now === false || $now < $start) {
            return Outcome::refuse('M17_RESOLUTION_CLOCK_INVALID');
        }
        $ageDays = intdiv($now - $start, 86400);
        $p = $input['profile_candidate']['resolved'];
        if ($input['volatility_level'] !== 'LOW') {
            $kind = 'DAY';
            $reason = $input['volatility_level'] === 'HIGH'
                ? 'VOLATILITY_HOLD_CANDIDATE' : 'UNKNOWN_VOLATILITY_CONSERVATIVE';
        } elseif ($ageDays < $p['day_until_age_days']) {
            $kind = 'DAY';
            $reason = 'AGE_LADDER';
        } elseif ($ageDays < $p['week_until_age_days']) {
            $kind = 'WEEK';
            $reason = 'AGE_LADDER';
        } else {
            $kind = 'MONTH';
            $reason = 'AGE_LADDER';
        }
        return Outcome::ok([
            'version'=>self::VERSION, 'kind'=>$kind, 'age_days'=>$ageDays,
            'reason_code'=>$reason,
            'profile_resolution_digest'=>$input['profile_candidate']['resolution_digest'],
            'resolution_is_schedule'=>false, 'historical_reuse_admitted'=>false,
        ]);
    }

    public static function verifyWindow(array $window): Outcome
    {
        if (!Vocabulary::exactKeys($window, [
            'version','kind','observation_at_utc','start_inclusive_utc','end_exclusive_utc',
            'calendar_timezone','calendar_origin','tzdb_version','start_offset_seconds',
            'end_offset_seconds','duration_seconds','historical_reuse_admitted','window_digest',
        ]) || ($window['version'] ?? null) !== self::VERSION
            || ($window['historical_reuse_admitted'] ?? null) !== false
            || !is_string($window['window_digest'])
            || preg_match('/^[a-f0-9]{64}$/D', $window['window_digest']) !== 1) {
            return Outcome::refuse('M17_WINDOW_SHAPE_INVALID');
        }
        $config = ($window['calendar_origin'] ?? null) === 'UTC_ISO_FALLBACK'
            ? null : ['timezone'=>$window['calendar_timezone'],
                      'tzdb_version'=>$window['tzdb_version']];
        $replay = self::assign([
            'kind'=>$window['kind'], 'observation_at_utc'=>$window['observation_at_utc'],
            'calendar_config'=>$config,
        ]);
        if (!$replay->isOk() || $replay->value() !== $window) {
            return Outcome::refuse('M17_WINDOW_REPLAY_MISMATCH');
        }
        return Outcome::ok(['digest_verified'=>true, 'historical_reuse_admitted'=>false]);
    }
}
