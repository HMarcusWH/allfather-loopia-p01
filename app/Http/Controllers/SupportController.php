<?php

declare(strict_types=1);

namespace Allfather\Loopia\Http\Controllers;

use Allfather\Loopia\Beta\BetaBundle;
use Allfather\Loopia\Http\Request;
use Allfather\Loopia\Http\Response;
use Allfather\Loopia\Http\ReadAdapters\SupportContextAdapter;
use Allfather\Loopia\Http\View;
use Allfather\Loopia\Support\Env;

/**
 * Help and support: what to quote, who to tell, and what this product does not claim.
 *
 * THE PAGE EXISTS BECAUSE THE DENIALS ARE DELIBERATELY UNHELPFUL. A surface that told the denied
 * caller which of membership, role, tier or workspace refused them would be a probe. So the
 * partner is left holding "not visible to you", and this page is the other half: quote the
 * reference, and an operator resolves it on a plane you cannot see.
 *
 * TWO KINDS OF REPORT, KEPT APART. A console that will not load and a battlecard that is WRONG are
 * different failures with different remedies and different people, and collapsing them is how
 * incorrect intelligence gets filed as a bug and never reaches the evidence chain that produced it.
 *
 * WHAT IT MAY SHOW is operational context the reader already has or could have: their own
 * workspace label, the environment class, the release reference, the support contact the operator
 * configured, and the standing limitations of the beta.
 *
 * WHAT IT MUST NEVER SHOW — and the absence is structural, not a habit. This controller holds no
 * `Database`, no `AccessGuard`, no product service and no `SecretBox`. Its single read goes through
 * `SupportContextAdapter`, whose whole content is one session-scoped statement; its configuration
 * comes from a CLOSED LIST of two keys that are non-secret by construction; and the rest comes from
 * the session the Kernel already resolved. There is no path from here to a credential, a hash, a
 * token, a stack trace, a query, a SQL string or a filesystem path, because none of those is
 * reachable from what it holds.
 */
final class SupportController
{
    /**
     * The configuration keys this surface may read. A closed list, because the failure mode of a
     * support page is rendering one key more than intended.
     *
     * `SUPPORT_CONTACT_TEXT` is free text an operator writes for their partners.
     * `APP_RELEASE_REF` is the deployed build reference, which the runbook writes at deploy time.
     *
     * @var list<string>
     */
    public const READABLE_CONFIG_KEYS = ['SUPPORT_CONTACT_TEXT', 'APP_RELEASE_REF'];

    public function __construct(
        private readonly View $view,
        private readonly Env $env,
        private readonly SupportContextAdapter $context,
        /** The DEPLOYMENT posture — development/testing/staging/production — as Bootstrap resolved
         *  it. Distinct from a workspace's `environment_class`, and the page labels both, because a
         *  partner reporting "it broke on staging" and one reporting "my TEST workspace is empty"
         *  are saying different things. */
        private readonly string $deploymentEnvironment,
    ) {
    }

    /** @param array<string, mixed>|null $session */
    public function show(Request $request, ?array $session): Response
    {
        if ($session === null) {
            return Response::redirect('/login');
        }

        return Response::html($this->view->render('support', [
            'title' => 'Help and support',
            'session' => $session,
            'nav' => ['current' => 'support'],
            'requestId' => $request->requestId,
            // The workspace LABEL and its environment class, or nothing. Never an identifier: a
            // support page is a page people screenshot.
            'workspace' => $this->context->activeWorkspace($session),
            'deploymentEnvironment' => $this->deploymentEnvironment,
            // NOT `require`: an unconfigured support contact must render a page that says so, not
            // a 500 on the surface somebody reached BECAUSE something was already broken.
            'supportContact' => $this->env->get('SUPPORT_CONTACT_TEXT') ?: null,
            'releaseRef' => $this->env->get('APP_RELEASE_REF') ?: null,
            'betaPermissions' => BetaBundle::PERMISSIONS,
        ]));
    }
}
