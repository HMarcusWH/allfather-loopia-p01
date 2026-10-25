<?php

declare(strict_types=1);

use Allfather\Loopia\Tests\Assert;

$root=dirname(__DIR__,2);
$stateFile=$root.'/docs/longitudinal/STATUS_LEDGER_2026-10-25.json';
$packagesFile=$root.'/docs/longitudinal/source/Allfather_PHP_Intelligence_Work_Packages_2026-10-25.json';
$state=json_decode((string) file_get_contents($stateFile),true,32,JSON_THROW_ON_ERROR);
$plan=json_decode((string) file_get_contents($packagesFile),true,32,JSON_THROW_ON_ERROR);
Assert::same('LI00_SOURCE_HOST_AUTHORITY_RECONCILIATION',$state['record_type'],'the state file is a dated reconciliation');
Assert::same('2026-10-25',$state['as_of_date'],'dated state never presents as live polling');
Assert::same('RAILWAY_DISPOSABLE_DB_STRICT_10_6_10_11',$state['php_main']['qualification'],'Railway is named as source, not GitHub green');
Assert::same(12458,$state['recent_qualification']['mariadb_10_6']['pass'],'10.6 report count retained');
Assert::same(12458,$state['recent_qualification']['mariadb_10_11']['pass'],'10.11 report count retained');
Assert::same(0,$state['recent_qualification']['mariadb_10_6']['skip'],'no fixture skips manufactured');
Assert::same('NOT_ACCEPTED',$state['quality']['heldout'],'model-quality approval not forged');
Assert::same('NOT_QUERIED_IN_THIS_AUDIT',$state['admitted_execution_grants'],'source state is not runtime grant state');
Assert::same(false,$state['adopted_new_authority'],'the docs grant no authority');
Assert::same('PROPOSED_ENGINEERING_PLAN_NOT_IMPLEMENTATION_OR_AUTHORIZATION',$plan['status'],'source manifest remains proposal');
Assert::same(22,count($plan['work_packages']),'all 22 original package definitions preserved');
Assert::same(10,count($plan['decision_packets']),'all ten owner decisions preserved');
Assert::same('OPEN_UNMERGED',$plan['baselines']['pr64_state'],'historical source has not been retroactively rewritten');
Assert::same('CLOSED_NOT_MERGED',$state['archive_candidate_pr64']['state'],'newer dated state corrects stale PR64 status');
Assert::true(is_file($root.'/docs/longitudinal/source/Allfather_PHP_Longitudinal_Intelligence_Plan_2026-10-25.md'),'full original plan exists');
Assert::true(is_file($root.'/docs/longitudinal/source/Allfather_Monitor_Longitudinal_Engineering_Handover_2026-10-25.md'),'original handover exists');
