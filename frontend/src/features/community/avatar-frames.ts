// Avatar frames shared by the profile editor (picker) and the public profile. A frame is a decorative ring
// around the (rounded-square) avatar: a flat colour, a pulsing neon glow, or an animated effect. Rendering
// lives in <AvatarFrame>; this file holds only the (serialisable) configuration.

export type AvatarFrameCategory = "Couleurs" | "Néon" | "Effets";
export type AvatarFrameVariant =
  | "solid"
  | "glow"
  | "fire"
  | "electric"
  | "rainbow"
  | "aurora"
  | "frost"
  | "embers"
  | "spectral";

export type AvatarFrameConfig = {
  key: string;
  label: string;
  category: AvatarFrameCategory;
  variant: AvatarFrameVariant;
  /** ring colour for the solid / glow variants */
  color?: string;
};

export const AVATAR_FRAMES: readonly AvatarFrameConfig[] = [
  // Couleurs simples
  { key: "gold", label: "Or", category: "Couleurs", variant: "solid", color: "#fbbf24" },
  { key: "silver", label: "Argent", category: "Couleurs", variant: "solid", color: "#cbd5e1" },
  { key: "bronze", label: "Bronze", category: "Couleurs", variant: "solid", color: "#d97706" },
  { key: "crimson", label: "Cramoisi", category: "Couleurs", variant: "solid", color: "#ef4444" },
  { key: "emerald", label: "Émeraude", category: "Couleurs", variant: "solid", color: "#10b981" },
  { key: "sapphire", label: "Saphir", category: "Couleurs", variant: "solid", color: "#3b82f6" },
  { key: "violet", label: "Violet", category: "Couleurs", variant: "solid", color: "#8b5cf6" },
  // Néon (lueur pulsée)
  { key: "neon_pink", label: "Néon rose", category: "Néon", variant: "glow", color: "#ec4899" },
  { key: "neon_cyan", label: "Néon cyan", category: "Néon", variant: "glow", color: "#22d3ee" },
  { key: "neon_green", label: "Néon vert", category: "Néon", variant: "glow", color: "#4ade80" },
  { key: "toxic", label: "Toxique", category: "Néon", variant: "glow", color: "#a3e635" },
  // Effets animés
  { key: "fire", label: "Flammes", category: "Effets", variant: "fire" },
  { key: "embers", label: "Braises", category: "Effets", variant: "embers" },
  { key: "electric", label: "Électricité", category: "Effets", variant: "electric" },
  { key: "rainbow", label: "Arc-en-ciel", category: "Effets", variant: "rainbow" },
  { key: "aurora", label: "Aurore", category: "Effets", variant: "aurora" },
  { key: "frost", label: "Givre", category: "Effets", variant: "frost" },
  { key: "spectral", label: "Spectre", category: "Effets", variant: "spectral" },
] as const;

export const AVATAR_FRAME_KEYS: readonly string[] = AVATAR_FRAMES.map((f) => f.key);

const BY_KEY = new Map(AVATAR_FRAMES.map((f) => [f.key, f]));

/** The frame for a key, or null for "no frame" (null/unknown key). */
export function getAvatarFrame(key: string | null): AvatarFrameConfig | null {
  return key ? (BY_KEY.get(key) ?? null) : null;
}
