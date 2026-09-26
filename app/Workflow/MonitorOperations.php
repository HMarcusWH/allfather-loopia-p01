<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;
use Allfather\Loopia\Core\Outcome;

/** UI-facing composition contract. Implementations own scope and every governance decision. */
interface MonitorOperations
{
    public function list(array $scope): Outcome;
    public function detail(array $scope, string $runId): Outcome;
    public function startConfigured(array $scope, string $missionId, string $idempotencyKey): Outcome;
    public function advance(array $scope, string $runId, ?string $expectedRequestDigest = null): Outcome;
    public function review(array $scope, string $runId, array $review): Outcome;
    public function stop(array $scope, string $runId): Outcome;
}
