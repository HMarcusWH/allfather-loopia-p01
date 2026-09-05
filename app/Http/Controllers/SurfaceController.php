<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Product\P01\Surfacing;
use Allfather\Loopia\Product\P04\Release as P04Release;
use Allfather\Loopia\Product\P05\Release as P05Release;

/**
 * The three released-output surfaces.
 *
 * NONE OF THESE DECIDES ANYTHING. Each delegates to the product's own gated read model — P01
 * surfacing, P04 three-gate distribution, P05 two-gate marketing — and renders what comes back,
 * including the refusal. The controller has no authority logic of its own, deliberately: a second
 * implementation of a gate is a second answer to the same question, and the two will drift.
 *
 * A DENIAL RENDERS THE GUARD'S OWN REASON CODES. Replacing them with a generic message would hide
 * from an operator which of several conditions refused, and each has a different remedy: a missing
 * role is an admin's job, a missing tier is a purchase, and an unconfigured target is a setting.
 */
final class SurfaceController
{
    public function __construct(
        private readonly Surfacing $p01,
        private readonly P04Release $p04,
        private readonly P05Release $p05,
        private readonly View $view,
    ) {
    }

    public function briefings(Request $request, ?array $session): Response
    {
        $result = $this->p01->listVisibleReleasedBriefings([
            'tenant_id' => (string) $session['tenant_id'],
            'workspace_id' => (string) $session['active_workspace_id'],
            'actor_type' => 'OPERATOR',
            'actor_id' => (string) $session['operator_id'],
            'briefing_kind' => 'BATTLECARD_BRIEF',
        ]);

        return $this->render($session, 'briefings', [
            'heading' => 'Competition briefings',
            'lede' => 'Battlecards released internally, rendered from the frozen artifact rather than '
                . 'from a live join — so what a viewer saw on a given day stays reconstructable.',
            'roleNote' => null,
            'columns' => [
                ['key' => 'signal_summary_safe', 'label' => 'Signal'],
                ['key' => 'confidence_band', 'label' => 'Confidence', 'kind' => 'state'],
                ['key' => 'briefing_kind', 'label' => 'Kind', 'kind' => 'governance'],
                ['key' => 'released_at', 'label' => 'Released', 'mono' => true],
            ],
            'result' => [
                'decision' => $result['decision'],
                'required_permission' => $result['required_permission'],
                'reason_codes' => $result['reason_codes'],
                'rows' => $result['briefings'],
            ],
        ]);
    }

    public function distributions(Request $request, ?array $session): Response
    {
        // The requested ROLE VIEW. It is caller-supplied and untrusted; the product's third gate
        // decides whether it is a configured recipient, and the cross-role filter means a caller
        // authorized for one view never sees another's briefings.
        $role = $request->input('role', 'SALES');

        $result = $this->p04->listVisibleRoleDistributions([
            'tenant_id' => (string) $session['tenant_id'],
            'workspace_id' => (string) $session['active_workspace_id'],
            'actor_type' => 'OPERATOR',
            'actor_id' => (string) $session['operator_id'],
            'target_org_role' => $role,
        ]);

        return $this->render($session, 'distributions', [
            'heading' => 'Role distributions',
            'lede' => 'Intelligence routed to an internal org-role. A briefing composed for one role '
                . 'is not redacted for another — it is not returned at all.',
            'roleNote' => 'Showing the ' . $role . ' view. Requesting another role re-runs all three '
                . 'gates; it does not widen this one.',
            'columns' => [
                ['key' => 'briefing_summary_safe', 'label' => 'Briefing'],
                ['key' => 'target_org_role', 'label' => 'Role', 'kind' => 'authority'],
                ['key' => 'decision_record_id', 'label' => 'Decision record', 'mono' => true],
                ['key' => 'released_at', 'label' => 'Released', 'mono' => true],
            ],
            'result' => [
                'decision' => $result['decision'],
                'required_permission' => $result['required_permission'],
                'reason_codes' => $result['reason_codes'],
                'rows' => $result['outputs'],
            ],
        ]);
    }

    public function marketing(Request $request, ?array $session): Response
    {
        $result = $this->p05->listVisibleMarketingAssets([
            'tenant_id' => (string) $session['tenant_id'],
            'workspace_id' => (string) $session['active_workspace_id'],
            'actor_type' => 'OPERATOR',
            'actor_id' => (string) $session['operator_id'],
        ]);

        return $this->render($session, 'marketing', [
            'heading' => 'Marketing drafts',
            'lede' => 'Internally released marketing drafts. RELEASED_INTERNAL is the strongest state '
                . 'these reach — there is no published state in this system to reach.',
            'roleNote' => null,
            'columns' => [
                ['key' => 'title_safe', 'label' => 'Title'],
                ['key' => 'asset_kind', 'label' => 'Kind', 'kind' => 'governance'],
                ['key' => 'decision_record_id', 'label' => 'Decision record', 'mono' => true],
                ['key' => 'released_at', 'label' => 'Released', 'mono' => true],
            ],
            'result' => [
                'decision' => $result['decision'],
                'required_permission' => $result['required_permission'],
                'reason_codes' => $result['reason_codes'],
                'rows' => $result['outputs'],
            ],
        ]);
    }

    /**
     * @param array<string, mixed>|null $session
     * @param array<string, mixed> $data
     */
    private function render(?array $session, string $navKey, array $data): Response
    {
        return Response::html(
            $this->view->render('surface', [
                'title' => $data['heading'],
                'session' => $session,
                'nav' => ['current' => $navKey],
                ...$data,
            ]),
            // A denial is a 403 at the transport too, not a 200 with an empty table. A monitoring
            // system that only reads status codes should see the refusal.
            $data['result']['decision'] === 'VISIBLE' ? 200 : 403,
        );
    }
}
