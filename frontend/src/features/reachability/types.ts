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
