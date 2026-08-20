import { receivedItemsPercent } from "@/features/reachability/item-progress";
import { isReachabilityData } from "@/features/reachability/types";
import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";

// ─── Types ───────────────────────────────────────────────────────────────────

export type OverlaySubscribe = {
  token: string;
  hubUrl: string;
  topics: string[];
};

// One end of an item transfer (sender = finder, receiver = owner of the item), as resolved by the
// bridge. `game` is the player's world.
export type FeedActor = { slot: number; name: string; game: string };
// A named id reference (the item, or the origin check/location). On the item ref the bridge also
// sends the AP classification `flags` (bit 1 = progression, story 32.9); absent from older bridges
// and from location refs.
export type FeedRef = { id: number; name: string; flags?: number };

// Feed event shape published by the bridge on `runs/{id}/feed` (see EventFeed / story 9.13). The
// `item`/`location`/`sender`/`receiver` fields are present only on item events (story 29.4) and only
// when the origin resolved - always fall back to `text` when they are absent.
export type FeedEvent = {
  type: string;
  // Absent on bridge death_link frames ({type, source, cause, timestamp}) - render with `?? ""`.
  text?: string;
  color?: string;
  timestamp: string;
  item?: FeedRef;
  location?: FeedRef;
  sender?: FeedActor;
  receiver?: FeedActor;
  // Marker set by the overlay-test endpoint so a per-slot overlay still shows the operator's "Test"
  // event (slot filter bypassed). Absent on real feed events.
  __test__?: boolean;
};

/** True when `actor` is one of the filtered slots (matched by slot index or display name). */
export function actorMatchesSlots(actor: FeedActor | undefined, slots: string[]): boolean {
  return actor !== undefined && slots.some((s) => String(actor.slot) === s || actor.name === s);
}

/** True when the event involves one of the filtered slots as sender (finder) or receiver (owner). */
export function eventInvolvesSlots(event: FeedEvent, slots: string[]): boolean {
  return actorMatchesSlots(event.sender, slots) || actorMatchesSlots(event.receiver, slots);
}

/**
 * Human-readable origin of an item event: "{check} - {world} ({sender})", e.g.
 * "Bowser - Mario 64 (Michel_M)". Returns null when the structured origin is absent (older bridge,
 * non-item event), so callers degrade to the prose `text`. Pure, no I/O.
 */
export function feedItemOrigin(event: FeedEvent): string | null {
  if (!event.sender) return null;
  const head = [event.location?.name, event.sender.game].filter((s) => !!s).join(" - ");
  return head ? `${head} (${event.sender.name})` : event.sender.name;
}

// Per-slot state published on `runs/{id}/players` (see PlayerProgressGrid).
export type PlayersSlot = {
  slot_name: string;
  checks_done: number;
  checks_total: number;
  items_received: number;
  client_status: number;
  goal_reached_at: string | null;
  // Count of reachable-but-unchecked locations, or null until the first reachability computation.
  reachable_now?: number | null;
};

export type PlayersState = {
  slots?: Record<string, PlayersSlot>;
  // Marker set by the overlay-test endpoint so the goals widget celebrates immediately, bypassing its
  // load-time baseline suppression. Absent on real `players` updates.
  __test__?: boolean;
};

// ─── Type guards ───────────────────────────────────────────────────────────────

/**
 * Guard for feed frames on `runs/{id}/feed` (story 33.19 - the ONE FeedEvent shape).
 * `text` is optional: the bridge's death_link frames carry none (review-verified against
 * bridge/core/ap_client.py) and must not be dropped.
 */
export function isFeedEvent(v: unknown): v is FeedEvent {
  if (typeof v !== "object" || v === null) return false;
  if (!("type" in v) || typeof v.type !== "string") return false;
  if ("text" in v && typeof v.text !== "string" && v.text !== undefined) return false;
  return "timestamp" in v && typeof v.timestamp === "string";
}

/** Guard for per-slot state frames on `runs/{id}/players` (story 33.19 - the ONE players shape). */
export function isPlayersState(v: unknown): v is PlayersState {
  if (typeof v !== "object" || v === null) return false;
  if (!("slots" in v)) return true;
  if (typeof v.slots !== "object" || v.slots === null) return false;
  return Object.values(v.slots).every((s) => typeof s === "object" && s !== null);
}

export function isOverlaySubscribe(v: unknown): v is OverlaySubscribe {
  if (typeof v !== "object" || v === null) return false;
  if (!("token" in v) || !("hubUrl" in v) || !("topics" in v)) return false;
  return (
    typeof v.token === "string" &&
    typeof v.hubUrl === "string" &&
    Array.isArray(v.topics) &&
    v.topics.every((t) => typeof t === "string")
  );
}

// ─── Fetch ─────────────────────────────────────────────────────────────────────

/**
 * Returns a short-lived Mercure subscriber payload for a session's overlay topics. Public endpoint,
 * tokenless (overlays are read-only and meant to be shown on stream): NO credentials and NOT apiFetch.
 * Returns null on any error or invalid shape.
 */
export async function fetchOverlaySubscribe(sessionId: string): Promise<OverlaySubscribe | null> {
  if (!sessionId) return null;
  try {
    const res = await fetch(`${env.apiBaseUrl}/public/overlay/${encodeURIComponent(sessionId)}/subscribe`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null) return null;
    const data: unknown = "data" in payload ? payload.data : null;
    return isOverlaySubscribe(data) ? data : null;
  } catch {
    return null;
  }
}

// Real progression figures shown by the goal celebration - same formulas as the progression page.
export type SlotGoalStats = { gameName: string; checksPercent: number; itemsPercent: number };

/**
 * Fetches a slot's reachability snapshot (anonymous GET, same public endpoint the progression page and
 * the reachable overlay use) and derives the celebration's percentages with the progression page's
 * formulas. The `players` frame alone cannot provide them: it carries no items total. Returns null on
 * any error or invalid shape - the caller falls back to the frame's checks counts.
 */
export async function fetchSlotGoalStats(sessionId: string, slotKey: string): Promise<SlotGoalStats | null> {
  if (!sessionId || !slotKey) return null;
  try {
    // Bounded wait: the bridge recomputes reachability when its cache is stale, which can take a few
    // seconds - never hold the celebration longer than this.
    const res = await fetch(
      `${env.apiBaseUrl}/sessions/${encodeURIComponent(sessionId)}/slots/${encodeURIComponent(slotKey)}/reachable`,
      { signal: AbortSignal.timeout(8_000) },
    );
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    const data: unknown = typeof payload === "object" && payload !== null && "data" in payload ? payload.data : payload;
    if (!isReachabilityData(data)) return null;
    return {
      gameName: data.game,
      checksPercent: Math.round((data.counts.checked / Math.max(1, data.counts.total)) * 100),
      itemsPercent: receivedItemsPercent(data.items_received, data.items_not_received),
    };
  } catch {
    return null;
  }
}

// A session slot offered in the panel's per-slot filter dropdown.
export type OverlaySlot = { key: string; name: string };

function readSlotName(value: unknown, key: string): string {
  if (typeof value === "object" && value !== null && "slot_name" in value && typeof value.slot_name === "string") {
    return value.slot_name;
  }
  return `Slot ${key}`;
}

// Excludes the injected TextOnly "Bridge" observer (game "Archipelago", conventional name "Bridge") and
// any spectator/group slot, so only real players are offered in the filter. The name fallback also
// covers a bridge that does not yet send `game`/`slot_type` (not redeployed). Lenient otherwise: a slot
// is kept when these fields are absent rather than wrongly hidden.
function isRealPlayerSlot(value: unknown): boolean {
  if (typeof value !== "object" || value === null) return false;
  if ("slot_type" in value && typeof value.slot_type === "string" && value.slot_type !== "player") {
    return false;
  }
  if ("game" in value && typeof value.game === "string" && (value.game === "" || value.game === "Archipelago")) {
    return false;
  }
  if ("slot_name" in value && typeof value.slot_name === "string" && value.slot_name.toLowerCase() === "bridge") {
    return false;
  }
  return true;
}

/**
 * Lists the session's slots (index + display name) for the per-slot overlay filter. Authenticated
 * (admin / session owner) via the existing `/players` endpoint, which proxies the bridge state. Returns
 * `null` on any error (e.g. the session is not running yet, so no bridge state) - the panel then offers
 * only "all slots". Never throws.
 */
export async function fetchOverlaySlots(sessionId: string): Promise<OverlaySlot[] | null> {
  if (!sessionId) return null;
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/sessions/${encodeURIComponent(sessionId)}/players`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    if (typeof payload !== "object" || payload === null) return null;
    const data: unknown = "data" in payload ? payload.data : null;
    if (typeof data !== "object" || data === null || !("slots" in data)) return null;
    const slots: unknown = data.slots;
    if (typeof slots !== "object" || slots === null) return null;
    return Object.entries(slots)
      .filter(([, value]) => isRealPlayerSlot(value))
      .map(([key, value]) => ({ key, name: readSlotName(value, key) }));
  } catch {
    return null;
  }
}

/**
 * Publishes a real sample event on the session's overlay-only test channel so live overlays (preview
 * iframe + the actual OBS source) react - without the event reaching player progression pages.
 * `slot` (a single slot key, or "" for none) targets the sample at that player so the test honors the
 * per-slot filter just like a real event. Authenticated (admin / session owner). Returns true on success.
 */
export async function testOverlayEvent(sessionId: string, type: string, slot: string): Promise<boolean> {
  try {
    const res = await apiFetch(
      `${env.apiBaseUrl}/sessions/${encodeURIComponent(sessionId)}/overlay-test`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ type, slot }),
      },
    );
    return res.ok;
  } catch {
    return false;
  }
}
