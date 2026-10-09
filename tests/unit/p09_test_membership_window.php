<?php

declare(strict_types=1);

use Allfather\Loopia\Auth\TestMembershipLifecycle;
use Allfather\Loopia\Tests\Assert;

$base = '2026-10-08T22:00:00.000Z';
$hour = '2026-10-08T23:00:00.000Z';
$day = '2026-10-09T22:00:00.000Z';

Assert::true(TestMembershipLifecycle::validateWindow($base, $hour, $base)->isOk(),
    'finite one-hour TEST membership window');
Assert::true(TestMembershipLifecycle::validateWindow($base, $day, $base)->isOk(),
    'exact 24-hour boundary accepted');
Assert::same('P09_TEST_WINDOW_OUT_OF_BOUNDS',
    TestMembershipLifecycle::validateWindow($base, '2026-10-09T22:00:00.001Z', $base)->reasonCode(),
    '24h plus 1ms denied');
Assert::same('P09_TEST_WINDOW_OUT_OF_BOUNDS',
    TestMembershipLifecycle::validateWindow($base, $base, $base)->reasonCode(),
    'zero duration denied');
Assert::same('P09_TEST_WINDOW_OUT_OF_BOUNDS',
    TestMembershipLifecycle::validateWindow($hour, $base, $base)->reasonCode(),
    'backwards time denied');
Assert::same('P09_TEST_WINDOW_OUT_OF_BOUNDS',
    TestMembershipLifecycle::validateWindow($base, $hour, '2026-10-08T23:00:00.000Z')->reasonCode(),
    'expired window denied');
Assert::same('P09_TEST_WINDOW_OUT_OF_BOUNDS',
    TestMembershipLifecycle::validateWindow('2026-10-06T22:00:00.000Z',
        $hour, $base)->reasonCode(), 'backdated more than 24h denied');
Assert::same('P09_TEST_WINDOW_OUT_OF_BOUNDS',
    TestMembershipLifecycle::validateWindow('2026-10-10T22:00:00.000Z',
        '2026-10-10T23:00:00.000Z', $base)->reasonCode(),
    'start more than 24h ahead denied');
Assert::same('P09_TEST_WINDOW_NOT_CANONICAL',
    TestMembershipLifecycle::validateWindow('2026-10-08T22:00:00Z',
        $hour, $base)->reasonCode(), 'noncanonical start denied');
Assert::same('P09_TEST_WINDOW_NOT_CANONICAL',
    TestMembershipLifecycle::validateWindow($base,
        'not-a-date', $base)->reasonCode(), 'invalid expiry denied');
