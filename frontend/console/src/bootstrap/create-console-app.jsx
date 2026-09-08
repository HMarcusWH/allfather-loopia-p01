import { createRoot } from "react-dom/client";
import { ConsoleApp } from "../app/app.jsx";
export function createConsoleApp({ mount, datasource }) {
  if (!(mount instanceof Element)) throw new TypeError("Console mount element is required");
  if (!datasource) throw new TypeError("Console datasource is required");
  const root = createRoot(mount);
  root.render(<ConsoleApp datasource={datasource} />);
  return () => root.unmount();
}
