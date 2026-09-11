import { Icon } from "../primitives/icons.jsx";

/**
 * The four things an operator must always be able to reach, whatever the console is showing.
 *
 * WHY THEY ARE NOT REACT ROUTES. Account, Help and Choose workspace are server-rendered pages, and
 * they stay that way for a reason that is easiest to see in the failure case: an account under the
 * forced password-change gate cannot load `/console/app` at all. A password form living inside the
 * shell would be unreachable exactly when it is required. The same argument makes the support page
 * server-rendered — it is the page somebody opens when the console is not working.
 *
 * WHY SIGN-OUT IS A FORM. It is a POST carrying the session's own CSRF token, because that is what
 * it already was and weakening it to a GET so React could use a button would turn sign-out into a
 * link any page on the internet could make somebody follow. The token reaches this component
 * through one reviewed data attribute on the mount element; it is the same value the server-rendered
 * console puts in every hidden input in the same document, so no exposure is added — and `script-src
 * 'self'` means no third-party script can read it in either case.
 *
 * NO SCRIPT IS INTRODUCED. A form POST is markup. There is no fetch, no inline handler, no eval,
 * and the shell's CSP is unchanged: `form-action 'self'` already permits exactly this.
 *
 * FAILING CLOSED. With no token the button still renders and the POST is refused by the Kernel with
 * a 403 the operator can act on. Hiding the control instead would leave somebody signed in with no
 * visible way out, which is worse than a refusal that explains itself.
 */
export function ShellUtilities({ csrfToken }) {
  return (
    <div className="rail-utilities">
      <div className="rail-utilities-label">Session</div>
      <a className="rail-utility" href="/console/workspaces">
        <Icon name="panels-top-left" />
        <span>Choose workspace</span>
      </a>
      <a className="rail-utility" href="/account">
        <Icon name="users-round" />
        <span>Account</span>
      </a>
      <a className="rail-utility" href="/support">
        <Icon name="book-open-check" />
        <span>Help &amp; support</span>
      </a>
      <form className="rail-utility-form" method="post" action="/logout">
        <input type="hidden" name="csrf_token" value={csrfToken ?? ""} />
        <button className="rail-utility" type="submit">
          <Icon name="key-round" />
          <span>Sign out</span>
        </button>
      </form>
    </div>
  );
}
