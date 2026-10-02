// Avatar frames shared by the profile editor (picker) and the public profile. A frame is a decorative ring
// around the (rounded-square) avatar: a flat colour, a pulsing neon glow, a clean animated effect, or a looping
// video overlay.
// Rendering lives in <AvatarFrame>; this file holds only the (serialisable) configuration.

export type AvatarFrameCategory = "Couleurs" | "Néon" | "Effets";
export type AvatarFrameVariant = "solid" | "glow" | "spectral" | "holographic" | "goldshimmer" | "video";

/**
 * A video frame (story 30.46): real fire filmed on black, laid over the avatar with `mix-blend-mode: screen` so
 * the black disappears. `poster` is its first frame, shown before mount and under reduced motion.
 */
export type AvatarFrameVideo = {
  webm: string;
  mp4: string;
  poster: string;
};

export type AvatarFrameConfig = {
  key: string;
  label: string;
  category: AvatarFrameCategory;
  variant: AvatarFrameVariant;
  /** ring colour for the solid / glow variants */
  color?: string;
  /** the overlay of the video variant */
  video?: AvatarFrameVideo;
};

/** A video frame whose assets are `/avatar-frames/<file>.webm|.mp4` and `<file>-poster.webp`. */
function videoFrame(key: string, label: string, file: string): AvatarFrameConfig {
  const base = `/avatar-frames/${file}`;
  return { key, label, category: "Effets", variant: "video", video: { webm: `${base}.webm`, mp4: `${base}.mp4`, poster: `${base}-poster.webp` } };
}

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
  // Effets (clean, animés)
  { key: "holographic", label: "Holographique", category: "Effets", variant: "holographic" },
  { key: "gold_shimmer", label: "Or scintillant", category: "Effets", variant: "goldshimmer" },
  { key: "spectral", label: "Spectre", category: "Effets", variant: "spectral" },
  // Video frames (story 30.46): one shared geometry, so the overlay CSS fits them all.
  videoFrame("fire", "Feu", "fire"),
  videoFrame("electric", "Électrique", "electric"),
  videoFrame("spectral_fire", "Flammes spectrales", "spectral-fire"),
  videoFrame("lava", "Magma", "lava"),
  videoFrame("runes", "Runes arcaniques", "runes"),
  videoFrame("cosmic", "Portail cosmique", "cosmic"),
  videoFrame("glitch", "Glitch", "glitch"),
] as const;

export const AVATAR_FRAME_KEYS: readonly string[] = AVATAR_FRAMES.map((f) => f.key);

const BY_KEY = new Map(AVATAR_FRAMES.map((f) => [f.key, f]));

/** The frame for a key, or null for "no frame" (null/unknown key). */
export function getAvatarFrame(key: string | null): AvatarFrameConfig | null {
  return key ? (BY_KEY.get(key) ?? null) : null;
}
