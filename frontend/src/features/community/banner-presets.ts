// Banner presets shared by the public profile header and the customization editor. Each preset is a layered
// treatment: an animated linear gradient base, optional blurred "mesh" blobs, and an optional subtle texture.
// Rendering lives in <ProfileBanner>; this file holds only the (serialisable) configuration.

export type BannerTexture = "grain" | "dots" | "grid";

export type BannerBlob = {
  color: string;
  /** center, as % of the banner box */
  cx: string;
  cy: string;
  /** diameter, as % of the banner width */
  size: string;
};

export type BannerPresetConfig = {
  key: string;
  label: string;
  /** three stops of the animated base gradient */
  gradient: [string, string, string];
  blobs?: BannerBlob[];
  texture?: BannerTexture;
};

export const BANNER_PRESETS: readonly BannerPresetConfig[] = [
  { key: "default", label: "Défaut", gradient: ["#6366f1", "#8b5cf6", "#3b82f6"] },
  { key: "sunset", label: "Coucher de soleil", gradient: ["#fb7185", "#f472b6", "#a855f7"], texture: "grain" },
  { key: "forest", label: "Forêt", gradient: ["#34d399", "#10b981", "#84cc16"], texture: "dots" },
  { key: "arcade", label: "Arcade", gradient: ["#d946ef", "#22d3ee", "#818cf8"], texture: "grid" },
  { key: "midnight", label: "Minuit", gradient: ["#1e3a8a", "#4338ca", "#6d28d9"], texture: "grain" },
  {
    key: "aurora",
    label: "Aurore",
    gradient: ["#0f766e", "#4c1d95", "#065f46"],
    blobs: [
      { color: "#2dd4bf", cx: "18%", cy: "35%", size: "55%" },
      { color: "#a78bfa", cx: "72%", cy: "22%", size: "50%" },
      { color: "#4ade80", cx: "48%", cy: "80%", size: "48%" },
    ],
  },
  {
    key: "ocean",
    label: "Océan",
    gradient: ["#0ea5e9", "#2563eb", "#06b6d4"],
    blobs: [
      { color: "#22d3ee", cx: "20%", cy: "30%", size: "52%" },
      { color: "#3b82f6", cx: "75%", cy: "55%", size: "55%" },
    ],
  },
  {
    key: "neon",
    label: "Néon",
    gradient: ["#7c3aed", "#0b0b16", "#db2777"],
    blobs: [
      { color: "#ec4899", cx: "22%", cy: "40%", size: "48%" },
      { color: "#22d3ee", cx: "78%", cy: "30%", size: "46%" },
      { color: "#a855f7", cx: "55%", cy: "78%", size: "44%" },
    ],
    texture: "grid",
  },
  { key: "retrowave", label: "Retrowave", gradient: ["#db2777", "#7c3aed", "#f59e0b"], texture: "grid" },
  { key: "pastel", label: "Pastel", gradient: ["#fbcfe8", "#bfdbfe", "#ddd6fe"], texture: "dots" },
] as const;

const BY_KEY = new Map(BANNER_PRESETS.map((p) => [p.key, p]));

export function getBannerPreset(key: string): BannerPresetConfig {
  return BY_KEY.get(key) ?? BANNER_PRESETS[0];
}
