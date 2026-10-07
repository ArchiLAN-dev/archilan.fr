export type SlotEntry = { index: string; name: string };

export type CheckEntry = {
  id: number;
  name: string;
  item?: { id: number; name: string; flags: number; slot: number; slot_name: string };
  check_status?: "checked" | "reachable" | "blocked";
};

export type SphereEntry = {
  index: number;
  status: "past" | "current" | "future" | "blocked";
  counts: { total: number; checked: number; reachable: number; blocked: number };
  locations: CheckEntry[];
};

export type ItemEntry = {
  id: number;
  name: string;
  count: number;
};

export type ItemLocation = {
  locationName: string;
  gameName: string | null;
  checkStatus: "reachable" | "blocked" | "checked" | null;
};

export type ReachabilityData = {
  game: string;
  player: string;
  reachable_unchecked: CheckEntry[];
  reachable_checked: CheckEntry[];
  unreachable_unchecked: CheckEntry[];
  checked_unreachable: CheckEntry[];
  items_received: ItemEntry[];
  items_not_received: ItemEntry[];
  spheres?: SphereEntry[];
  counts: { checked: number; total: number; reachable_now: number };
  cached?: boolean;
};

export type ToastItem = { id: number; name: string; flags: number };

export type HintEntry = {
  receivingPlayer: number;
  receivingPlayerName: string;
  findingPlayer: number;
  findingPlayerName: string;
  locationId: number;
  locationName: string;
  itemId: number;
  itemName: string;
  itemFlags: number;
  entrance: string;
  found: boolean;
  status: number;
  statusName: string;
};

export type HintsData = {
  slot: number;
  hints: HintEntry[];
  hintsUsed: number;
  hintPointsAvailable: number;
  hintCost: number;
};

// ─── SSE payload guards (story 33.19) ────────────────────────────────────────
// Structural checks on the discriminating fields only: the bridge is the single publisher of these
// shapes, so a full deep validation would duplicate its contract for no safety gain. A frame that
// passes the discriminants but carries a malformed nested entry degrades exactly as it did when the
// pages cast blindly - except non-object garbage is now dropped at the door.

export function isReachabilityData(v: unknown): v is ReachabilityData {
  if (typeof v !== "object" || v === null) return false;
  if (!("counts" in v) || typeof v.counts !== "object" || v.counts === null) return false;
  // Every array the pages hard-dereference (map/spread/length) is verified, so a passing frame
  // can never throw mid-handler or mid-render. The bridge always publishes all of them together
  // (review-verified against reachable.py) - these checks cost nothing in drop risk.
  return (
    "reachable_unchecked" in v && Array.isArray(v.reachable_unchecked)
    && "reachable_checked" in v && Array.isArray(v.reachable_checked)
    && "unreachable_unchecked" in v && Array.isArray(v.unreachable_unchecked)
    && "checked_unreachable" in v && Array.isArray(v.checked_unreachable)
    && "items_received" in v && Array.isArray(v.items_received)
    && "items_not_received" in v && Array.isArray(v.items_not_received)
  );
}

/**
 * Story 17.28: what the player reads when the game server answered something that is not a computation (a
 * tracking daemon out of step, for instance) - the API was reached, it is not a network failure.
 */
export const REACHABILITY_INVALID_MESSAGE =
  "Le calcul de ce slot n'a pas abouti (le serveur de partie a renvoyé une réponse inattendue). Réessaie dans un instant.";

/** Story 17.28: the computation carried by an API answer `{ data }`, or null when it is not one. */
export function reachabilityOf(payload: unknown): ReachabilityData | null {
  if (typeof payload !== "object" || payload === null || !("data" in payload)) return null;
  return isReachabilityData(payload.data) ? payload.data : null;
}

/**
 * Story 17.28: what a reachability request answered. The game server does not hold the request while its
 * tracking daemon starts (up to a minute on a big run): it answers 202 « computing », with the slot's last
 * result when there is one, and the new result reaches the page through the live push.
 */
export type ReachableAnswer =
  | { kind: "data"; data: ReachabilityData; computing: boolean }
  | { kind: "computing" }
  | { kind: "invalid" };

export function reachableAnswerOf(status: number, payload: unknown): ReachableAnswer {
  const data = reachabilityOf(payload);
  if (status === 202) return data ? { kind: "data", data, computing: true } : { kind: "computing" };
  return data ? { kind: "data", data, computing: false } : { kind: "invalid" };
}

/** Story 17.28: while computing, the page asks again this often in case the live push is not connected. */
export const REACHABILITY_RETRY_MS = 15_000;

/** Hints pushes arrive as partial frames; `hints` is the discriminant the pages already keyed on. */
export function isHintsUpdate(v: unknown): v is Partial<HintsData> & Pick<HintsData, "hints"> {
  if (typeof v !== "object" || v === null) return false;
  return "hints" in v && Array.isArray(v.hints);
}

/** Archipelago hint status values (int) → name, mirroring the bridge HintStatus enum. */
export const HINT_STATUS_NAMES: Record<number, string> = {
  0: "unspecified",
  10: "no_priority",
  20: "avoid",
  30: "priority",
  40: "found",
};

/** Statuses a player may set on the hints page ("found" is bridge-managed). */
export const SETTABLE_HINT_STATUSES: { value: number; label: string }[] = [
  { value: 30, label: "Prioritaire" },
  { value: 10, label: "Faible prio." },
  { value: 20, label: "Éviter" },
  { value: 0, label: "Non classé" },
];
