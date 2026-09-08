import { StatusBadge } from "../primitives/index.jsx";
export function Collection({ rows = [], columns = [] }) {
  if (!rows.length) return <div className="empty-state">No rows in this projection.</div>;
  return <div className="collection-wrap"><table className="collection"><thead><tr>{columns.map((c) => <th key={c.key}>{c.label}</th>)}</tr></thead><tbody>{rows.map((row, i) => <tr key={row.id ?? i}>{columns.map((c) => <td key={c.key}>{c.tone ? <StatusBadge tone={c.tone(row[c.key], row)}>{row[c.key] ?? "—"}</StatusBadge> : row[c.key] ?? "—"}</td>)}</tr>)}</tbody></table></div>;
}
