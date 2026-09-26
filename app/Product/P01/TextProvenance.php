<?php
declare(strict_types=1);
namespace Allfather\Loopia\Product\P01;

use Allfather\Loopia\Core\Digest;

/** Canonical PR366 proof contract. No raw source text, model judgment or new retention authority. */
final class TextProvenance
{
    public const VERSION = 'p01_normalized_lines_v1';
    public const CLASSIFIER_VERSION = 'p01_structural_change_v2';

    public static function capture(string $contentHash, string $body): array
    {
        $lines = [];
        foreach (explode("\n", $body) as $line) {
            // Exact ECMAScript trim set; PHP trim alone would mishandle NBSP/BOM and remove NUL.
            $line = preg_replace('/^[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $line);
            if ($line === null) { throw new \InvalidArgumentException('Comparison text must be UTF-8'); }
            if ($line !== '') { $lines[] = Digest::canonicalDigest(['line' => $line]); }
        }
        $lines = array_values(array_unique($lines)); sort($lines, SORT_STRING);
        return ['version' => self::VERSION, 'contentHash' => $contentHash, 'lineHashes' => $lines];
    }

    public static function read(?string $raw, string $contentHash): ?array
    {
        if ($raw === null) { return null; }
        try {
            $object = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) { return null; }
            $p = get_object_vars($object);
            $keys = array_keys($p); sort($keys, SORT_STRING);
            if ($keys !== ['contentHash', 'lineHashes', 'version'] || $p['version'] !== self::VERSION
                || $p['contentHash'] !== $contentHash || !is_array($p['lineHashes']) || !array_is_list($p['lineHashes'])) { return null; }
            foreach ($p['lineHashes'] as $hash) { if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { return null; } }
            $sorted = array_values(array_unique($p['lineHashes'])); sort($sorted, SORT_STRING);
            return $sorted === $p['lineHashes'] ? $p : null;
        } catch (\Throwable) { return null; }
    }

    public static function classify(string $beforeHash, string $afterHash, ?array $before, ?array $after, float $threshold): array
    {
        if ($beforeHash === $afterHash) { return ['significance' => 'NONE', 'summary' => 'no content change (identical capture hash)']; }
        if ($before === null || $after === null) { return ['significance' => 'UNKNOWN', 'summary' => 'content hash changed; retained capture-bound comparison proof unavailable']; }
        $added = count(array_diff($after['lineHashes'], $before['lineHashes']));
        $removed = count(array_diff($before['lineHashes'], $after['lineHashes']));
        $union = count(array_unique([...$before['lineHashes'], ...$after['lineHashes']]));
        $ratio = $union === 0 ? 0.0 : ($added + $removed) / $union;
        return ['significance' => $ratio > 0 && $ratio >= $threshold ? 'MATERIAL' : 'MINOR',
            'summary' => sprintf('structural change: distance=%s, +%d/-%d lines', self::distanceString($ratio), $added, $removed)];
    }

    public static function digest(array $input): string
    {
        $payload = ['version' => self::CLASSIFIER_VERSION,
            'beforeSnapshotId' => $input['beforeSnapshotId'], 'afterSnapshotId' => $input['afterSnapshotId'],
            'beforeContentHash' => $input['beforeContentHash'], 'afterContentHash' => $input['afterContentHash'],
            'beforeTextProvenanceDigest' => $input['before'] === null ? null : Digest::canonicalDigest($input['before']),
            'afterTextProvenanceDigest' => $input['after'] === null ? null : Digest::canonicalDigest($input['after']),
            'threshold' => $input['threshold'], 'significance' => $input['significance'], 'summary' => $input['summary']];
        ksort($payload,SORT_STRING);$parts=[];
        foreach ($payload as $key=>$value) {$parts[]=Digest::canonicalize($key).':'.($key==='threshold'?self::thresholdJson((float)$value):Digest::canonicalize($value));}
        return hash('sha256','{'.implode(',',$parts).'}');
    }

    /** JSON.stringify's decimal/exponent boundary for the bounded finite [0,1] threshold. */
    private static function thresholdJson(float $value): string
    {
        if ($value == 0.0) {return '0';}
        $json=json_encode($value,JSON_THROW_ON_ERROR);
        if (!str_contains($json,'e')) {return $json;}
        [$mantissa,$exponent]=explode('e',$json);$exp=(int)$exponent;
        $mantissa=preg_replace('/\.0$/','',$mantissa);
        if ($exp < -6) {return $mantissa.'e'.$exp;}
        $digits=str_replace('.','',$mantissa);$point=(strpos($mantissa,'.')===false?strlen($mantissa):strpos($mantissa,'.'))+$exp;
        return $point<=0?'0.'.str_repeat('0',-$point).$digits:substr($digits,0,$point).'.'.substr($digits,$point);
    }

    /** JavaScript positive Number.toFixed(3), including binary half ties; PHP sprintf uses ties-to-even. */
    private static function distanceString(float $ratio): string
    {
        if ($ratio < 0.0005) { return '0.000'; }
        $bits = unpack('J', pack('E', $ratio))[1];
        $exponent = ($bits >> 52) & 0x7ff;
        $mantissa = ($bits & 0xfffffffffffff) | (1 << 52);
        $numerator = $mantissa * 1000; // <= (2^53 - 1) * 1000, within signed 64-bit.
        $shift = 1075 - $exponent;
        $mask = $shift === 63 ? PHP_INT_MAX : ((1 << $shift) - 1);
        $scaled = ($numerator >> $shift) + (($numerator & $mask) >= (1 << ($shift - 1)) ? 1 : 0);
        return intdiv($scaled, 1000) . '.' . str_pad((string) ($scaled % 1000), 3, '0', STR_PAD_LEFT);
    }
}
