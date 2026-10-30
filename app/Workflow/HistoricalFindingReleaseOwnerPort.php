<?php

declare(strict_types=1);

namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Outcome;

/**
 * Versioned AN-03 owner-adapter seam. Every method is a separate owner stage.
 * No production implementation is registered while U03/U06/U07 remain unadopted.
 * A fixture implementation belongs under tests/support and cannot authorize release.
 */
interface HistoricalFindingReleaseOwnerPort
{
    public function recheckCurrentSourceUse(array $proposal, ?array $previous): Outcome;
    public function readFactualHumanDecision(array $proposal, ?array $previous): Outcome;
    public function submitM04MultiEvidenceProposal(array $proposal, ?array $previous): Outcome;
    public function recordM07IndependentReview(array $proposal, ?array $previous): Outcome;
    public function evaluateM08Admissibility(array $proposal, ?array $previous): Outcome;
    public function prepareM10CurrentRelianceRecord(array $proposal, ?array $previous): Outcome;
    public function readDeliveryHumanDecision(array $proposal, ?array $previous): Outcome;
    public function prepareM16InternalRepresentation(array $proposal, ?array $previous): Outcome;
}
