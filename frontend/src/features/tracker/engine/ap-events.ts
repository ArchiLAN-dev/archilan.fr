/**
 * What crosses between the Archipelago connection (on the page) and the pack engine (in the Worker), story 54.6.
 * The page translates server packets into these events; the engine calls the pack's handlers with them, as
 * PopTracker's "Archipelago Interface" does (`doc/AUTOTRACKING.md`).
 */

/** Names of one game, by id, for `Archipelago:GetItemName` and `GetLocationName`. */
export type ApGameNames = { items: Record<number, string>; locations: Record<number, string> };

export type ApPlayer = { slot: number; team: number; alias: string; name: string; game: string };

export type ApReceivedItem = { index: number; item: number; player: number; location: number; flags: number };

export type ApScouted = { location: number; item: number; player: number; flags: number };

export type ApEvent =
  /** Sets the `Archipelago` properties and the name tables; calls no handler. */
  | {
      kind: "connected";
      playerNumber: number;
      teamNumber: number;
      seed: string;
      game: string;
      checked: number[];
      missing: number[];
      players: ApPlayer[];
      names: Record<string, ApGameNames>;
    }
  /** Every Clear handler, with the slot data. */
  | { kind: "clear"; slotData: unknown }
  /** One Item handler call per item, in index order. */
  | { kind: "items"; items: ApReceivedItem[] }
  /** One Location handler call per location. */
  | { kind: "locations"; locations: number[] }
  | { kind: "scouted"; locations: ApScouted[] }
  | { kind: "bounced"; data: unknown }
  /** One key of a `Retrieved` packet: a key whose value is null still calls the handlers. */
  | { kind: "retrieved"; key: string; value: unknown }
  | { kind: "setReply"; key: string; value: unknown; originalValue: unknown }
  | { kind: "disconnected" };

/** What the pack asks the server for, through the page. */
export type ApOutgoing = { cmd: "Get" | "SetNotify"; keys: string[] };

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

/** Shallow: the events come from our own page code, only their shape is checked. */
export function isApEvent(value: unknown): value is ApEvent {
  if (!isObject(value)) return false;
  switch (value.kind) {
    case "connected":
      return (
        typeof value.playerNumber === "number" &&
        typeof value.teamNumber === "number" &&
        Array.isArray(value.checked) &&
        Array.isArray(value.missing) &&
        Array.isArray(value.players) &&
        isObject(value.names)
      );
    case "clear":
    case "bounced":
      return true;
    case "items":
      return Array.isArray(value.items);
    case "locations":
      return Array.isArray(value.locations);
    case "scouted":
      return Array.isArray(value.locations);
    case "retrieved":
    case "setReply":
      return typeof value.key === "string";
    case "disconnected":
      return true;
    default:
      return false;
  }
}
