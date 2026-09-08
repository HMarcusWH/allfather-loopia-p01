export const TONES = Object.freeze(["neutral", "cyan", "gold", "amber", "violet", "green", "red"]);
export function safeTone(value) { return TONES.includes(value) ? value : "neutral"; }
export function humanize(value) { return String(value ?? "—").replaceAll("_", " ").toLowerCase(); }
