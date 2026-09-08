export function DevReference({ title, source, disposition, children }) {
  return <section className="dev-reference" data-source={source}>
    <h2>{title}</h2><p><strong>{disposition}</strong></p><p>{children}</p>
  </section>;
}
