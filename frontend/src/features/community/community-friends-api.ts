import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";
import type { ImageFraming } from "@/features/community/image-framing";
import type { NameStyle } from "@/features/community/titled-name";

export type RelationshipState =
  | "none"
  | "outgoing"
  | "incoming"
  | "friends"
  | "blocking"
  | "blocked"
  | "self";

export type Relationship = { state: RelationshipState; friendshipId: string | null };

export type FriendCard = {
  userId: string;
  slug: string;
  displayName: string | null;
  avatarUrl: string | null;
  // Story 30.42: an admin's GIF, animated on hover off the profile page.
  avatarAnimatedUrl?: string | null;
  // Story 30.47: the member's avatar frame, shown wherever the avatar is.
  avatarFrame?: string | null;
  // Story 30.43: the framing of an uploaded avatar (null = centred).
  avatarFraming?: ImageFraming | null;
  // Story 30.44: legendary admin, epic member (null = a plain name).
  nameStyle?: NameStyle | null;
};

export type IncomingRequest = FriendCard & { friendshipId: string };

export type FriendsData = { friends: FriendCard[]; incoming: IncomingRequest[]; outgoing: FriendCard[] };

const STATES: RelationshipState[] = ["none", "outgoing", "incoming", "friends", "blocking", "blocked", "self"];

function isRelationship(v: unknown): v is Relationship {
  if (typeof v !== "object" || v === null) return false;
  if (!("state" in v) || typeof v.state !== "string") return false;
  const state = v.state;
  if (!STATES.some((s) => s === state)) return false;
  return hasNullableStringProp(v, "friendshipId");
}

function isFriendCard(v: unknown): v is FriendCard {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "userId") &&
    hasStringProp(v, "slug") &&
    hasNullableStringProp(v, "displayName") &&
    hasNullableStringProp(v, "avatarUrl")
  );
}

function dataOf(json: unknown): unknown {
  if (typeof json !== "object" || json === null || !("data" in json)) return null;
  return json.data;
}

export async function fetchRelationship(slug: string): Promise<Relationship | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profiles/${encodeURIComponent(slug)}/relationship`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isRelationship(data) ? data : null;
  } catch {
    return null;
  }
}

async function relationshipAction(slug: string, segment: string, method: "POST" | "DELETE"): Promise<Relationship | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/profiles/${encodeURIComponent(slug)}/${segment}`, { method });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isRelationship(data) ? data : null;
  } catch {
    return null;
  }
}

export const sendFriendRequest = (slug: string) => relationshipAction(slug, "friend-request", "POST");
export const removeFriendship = (slug: string) => relationshipAction(slug, "friendship", "DELETE");
export const blockUser = (slug: string) => relationshipAction(slug, "block", "POST");
export const unblockUser = (slug: string) => relationshipAction(slug, "block", "DELETE");

async function respondToRequest(friendshipId: string, action: "accept" | "decline"): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friendships/${encodeURIComponent(friendshipId)}/${action}`, {
      method: "POST",
    });
    return res.ok;
  } catch {
    return false;
  }
}

export const acceptFriendship = (id: string) => respondToRequest(id, "accept");
export const declineFriendship = (id: string) => respondToRequest(id, "decline");

export async function fetchFriends(): Promise<FriendsData | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friends`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    if (typeof data !== "object" || data === null) return null;
    if (!("friends" in data) || !Array.isArray(data.friends) || !data.friends.every(isFriendCard)) return null;
    if (!("outgoing" in data) || !Array.isArray(data.outgoing) || !data.outgoing.every(isFriendCard)) return null;
    if (!("incoming" in data) || !Array.isArray(data.incoming)) return null;
    if (!data.incoming.every((r) => isFriendCard(r) && hasStringProp(r, "friendshipId"))) return null;
    return { friends: data.friends, incoming: data.incoming, outgoing: data.outgoing };
  } catch {
    return null;
  }
}

/** Story 43.2: a member played with, and how much. */
export type FriendSuggestion = FriendCard & {
  sessionsTogether: number;
  lastTitle: string | null;
  lastPlayedAt: string | null;
};

function isFriendSuggestion(v: unknown): v is FriendSuggestion {
  return (
    isFriendCard(v) &&
    "sessionsTogether" in v &&
    typeof v.sessionsTogether === "number" &&
    hasNullableStringProp(v, "lastTitle") &&
    hasNullableStringProp(v, "lastPlayedAt")
  );
}

/**
 * « Tu as joué avec » (story 43.2). With a session, only the members played with in it. Null on failure.
 */
export async function fetchFriendSuggestions(options: { sessionId?: string; limit?: number } = {}): Promise<FriendSuggestion[] | null> {
  const params = new URLSearchParams();
  if (options.sessionId !== undefined) params.set("sessionId", options.sessionId);
  if (options.limit !== undefined) params.set("limit", String(options.limit));
  const query = params.toString();
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friend-suggestions${query === "" ? "" : `?${query}`}`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) && data.every(isFriendSuggestion) ? data : null;
  } catch {
    return null;
  }
}

/** « Ignorer »: never suggested again (story 43.2). */
export async function dismissFriendSuggestion(slug: string): Promise<boolean> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friend-suggestions/${encodeURIComponent(slug)}/ignore`, {
      method: "POST",
    });
    return res.ok;
  } catch {
    return false;
  }
}

function codeOf(json: unknown): string | null {
  const data = dataOf(json);
  return typeof data === "object" && data !== null && hasStringProp(data, "code") ? data.code : null;
}

/** Story 43.3: the code of « Mon lien d'ami » (`/ami/{code}`), made on first ask. Null on failure. */
export async function fetchMyFriendLinkCode(): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friend-link`);
    return res.ok ? codeOf(await res.json()) : null;
  } catch {
    return null;
  }
}

/** A new code: the old link stops working. Null on failure. */
export async function regenerateFriendLink(): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friend-link/regenerate`, { method: "POST" });
    return res.ok ? codeOf(await res.json()) : null;
  } catch {
    return null;
  }
}

export type FriendLinkView = { member: FriendCard; relationship: Relationship };

export type FriendLinkResult = { kind: "ok"; view: FriendLinkView } | { kind: "invalid" } | { kind: "error" };

/** The member a scanned link points to. An unknown, regenerated or blocked link is `invalid`, all alike. */
export async function fetchFriendLink(code: string): Promise<FriendLinkResult> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friend-link/${encodeURIComponent(code)}`);
    if (res.status === 404) return { kind: "invalid" };
    if (!res.ok) return { kind: "error" };
    const data = dataOf(await res.json());
    if (typeof data !== "object" || data === null) return { kind: "error" };
    if (!("member" in data) || !isFriendCard(data.member)) return { kind: "error" };
    if (!("relationship" in data) || !isRelationship(data.relationship)) return { kind: "error" };
    return { kind: "ok", view: { member: data.member, relationship: data.relationship } };
  } catch {
    return { kind: "error" };
  }
}

/** « Ajouter en ami » from the link. Null on failure. */
export async function addFriendFromLink(code: string): Promise<Relationship | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friend-link/${encodeURIComponent(code)}/add`, { method: "POST" });
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return isRelationship(data) ? data : null;
  } catch {
    return null;
  }
}

/** Story 43.4: the viewer's friends registered to an event. Null on failure. */
export async function fetchEventFriends(eventId: string): Promise<FriendCard[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/events/${encodeURIComponent(eventId)}/friends`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    return Array.isArray(data) && data.every(isFriendCard) ? data : null;
  } catch {
    return null;
  }
}

/** The same for a list of events in one call, keyed by event id; an event without a friend is absent. */
export async function fetchEventFriendsBatch(eventIds: string[]): Promise<Record<string, FriendCard[]> | null> {
  if (eventIds.length === 0) return {};
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/event-friends?ids=${eventIds.map(encodeURIComponent).join(",")}`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    if (typeof data !== "object" || data === null || Array.isArray(data)) return null;
    const byEvent: Record<string, FriendCard[]> = {};
    for (const [eventId, cards] of Object.entries(data)) {
      if (!Array.isArray(cards) || !cards.every(isFriendCard)) return null;
      byEvent[eventId] = cards;
    }
    return byEvent;
  } catch {
    return null;
  }
}

/** Story 43.5: a friend playing now. Title and page only when the viewer may open the session. */
export type FriendPlaying = FriendCard & {
  game: string | null;
  kind: "event" | "run" | null;
  title: string | null;
  eventId: string | null;
  runId: string | null;
};

/** A friend whose last session ended less than a day ago. */
export type FriendRecent = FriendCard & { game: string | null; finishedAt: string };

export type FriendsNow = { hasFriends: boolean; playing: FriendPlaying[]; recent: FriendRecent[] };

function isFriendPlaying(v: unknown): v is FriendPlaying {
  if (!isFriendCard(v) || !hasNullableStringProp(v, "game") || !hasNullableStringProp(v, "title")) return false;
  if (!hasNullableStringProp(v, "eventId") || !hasNullableStringProp(v, "runId")) return false;
  return "kind" in v && (v.kind === "event" || v.kind === "run" || v.kind === null);
}

function isFriendRecent(v: unknown): v is FriendRecent {
  return isFriendCard(v) && hasNullableStringProp(v, "game") && hasStringProp(v, "finishedAt");
}

/** « Mes amis en ce moment » (story 43.5). Null on failure. */
export async function fetchFriendsNow(): Promise<FriendsNow | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/community/friends/now`);
    if (!res.ok) return null;
    const data = dataOf(await res.json());
    if (typeof data !== "object" || data === null) return null;
    if (!("hasFriends" in data) || typeof data.hasFriends !== "boolean") return null;
    if (!("playing" in data) || !Array.isArray(data.playing) || !data.playing.every(isFriendPlaying)) return null;
    if (!("recent" in data) || !Array.isArray(data.recent) || !data.recent.every(isFriendRecent)) return null;
    return { hasFriends: data.hasFriends, playing: data.playing, recent: data.recent };
  } catch {
    return null;
  }
}
