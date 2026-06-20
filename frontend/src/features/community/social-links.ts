import type { IconType } from "react-icons";
import {
  FaBluesky,
  FaDiscord,
  FaGithub,
  FaGlobe,
  FaInstagram,
  FaLink,
  FaSteam,
  FaTiktok,
  FaTwitch,
  FaXTwitter,
  FaYoutube,
} from "react-icons/fa6";

// Predefined social-link types: each maps a stored key (kept in the link's `label`) to a brand icon and a
// human label. Shared by the profile editor (type picker) and the public profile (icon rendering).
export type LinkType = {
  key: string;
  label: string;
  icon: IconType;
  placeholder: string;
};

export const LINK_TYPES: readonly LinkType[] = [
  { key: "twitch", label: "Twitch", icon: FaTwitch, placeholder: "https://twitch.tv/ton-pseudo" },
  { key: "youtube", label: "YouTube", icon: FaYoutube, placeholder: "https://youtube.com/@ta-chaine" },
  { key: "x", label: "X", icon: FaXTwitter, placeholder: "https://x.com/ton-pseudo" },
  { key: "bluesky", label: "Bluesky", icon: FaBluesky, placeholder: "https://bsky.app/profile/ton-pseudo" },
  { key: "instagram", label: "Instagram", icon: FaInstagram, placeholder: "https://instagram.com/ton-pseudo" },
  { key: "tiktok", label: "TikTok", icon: FaTiktok, placeholder: "https://tiktok.com/@ton-pseudo" },
  { key: "discord", label: "Discord", icon: FaDiscord, placeholder: "https://discord.gg/invitation" },
  { key: "steam", label: "Steam", icon: FaSteam, placeholder: "https://steamcommunity.com/id/ton-pseudo" },
  { key: "github", label: "GitHub", icon: FaGithub, placeholder: "https://github.com/ton-pseudo" },
  { key: "website", label: "Site web", icon: FaGlobe, placeholder: "https://ton-site.fr" },
] as const;

export const OTHER_LINK_TYPE: LinkType = { key: "other", label: "Autre", icon: FaLink, placeholder: "https://…" };

const BY_KEY = new Map(LINK_TYPES.map((t) => [t.key, t]));

export function isKnownLinkType(label: string): boolean {
  return BY_KEY.has(label.trim().toLowerCase());
}

/** Resolve a stored link label to a known type (case-insensitive); falls back to "Autre". */
export function resolveLinkType(label: string): LinkType {
  return BY_KEY.get(label.trim().toLowerCase()) ?? OTHER_LINK_TYPE;
}
