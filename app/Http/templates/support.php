<?php
/**
 * Help and support.
 *
 * THE CLAIM CEILING IS RESTATED HERE, not because a disclaimer helps, but because this is the page
 * somebody reads when the product has just surprised them — and the commonest surprise in a fit
 * product is reading a fit hypothesis as buyer intent. The page that explains how to report a
 * problem is the right place to say what the system does not claim.
 *
 * NOTHING ON THIS PAGE IS A SECRET. Every value is either the reader's own request reference, their
 * own workspace label, a deployment posture, an operator-authored contact string, or a release
 * reference. No identifier, no credential, no token, no path, no query.
 *
 * @var ?string $requestId
 * @var array{display_name: string, environment_class: string}|null $workspace
 * @var string $deploymentEnvironment
 * @var ?string $supportContact
 * @var ?string $releaseRef
 * @var list<string> $betaPermissions
 * @var array<string, mixed>|null $session
 */
use Allfather\Loopia\Http\View;
$tag = static fn (string $term, string $kind = 'state'): string =>
    (new View(__DIR__))->partial('_tag', ['term' => $term, 'kind' => $kind]);
?>
<header class="page__head">
  <h1 class="page__title">Help and support</h1>
  <p class="page__lede">
    What to quote when something goes wrong, who to tell, and what this console does and does not
    claim. This is a closed beta: the limitations below are deliberate, not defects.
  </p>
</header>

<section class="section">
  <h2 class="section__title">Your request reference</h2>
  <?php if (($requestId ?? null) !== null): ?>
    <p class="page__lede">
      <code><?= View::e($requestId) ?></code>
    </p>
  <?php endif; ?>
  <p class="page__meta">
    Every page you load carries one of these, in the <code>X-Request-ID</code> response header. It
    is a random reference to one request — it contains nothing about you, your tenant, your
    workspace or your address — and quoting it lets an operator find what happened without you
    having to describe it.
  </p>
</section>

<section class="section">
  <h2 class="section__title">This deployment</h2>
  <div class="table-wrap">
    <table>
      <tbody>
        <tr><th>Environment</th><td><?= $tag(strtoupper($deploymentEnvironment)) ?></td></tr>
        <tr>
          <th>Workspace</th>
          <td>
            <?php if ($workspace === null): ?>
              <span class="tag is-uncertain"><span class="tag__dot" aria-hidden="true"></span>None selected</span>
              — <a href="/console/workspaces">choose a workspace</a>
            <?php else: ?>
              <?= View::e($workspace['display_name']) ?>
              <?= $tag($workspace['environment_class']) ?>
            <?php endif; ?>
          </td>
        </tr>
        <tr>
          <th>Release</th>
          <td class="mono"><?= View::e($releaseRef ?? 'not recorded in this deployment') ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</section>

<section class="section">
  <h2 class="section__title">Reporting a technical or access failure</h2>
  <p class="page__lede">
    A page that will not load, a sign-in that will not complete, or a surface that says you are not
    permitted when you believe you should be.
  </p>
  <p class="page__meta">
    Send the request reference above, what you were trying to open, and roughly when. Denials are
    deliberately vague about WHICH condition refused you — naming it would let anyone probe for
    what exists — so the reference is how an operator answers the question you cannot be told.
  </p>
</section>

<section class="section">
  <h2 class="section__title">Reporting incorrect intelligence — a different report</h2>
  <p class="page__lede">
    A battlecard, twin, prospect or draft whose CONTENT is wrong. This is not the same as a
    technical failure and it does not go to the same place.
  </p>
  <p class="page__meta">
    A working page showing a wrong claim is an evidence problem, and it is traced through the
    evidence chain that produced the claim rather than through the request that displayed it. Say
    which output, which workspace, and what specifically is wrong — the request reference does not
    help here, because the request worked.
  </p>
</section>

<section class="section">
  <h2 class="section__title">What this console does not claim</h2>
  <div class="notice notice--empty">
    <p>
      <strong>A product-fit hypothesis is not buyer intent.</strong> The system will say a company
      may fit what you sell. It will not say the company wants to buy it — that claim is not
      available at any evidence level, and there is no field to record it in.
    </p>
    <p>
      <strong>Outputs are internal only.</strong> Everything here is released to your own tenant.
      Nothing is customer-visible output, and nothing is exportable from this projection.
    </p>
    <p>
      <strong>No send, publish or CRM action exists.</strong> Not disabled, not gated behind a
      flag — there is no adapter, no channel and no code path. A beta partner cannot accidentally
      send anything from this console, because nothing here can send.
    </p>
  </div>
</section>

<section class="section">
  <h2 class="section__title">Known beta limitations</h2>
  <ul class="page__meta">
    <li>
      Released-intelligence views are read-only. Authorized operators can configure Monitor setup,
      propose or stand down schedules, and record human reviews through the governed workbench.
      Those actions do not admit execution grants or schedules.
    </li>
    <li>
      Viewing a page does not capture a source or call a model. Explicit workbench execution requires
      separately admitted authority and the appropriate operator permissions.
    </li>
    <li>
      A console proposal or source-code merge does not enable recurrence. Recurring observation
      requires a separately admitted schedule and bounded execution grant.
    </li>
    <li>
      Some screens are deliberately unavailable and say so. They are waiting on a governed read
      model, and an unavailable screen is the honest answer until one exists.
    </li>
    <li>
      Password reset is operator-issued. There is no reset email — this projection performs no
      external send at all, which is the same reason nothing else here can send either.
    </li>
  </ul>
</section>

<section class="section">
  <h2 class="section__title">What your subscription includes</h2>
  <p class="page__meta">
    The closed-beta bundle carries these read permissions and no others. A permission listed here
    is still refused unless your workspace role carries it too — the two gates are separate, and
    both must allow.
  </p>
  <p class="notice__codes">
    <?php foreach ($betaPermissions as $permission): ?><?= $tag($permission, 'authority') ?> <?php endforeach; ?>
  </p>
</section>

<section class="section">
  <h2 class="section__title">Who to contact</h2>
  <?php if ($supportContact === null): ?>
    <div class="notice notice--denied">
      No support contact is configured for this deployment. Use the channel your operator gave you
      when this account was opened.
    </div>
  <?php else: ?>
    <p class="page__lede"><?= View::e($supportContact) ?></p>
  <?php endif; ?>
</section>
