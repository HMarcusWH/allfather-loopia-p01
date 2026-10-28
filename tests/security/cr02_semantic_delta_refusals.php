<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/Cr02Fixture.php';

use Allfather\Loopia\Core\Digest;
use Allfather\Loopia\Product\P01\SectionFingerprint;
use Allfather\Loopia\Product\P01\SemanticDelta;
use Allfather\Loopia\Tests\Assert;
use Allfather\Loopia\Tests\Cr02Fixture;

$case = Cr02Fixture::cases()['cases'][1]; // price
$before = Cr02Fixture::syntheticProjection($case, 'before');
$after = Cr02Fixture::syntheticProjection($case, 'after');
$refuse = static function (array $b, array $a, string $reason, string $label): void {
    $out = SemanticDelta::compare($b, $a);
    Assert::true($out->isRefusal(), $label . ': fail closed');
    if ($out->isRefusal()) {
        Assert::same($reason, $out->reasonCode(), $label . ': exact reason');
    }
};
$redigest = static function (array $doc): array {
    unset($doc['projection_digest']);
    $doc['projection_digest'] = Digest::canonicalDigest($doc);
    return $doc;
};

$refuse($before, $before, 'CR02_NOT_DISTINCT_CAPTURE_PROJECTIONS', 'same evidence cannot be both timestamps');
$bad = $after;
$bad['customer_visible'] = true;
$refuse($before, $bad, 'CR02_PROJECTION_SHAPE_OR_AUTHORITY', 'caller may not promote derived candidate to customer');
$bad = $after;
$bad['production_authorized'] = true;
$refuse($before, $bad, 'CR02_PROJECTION_SHAPE_OR_AUTHORITY', 'fake production flag refused');
$bad = $after;
$bad['unreviewed_grant'] = 'APPROVED';
$refuse($before, $bad, 'CR02_PROJECTION_SHAPE_OR_AUTHORITY', 'unknown source grant field rejected');
$bad = $after;
$bad['source_binding']['retention_class'] = 'M05_REAL';
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_NATIVE_OWNER_BINDING_NOT_IMPLEMENTED', 'real source-use requires native CR04 owner adapter');
$bad = $after;
$bad['source_binding']['tenant_id'] = '00000000-0000-4000-8000-000000001234';
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_CROSS_SCOPE_OR_CARD_REVISION', 'foreign tenant cannot be joined to original capture');
$bad = $after;
$bad['source_binding']['workspace_id'] = '00000000-0000-4000-8000-000000001234';
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_CROSS_SCOPE_OR_CARD_REVISION', 'foreign workspace cannot be joined');
$bad = $after;
$bad['source_binding']['source_card_sha256'] = str_repeat('d', 64);
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_CROSS_SCOPE_OR_CARD_REVISION', 'unreviewed source card revision cannot silently compare');
$bad = $after;
$bad['source_binding']['monitored_source_id'] = '00000000-0000-4000-8000-000000001234';
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_CROSS_SCOPE_OR_CARD_REVISION', 'different monitored sources cannot be compared');
$bad = $after;
$bad['source_binding']['capture_ref_id'] = $before['source_binding']['capture_ref_id'];
$bad['sections'][0]['origin_id'] = hash('sha256', $bad['source_binding']['capture_ref_id'] . "\0"
    . $bad['source_binding']['source_ref_id'] . "\0" . $bad['sections'][0]['selector']);
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_NOT_DISTINCT_CAPTURE_PROJECTIONS', 'same capture identity even with different body refused');
$bad = $after;
$bad['source_binding']['observed_at'] = '2026-10-27T11:59:00.000Z';
$bad['source_binding']['known_at'] = '2026-10-27T12:01:30.000Z';
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_SNAPSHOT_CLOCK_NOT_FORWARD', 'clock reversal cannot create evidence');
$bad = $after;
$bad['projection_digest'] = str_repeat('0', 64);
$refuse($before, $bad, 'CR02_PROJECTION_DIGEST_MISMATCH', 'projection cannot be modified without current digest');
$bad = $after;
$bad['sections'][0]['text_sha256'] = str_repeat('0', 64);
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_SECTION_TEXT_DIGEST_INCONSISTENT', 'excerpt digest is cross-checked when complete');
$bad = $after;
$bad['sections'][0]['excerpt'] = 'unauthorized new narrative';
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_SECTION_TEXT_DIGEST_INCONSISTENT', 'rewritten source excerpt is not trusted');
$bad = $after;
$bad['sections'][0]['raw_start_byte'] = 10;
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_CR01_PROJECTION_INVALID', 'repaired DOM path never becomes raw byte location');
$bad = $after;
$bad['sections'][0]['text_bytes'] = 5000;
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_SECTION_PROVENANCE_INVALID', 'oversized normalized text cannot bypass bounded source');
$bad = $after;
$bad['sections'][0]['type'] = 'UNKNOWN_SEMANTIC_COMMAND';
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_CR01_PROJECTION_INVALID', 'unknown type cannot invent critical finding');
$bad = $after;
$bad['sections'][0]['selector'] = 'html[1]/body[1]/section[11]';
$bad = $redigest($bad);
$refuse($before, $bad, 'CR02_SECTION_PROVENANCE_INVALID', 'selector changes require matching capture-derived section identity');

$empty = $before;
$empty['sections'] = [];
$empty['section_count'] = 0;
$empty = $redigest($empty);
$unknown = SemanticDelta::compare($empty, $after);
Assert::true($unknown->isOk(), 'zero typed section coverage produces an explicit safe unknown');
if ($unknown->isOk()) {
    Assert::true(in_array('NO_TYPED_SECTION_COVERAGE', $unknown->value()['unknown_dimensions'], true),
        'absence is UNKNOWN, not proven no change');
    Assert::same(false, $unknown->value()['customer_release_authorized'],
        'zero source coverage never authorizes release');
}
Assert::same(64, SectionFingerprint::MAX_SECTIONS, 'maximum section count is bounded');
Assert::same(4096, SemanticDelta::MAX_PAIR_COMPARISONS, 'maximum quadratic alignment effort bounded');
Assert::same(128, SemanticDelta::MAX_DELTAS, 'result list bounded even when all sections unmatched');
