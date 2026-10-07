import { externalLinks } from "@/lib/external-links";
import { hasNumberProp } from "@/lib/type-guards";

/** Story 30.50: how many people the ArchiLAN Discord has, and how many are online now. */
export type DiscordStats = { members: number; online: number };

/** How long the counts are kept before Discord is asked again (seconds). */
const REVALIDATE = 300;

/** The code of an invite link: `https://discord.gg/<code>` or `https://discord.com/invite/<code>`. */
export function inviteCode(url: string): string | null {
  return url.match(/(?:discord\.gg|discord(?:app)?\.com\/invite)\/([\w-]+)/)?.[1] ?? null;
}

export function isDiscordInvite(v: unknown): v is { approximate_member_count: number; approximate_presence_count: number } {
  return typeof v === "object" && v !== null && hasNumberProp(v, "approximate_member_count") && hasNumberProp(v, "approximate_presence_count");
}

/**
 * The counts Discord gives anyone holding the invite link, no bot needed. Read on the server and kept five
 * minutes; null when Discord is out of reach or the link is not an invite (the site then shows no number).
 */
export async function fetchDiscordStats(): Promise<DiscordStats | null> {
  const code = inviteCode(externalLinks.archilanDiscord);
  if (code === null) return null;
  try {
    const res = await fetch(`https://discord.com/api/v10/invites/${encodeURIComponent(code)}?with_counts=true`, { next: { revalidate: REVALIDATE } });
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return isDiscordInvite(payload) ? { members: payload.approximate_member_count, online: payload.approximate_presence_count } : null;
  } catch {
    return null;
  }
}
