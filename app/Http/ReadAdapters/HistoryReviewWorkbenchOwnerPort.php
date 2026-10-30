<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\ReadAdapters;

use Allfather\Loopia\Core\Outcome;

/** Future authenticated M02 + P01/M05/M07/M10 owner interface. */
interface HistoryReviewWorkbenchOwnerPort
{
    public function read(array $scope, int $page, int $limit): Outcome;
    public function proposeCommand(array $scope, array $candidate): Outcome;
}
