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
  return "reachable_unchecked" in v && Array.isArray(v.reachable_unchecked);
}

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
