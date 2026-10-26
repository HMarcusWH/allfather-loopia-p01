<?php

declare(strict_types=1);

use Allfather\Loopia\Substrate\M17\{InputSet,MemoryStore,RollupEngine};
use Allfather\Loopia\Tests\{Assert,Li07aFixture};
use Allfather\Loopia\Db\Database;
use Allfather\Loopia\Core\Clock;

require_once dirname(__DIR__).'/support/Li07aFixture.php';
$inputs=Li07aFixture::inputs();
$a=MemoryStore::preview($inputs);
Assert::true($a->isOk(),'M17 candidate preview validates existing deterministic LI07A inputs');
if ($a->isOk()) {
 $v=$a->value();
 Assert::same('NOT_ADMITTED',$v['candidate_state'],'preview cannot approve a memory');
 Assert::same('P01_COMPETITOR',$v['identity']['subject_kind'],'P01 namespace remains typed, not a fake P02 twin');
 Assert::same($v['rollup']['input_digest'],$v['identity']['input_digest'],'identity freezes exact referenced input digest');
 Assert::same($v['rollup']['profile_resolution_digest'],$v['identity']['profile_digest'],'identity freezes exact profile');
 Assert::same(false,$v['memory_is_evidence'],'memory cannot be an M05 evidence object');
 Assert::same(false,$v['memory_is_release'],'memory cannot be released intelligence');
 Assert::same($v['derivation_identity_digest'],MemoryStore::preview($inputs)->value()['derivation_identity_digest'],'exact same basis replay-stable');
}
$rotated=$inputs;
$rotated['input_set']['refs']=array_reverse($rotated['input_set']['refs']);
Assert::same('M17_ROLLUP_INPUT_INVALID',MemoryStore::preview($rotated)->reasonCode(),
    'corrupt caller ordering does not pass InputSet integrity');
$bad=$inputs;
$bad['input_set']['scope']['competitor_id']='P02_TWIN_IMPERSONATION';
Assert::same('M17_ROLLUP_INPUT_INVALID',MemoryStore::preview($bad)->reasonCode(),
    'self-asserted P02 mapping breaks hash and refuses');
// No database connection is required: the gate must refuse before accessing it.
$dummyDb=(new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
$refusing=MemoryStore::forDisposableTest($dummyDb,new Clock());
Assert::same('M17_U04_NOT_ADOPTED_PERSISTENCE_DISABLED',$refusing->reasonCode(),
    'unapproved PHP code cannot instantiate the disposable persistence writer');
