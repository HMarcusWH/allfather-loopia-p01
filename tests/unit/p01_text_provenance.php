<?php
declare(strict_types=1);
use Allfather\Loopia\Product\P01\TextProvenance;
use Allfather\Loopia\Tests\Assert;
$vectors = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/p01_provenance_vectors.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($vectors['cases'] as $v) {
    $i = $v['input'];
    foreach (['before','after'] as $side) {
        if ($v[$side . 'Text'] === null) { continue; }
        Assert::same($i[$side], TextProvenance::capture($i[$side . 'ContentHash'], $v[$side . 'Text']), $v['name'] . ' canonical proof ' . $side);
        Assert::same($i[$side], TextProvenance::read(json_encode($i[$side], JSON_THROW_ON_ERROR), $i[$side . 'ContentHash']), $v['name'] . ' strict proof round trip');
    }
    Assert::same(['significance'=>$i['significance'],'summary'=>$i['summary']], TextProvenance::classify($i['beforeContentHash'],$i['afterContentHash'],$i['before'],$i['after'],(float)$i['threshold']), $v['name'] . ' canonical classifier including binary rounding');
    Assert::same($v['expectedDigest'], TextProvenance::digest($i), $v['name'] . ' exact canonical digest');
}
$proof = TextProvenance::capture(str_repeat('a',64), "A\nB");
foreach ([null, json_encode([...$proof,'lineHashes'=>new \stdClass()]), '{}', 'false', '{invalid', json_encode([...$proof,'extra'=>true]), json_encode([...$proof,'contentHash'=>'wrong']), json_encode([...$proof,'lineHashes'=>array_reverse($proof['lineHashes'])]), json_encode([...$proof,'lineHashes'=>[$proof['lineHashes'][0],$proof['lineHashes'][0]]])] as $raw) {
    Assert::same(null, TextProvenance::read($raw,str_repeat('a',64)), 'malformed or unbound proof refused');
}
