<?php
declare(strict_types=1);
namespace Allfather\Loopia\Workflow;

use Allfather\Loopia\Core\Outcome;

/**
 * Who vouches, at dispatch time, that a prepared request's context is still exactly what it was.
 *
 * For a product mission that is ContextEnvelope: current M02 access, current source policy, the
 * retained capture bytes. For an evaluation it is EvaluationCaseContext: the frozen, reviewed case
 * manifest and nothing else — no source is fetched and no mission evidence is read. The executor
 * asks the same two questions of either, so evaluation runs the product's request, dispatch and
 * candidate path rather than a parallel one.
 */
interface ModelContextAuthority
{
    public function revalidate(array $context): Outcome;

    public function validateRoutingPackage(array $context, array $package, array $priorCandidate): Outcome;
}
