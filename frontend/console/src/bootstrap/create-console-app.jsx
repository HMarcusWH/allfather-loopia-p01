import { createRoot } from "react-dom/client";
import { ConsoleApp } from "../app/app.jsx";
export function createConsoleApp({ mount, datasource, csrfToken = null }) {
  if (!(mount instanceof Element)) throw new TypeError("Console mount element is required");
  if (!datasource) throw new TypeError("Console datasource is required");
  const root = createRoot(mount);
  // The token is the SESSION's own CSRF value, read from the mount element the server rendered. It
  // is passed as data, never used to decide anything: the only thing the shell does with it is put
  // it in the sign-out form, and the server re-verifies it against the session's stored digest.
  root.render(<ConsoleApp datasource={datasource} csrfToken={csrfToken} />);
  return () => root.unmount();
}
