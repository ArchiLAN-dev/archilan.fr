// Banner gradient presets shared by the public profile header and the customization form, so a preset
// always renders the same swatch in the editor and on the live profile.

export const BANNER_CLASSES: Record<string, string> = {
  default: "bg-gradient-to-r from-accent/30 via-accent/10 to-transparent",
  sunset: "bg-gradient-to-r from-orange-500/40 via-pink-500/20 to-transparent",
  forest: "bg-gradient-to-r from-emerald-600/40 via-emerald-400/15 to-transparent",
  arcade: "bg-gradient-to-r from-fuchsia-500/40 via-cyan-400/20 to-transparent",
  midnight: "bg-gradient-to-r from-indigo-800/50 via-indigo-500/20 to-transparent",
  aurora: "bg-gradient-to-r from-teal-400/40 via-violet-500/20 to-transparent",
};

export const BANNER_LABELS: Record<string, string> = {
  default: "Défaut",
  sunset: "Coucher de soleil",
  forest: "Forêt",
  arcade: "Arcade",
  midnight: "Minuit",
  aurora: "Aurore",
};

export function bannerClass(preset: string): string {
  return BANNER_CLASSES[preset] ?? BANNER_CLASSES.default;
}