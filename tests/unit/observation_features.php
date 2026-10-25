<?php

declare(strict_types=1);

use Allfather\Loopia\Product\P01\ObservationFeatures;
use Allfather\Loopia\Tests\Assert;

// All inputs are synthetic, and these pure calls have NO storage or network access.
$case = static function (string $before, string $after, string $format='TEXT_PLAIN'): array {
    return [
        'before_body'=>$before, 'after_body'=>$after,
        'before_body_sha256'=>hash('sha256',$before),
        'after_body_sha256'=>hash('sha256',$after),
        'before_evidence_id'=>'fixture-e1', 'after_evidence_id'=>'fixture-e2',
        'before_snapshot_id'=>'fixture-s1', 'after_snapshot_id'=>'fixture-s2',
        'format'=>$format,
    ];
};
$extract = static function (array $input): array {
    $result=ObservationFeatures::extract($input);
    Assert::true($result->isOk(),'valid synthetic feature input accepted');
    return $result->isOk() ? $result->value() : [];
};

$identical=$extract($case('Unchanged factual text','Unchanged factual text'));
Assert::same('BYTE_IDENTICAL',$identical['status']??null,'identical payload is not an event');
Assert::same([],$identical['features']??null,'byte repeat yields no changed features');

$navBefore='<nav class="header"><a href="/studios">Studios</a><a href="/pricing">Pricing</a></nav>';
$navAfter='<nav class="header"><a href="/pricing">Pricing</a><a href="/studios">Studios</a></nav>';
$nav=$extract($case($navBefore,$navAfter,'HTML_NAV_SUBSET'));
Assert::same('FEATURES_OBSERVED_NOT_ASSESSED',$nav['status']??null,'supported reordered menu is a descriptive feature');
Assert::same(1,count($nav['features']??[]),'one navigation order feature, not an inferred launch');
Assert::same('NAV_ORDER_CHANGED',$nav['features'][0]['kind']??null,'navigation permutation found');
Assert::same(['Studios','Pricing'],$nav['features'][0]['before_order_untrusted']??null,'original source order retained as untrusted data');
Assert::same(['Pricing','Studios'],$nav['features'][0]['after_order_untrusted']??null,'new source order retained as untrusted data');
Assert::same(['fixture-e1','fixture-e2'],$nav['features'][0]['evidence_refs']??null,'exact evidence refs bind the candidate');
Assert::same('NOT_ASSESSED',$nav['business_materiality']??null,'menu order is not business impact');
Assert::same(false,$nav['release_authorized']??null,'feature extraction cannot release');

$navEntity=$extract($case(
    '<nav><a>Studios &amp; Music</a><a>Pricing</a></nav>',
    '<nav><a>Pricing</a><a>Studios & Music</a></nav>',
    'HTML_NAV_SUBSET',
));
Assert::same('NAV_ORDER_CHANGED',$navEntity['features'][0]['kind']??null,'equivalent entity encodings normalize labels without inventing pages');
$multiNav=$extract($case(
    '<nav><a>A</a><a>B</a></nav><nav><a>C</a><a>D</a></nav>',
    '<nav><a>B</a><a>A</a></nav>',
    'HTML_NAV_SUBSET',
));
Assert::same([],$multiNav['features']??null,'multiple navigation blocks are not guessed into one ordering');
Assert::true(in_array('NAV_SUBSET_UNSUPPORTED_OR_AMBIGUOUS',$multiNav['extraction_notes']??[],true),'ambiguous navigation marked explicitly');

$price=$extract($case('Monthly subscription: 449 SEK','Monthly subscription: 499 SEK'));
Assert::same('PRICE_TOKEN_CHANGED',$price['features'][0]['kind']??null,'single price digit preserved');
Assert::same(44900,$price['features'][0]['before_minor_units']??null,'before price canonical minor units');
Assert::same(49900,$price['features'][0]['after_minor_units']??null,'after price canonical minor units');
Assert::same('SEK',$price['features'][0]['currency']??null,'currency not inferred from tenant locale');
Assert::same('NOT_ASSESSED',$price['business_materiality']??null,'small structural diff never suppresses price change');
$kr=$extract($case('Monthly: 449 kr','Monthly: 499 SEK'));
Assert::same('PRICE_TOKEN_CHANGED',$kr['features'][0]['kind']??null,'kr and SEK are one typed currency');
$decimal=$extract($case('Fee: EUR 12,50','Fee: EUR 13,10'));
Assert::same(1250,$decimal['features'][0]['before_minor_units']??null,'decimal price parsed without floats');
Assert::same(1310,$decimal['features'][0]['after_minor_units']??null,'decimal after value preserves cents');

$many=$extract($case('Products: 449 SEK, 200 SEK','Products: 499 SEK, 200 SEK'));
Assert::same([],$many['features']??null,'multiple listed prices never paired opportunistically');
Assert::true(in_array('PRICE_TOKENS_AMBIGUOUS',$many['extraction_notes']??[],true),'unmatched price basis reported uncertain');
$changedCurrency=$extract($case('Fee 449 SEK','Fee 449 EUR'));
Assert::same([],$changedCurrency['features']??null,'currency change cannot be read as arithmetic gain');
Assert::true(in_array('PRICE_CURRENCY_CHANGED_NO_MAGNITUDE_COMPARISON',$changedCurrency['extraction_notes']??[],true),'currency mismatch noted');

$negation=$extract($case('Free trial is available','Free trial is not available'));
Assert::same('NEGATION_TOKEN_CHANGED',$negation['features'][0]['kind']??null,'not insertion recognized');
Assert::same('AFFIRMATIVE',$negation['features'][0]['before_polarity']??null,'before negation');
Assert::same('NEGATED',$negation['features'][0]['after_polarity']??null,'after negation');
$swedish=$extract($case('Provperiod finns','Provperiod finns inte'));
Assert::same('NEGATION_TOKEN_CHANGED',$swedish['features'][0]['kind']??null,'Swedish inte preserved');
$different=$extract($case('Free trial is available','Free trial is now available'));
Assert::same([],$different['features']??null,'other content changes are not made into negation');

$date=$extract($case('Launch starts on 2026-10-10','Launch starts on 2026-11-10'));
Assert::same('DATE_LITERAL_CHANGED',$date['features'][0]['kind']??null,'exact ISO literal changes detected');
Assert::same(false,$date['features'][0]['event_date_verified']??null,'source-asserted date not certified real-world event time');

$unknown=$extract($case('Raw page text A','Raw page text B'));
Assert::same('UNCLASSIFIED_CHANGE',$unknown['status']??null,'unknown change remains unknown, not no-change');
Assert::same([],$unknown['features']??null,'unknown delta yields no invented semantic feature');
Assert::same('NOT_CHECKED_BY_PURE_EXTRACTOR',$unknown['source_rights']??null,'parser does not certify historical source-use rights');
Assert::true(array_key_exists('independent_origin_count',$unknown),'unknown independent origin is explicitly represented');
Assert::same(null,$unknown['independent_origin_count'],'parser makes no common-origin assertion');
Assert::same(false,$unknown['execution_authorized']??null,'parser cannot spend model budget');

$script=$extract($case(
    '<nav><a>A</a><a>B</a></nav><script>untrusted command</script>',
    '<nav><a>B</a><a>A</a></nav><script>untrusted command</script>',
    'HTML_NAV_SUBSET',
));
Assert::same('UNSUPPORTED_MARKUP',$script['status']??null,'active markup is not parsed by bounded subset');
Assert::same([],$script['features']??null,'unsupported active markup creates no observed feature');

$bad=$case('A','B');$bad['after_body_sha256']=str_repeat('0',64);
Assert::same('LI04_FEATURE_BYTES_DIGEST_MISMATCH',ObservationFeatures::extract($bad)->reasonCode(),'payload SHA cannot be claimed rather than verified');
$bad=$case('A','B');$bad['before_snapshot_id']=$bad['after_snapshot_id'];
Assert::same('LI04_FEATURE_PAIR_NOT_DISTINCT',ObservationFeatures::extract($bad)->reasonCode(),'same snapshot cannot define before-and-after');
$bad=$case('A','B');$bad['format']='UNBOUNDED_HTML';
Assert::same('LI04_FEATURE_FORMAT_UNSUPPORTED',ObservationFeatures::extract($bad)->reasonCode(),'unknown format denied');
$bad=$case('A','B');$bad['new_authority']='APPROVED';
Assert::same('LI04_FEATURE_INPUT_CONTRACT_INVALID',ObservationFeatures::extract($bad)->reasonCode(),'caller cannot add authorizing fields');
$bad=$case('','B');
Assert::same('LI04_FEATURE_BYTES_UNAVAILABLE_OR_INVALID',ObservationFeatures::extract($bad)->reasonCode(),'missing captured bytes are not interpreted as ordinary change');
$bad=$case(str_repeat('A',ObservationFeatures::MAX_BODY_BYTES+1),'B');
Assert::same('LI04_FEATURE_BYTES_UNAVAILABLE_OR_INVALID',ObservationFeatures::extract($bad)->reasonCode(),'oversized retained content refused');
$bad=$case("\xff",'A');
Assert::same('LI04_FEATURE_BYTES_UNAVAILABLE_OR_INVALID',ObservationFeatures::extract($bad)->reasonCode(),'invalid UTF-8 source refused');
