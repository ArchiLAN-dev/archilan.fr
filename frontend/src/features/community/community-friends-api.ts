import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasNullableStringProp, hasStringProp } from "@/lib/type-guards";

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
