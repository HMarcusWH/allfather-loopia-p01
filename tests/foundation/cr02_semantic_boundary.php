<?php

declare(strict_types=1);

use Allfather\Loopia\Product\P01\SectionFingerprint;
use Allfather\Loopia\Product\P01\SemanticDelta;
use Allfather\Loopia\Tests\Assert;

$root = dirname(__DIR__, 2);
Assert::same('cr02.section-fingerprint.v1', SectionFingerprint::VERSION, 'typed fingerprint contract version');
Assert::same('cr02.semantic-delta.v1', SemanticDelta::VERSION, 'semantic delta version');
Assert::same('cr02.same-selector-unique-content-alignment.v1', SemanticDelta::ALGORITHM_VERSION,
    'alignment algorithm is immutable and auditable');
Assert::same(4096, SemanticDelta::MAX_PAIR_COMPARISONS, '64 x 64 hard alignment limit');
foreach (['SectionFingerprint.php','SemanticDelta.php'] as $file) {
    $source = (string) file_get_contents($root . '/app/Product/P01/' . $file);
    foreach (['curl_exec(', 'CurlHttpsTransport', 'DnsHostResolver',
        'ModelExecutor', 'OpenAIResponsesTransport', 'new \\PDO(', 'new PDO(',
        'mail(', 'fsockopen(', 'shell_exec(', 'proc_open(', 'file_get_contents('] as $forbidden) {
        Assert::false(str_contains($source, $forbidden),
            'CR02 source has no network, provider, DB, filesystem or action side effect: ' . $file);
    }
}
$legacyPaths = [
    'app/Product/P01/ChangeDetection.php' => 'ebcda9228642d8444387bc099cfa1ba5fd428da7',
    'app/Product/P01/TextProvenance.php' => 'f605368d1cceaef79899a785fa56ee1428fcb4b4',
];
foreach ($legacyPaths as $path => $blobSha) {
    $bytes = (string) file_get_contents($root . '/' . $path);
    Assert::same($blobSha, sha1('blob ' . strlen($bytes) . "\0" . $bytes),
        'original native P01 change detector and line-hash code exactly unchanged: ' . $path);
}
$matrix = json_decode((string) file_get_contents($root . '/docs/longitudinal/build-first/CAPABILITY_MATRIX.json'),
    true, 64, JSON_THROW_ON_ERROR);
$rows = array_column($matrix['build_packages'], null, 'id');
Assert::same('BUILT_INERT', $rows['CR-02']['build_status'], 'CR02 remains source-built, not factory-adopted');
foreach (['native_permission','customer_visibility','production_activation','live_migration','external_effects'] as $flag) {
    Assert::same(false, $rows['CR-02'][$flag], 'no native authority introduced: ' . $flag);
}
$doc = (string) file_get_contents($root . '/docs/CR02_SEMANTIC_DELTA_ADR.md');
foreach (['CR-01', 'M05', 'CR-04', 'ORDER_ONLY', 'UNKNOWN', 'NOT_ASSESSED'] as $marker) {
    Assert::contains($marker, $doc, 'CR02 ADR contains upstream and future owner boundary: ' . $marker);
}
