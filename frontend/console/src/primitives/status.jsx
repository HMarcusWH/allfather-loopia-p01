import { safeTone } from "../data/presentation-vocabulary.js";
export function StatusBadge({ children, tone = "neutral" }) {
  return <span className="status-badge" data-tone={safeTone(tone)}>{children}</span>;
}
