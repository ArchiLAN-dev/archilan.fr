/**
 * The colours a member buys for their name (story 41.23), mirroring the API's `NameColor` palette. The API sends
 * a bought colour as the name style `color-<key>`, shown where no rarity colour (30.44) applies.
 */
export const NAME_COLORS = [
  { key: "emerald", label: "Émeraude", hex: "#34d399" },
  { key: "azure", label: "Azur", hex: "#38bdf8" },
  { key: "ruby", label: "Rubis", hex: "#f87171" },
  { key: "amber", label: "Ambre", hex: "#fbbf24" },
  { key: "amethyst", label: "Améthyste", hex: "#c084fc" },
  { key: "turquoise", label: "Turquoise", hex: "#2dd4bf" },
  { key: "lime", label: "Lime", hex: "#a3e635" },
  { key: "pink", label: "Rose", hex: "#f472b6" },
] as const;

export type NameColorKey = (typeof NAME_COLORS)[number]["key"];

export type NameColorStyle = `color-${NameColorKey}`;

export const NAME_COLOR_STYLE_PREFIX = "color-";

export function nameColor(key: string): (typeof NAME_COLORS)[number] | null {
  return NAME_COLORS.find((color) => color.key === key) ?? null;
}

export function isNameColorStyle(value: unknown): value is NameColorStyle {
  return typeof value === "string" && value.startsWith(NAME_COLOR_STYLE_PREFIX) && nameColor(value.slice(NAME_COLOR_STYLE_PREFIX.length)) !== null;
}

/** The colour of a `color-<key>` name style. */
export function nameColorOfStyle(style: NameColorStyle): string {
  return nameColor(style.slice(NAME_COLOR_STYLE_PREFIX.length))?.hex ?? "inherit";
}
