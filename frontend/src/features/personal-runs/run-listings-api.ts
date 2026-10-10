import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { hasBooleanProp, hasNullableStringProp, hasStringProp } from "@/lib/type-guards";
import type { FriendCard } from "@/features/community/community-friends-api";

/** Story 43.17: a draft run listed for every member, as « Parties qui cherchent des joueurs » shows it. */
export type RunListing = {
  runId: string;
  title: string;
  pitch: string;
  plannedFor: string | null;
  listedAt: string;
  seatsWanted: number | null;
  joined: number;
  games: string[];
  owner: FriendCard;
  isOwnerFriend: boolean;
  friendsIn: FriendCard[];
};

export const RUN_LISTINGS_KEY = ["run-listings"] as const;

function isCard(v: unknown): v is FriendCard {
  return typeof v === "object" && v !== null && hasStringProp(v, "userId") && hasStringProp(v, "slug") && hasNullableStringProp(v, "displayName") && hasNullableStringProp(v, "avatarUrl");
}

function isRunListing(v: unknown): v is RunListing {
  if (typeof v !== "object" || v === null) return false;
  if (!hasStringProp(v, "runId") || !hasStringProp(v, "title") || !hasStringProp(v, "pitch") || !hasStringProp(v, "listedAt") || !hasNullableStringProp(v, "plannedFor")) return false;
  if (!("joined" in v) || typeof v.joined !== "number") return false;
  if (!("seatsWanted" in v) || (v.seatsWanted !== null && typeof v.seatsWanted !== "number")) return false;
  if (!("games" in v) || !Array.isArray(v.games) || !v.games.every((g) => typeof g === "string")) return false;
  if (!("owner" in v) || !isCard(v.owner) || !hasBooleanProp(v, "isOwnerFriend")) return false;
  return "friendsIn" in v && Array.isArray(v.friendsIn) && v.friendsIn.every(isCard);
}

/** The open listings, the latest first. Null on failure. */
export async function fetchRunListings(): Promise<RunListing[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/run-listings`);
    if (!res.ok) return null;
    const json: unknown = await res.json();
    const data = typeof json === "object" && json !== null && "data" in json ? json.data : null;
    return Array.isArray(data) && data.every(isRunListing) ? data : null;
  } catch {
    return null;
  }
}

export type ListingReportResult = "ok" | "forbidden" | "invalid" | "not_found" | "error";

/** Reports a listing to the moderators (story 43.17). */
export async function reportRunListing(runId: string, problem: string, comment: string): Promise<ListingReportResult> {
  try {
    const trimmed = comment.trim();
    const res = await apiFetch(`${env.apiBaseUrl}/community/run-listings/${encodeURIComponent(runId)}/report`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ problem, comment: trimmed === "" ? null : trimmed }),
    });
    if (res.status === 204) return "ok";
    if (res.status === 403) return "forbidden";
    if (res.status === 422) return "invalid";
    if (res.status === 404) return "not_found";
    return "error";
  } catch {
    return "error";
  }
}
