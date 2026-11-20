import { isApEvent, type ApEvent, type ApOutgoing } from "./ap-events";
import type { ItemDefinition, ItemState, MouseButton } from "./items";
import type { AccessibilityLevel, Highlight } from "./levels";
import type { ChestImages, MapLocation } from "./locations";

/**
 * The messages between the page and the tracker Worker (story 54.4). The page never touches the engine: it posts a
 * request with a `requestId` and gets a response carrying the same id (`loaded`, `state`, `image` or `error`).
 * `log` messages may come at any time.
 */
export type PackVariant = { uid: string; displayName: string; flags: string[] };

export type PackManifest = {
  name: string;
  gameName: string;
  packageUid: string;
  packageVersion: string;
  platform: string;
  variants: PackVariant[];
  minPopTrackerVersion: string | null;
  targetPopTrackerVersion: string | null;
};

export type LoadedMap = {
  name: string;
  img: string | null;
  locationSize: number | null;
  locationBorderThickness: number | null;
  locationShape: string | null;
};

export type LoadedSection = {
  id: number;
  path: string;
  name: string;
  itemCount: number;
  hostedItems: string[];
  clearAsGroup: boolean;
  /** For a `ref` section, the id of the section it shows: its state is under that id. */
  refId: number | null;
  images: ChestImages;
};

export type LoadedLocation = {
  id: number;
  /** Not unique: a pack may have several unnamed children under one location. */
  path: string;
  name: string;
  parentId: number | null;
  mapLocations: MapLocation[];
  images: ChestImages;
  overlayBackground: string | null;
  sections: LoadedSection[];
};

export type LoadedPack = {
  manifest: PackManifest;
  variantUid: string;
  items: ItemDefinition[];
  maps: LoadedMap[];
  /** Layout widgets by name, as JSON (drawn by story 54.5). */
  layouts: Record<string, unknown>;
  locations: LoadedLocation[];
};

export type SectionState = {
  available: number;
  /** `Cleared` once every check is done. */
  level: AccessibilityLevel;
  visible: boolean;
  highlight: Highlight;
};

/** A diff by id: only what changed since the previous message (everything in `loaded`). */
export type StateUpdate = {
  items: Record<number, ItemState>;
  /** `ref` sections are absent: they share the state of the section they show. */
  sections: Record<number, SectionState>;
  /** Locations that have sections. */
  locations: Record<number, AccessibilityLevel>;
  /**
   * Story 54.11: whether a pin with its own rules (`restrict_visibility_rules`, `force_invisibility_rules`) shows, by
   * `pinKey(locationId, index in mapLocations)`. A pin without such rules is absent and shows.
   */
  pins?: Record<string, boolean>;
  /** Story 54.11: `Tracker:UiHint(name, value)` calls since the last update, in order. */
  hints?: UiHint[];
};

export type UiHint = { name: string; value: string };

export function pinKey(locationId: number, index: number): string {
  return `${locationId}:${index}`;
}

export type ItemStatePatch = { active?: boolean; stage?: number; count?: number };

export type EngineAction =
  | { type: "itemClick"; itemId: number; button: MouseButton }
  | { type: "itemSet"; itemId: number; state: ItemStatePatch }
  | { type: "sectionClear"; sectionId: number; count?: number }
  | { type: "sectionUnclear"; sectionId: number };

export type TrackerRequest =
  | { type: "load"; requestId: number; archive: ArrayBuffer; rootPath: string; variantUid: string }
  | (EngineAction & { requestId: number })
  | { type: "bulk"; requestId: number; actions: EngineAction[] }
  | { type: "image"; requestId: number; path: string }
  /** Story 54.6: Archipelago events, applied together with one logic pass at the end. */
  | { type: "ap"; requestId: number; events: ApEvent[] }
  /** Story 54.13: the page's heartbeat; a Worker stuck in the pack never answers it. */
  | { type: "ping"; requestId: number };

export type TrackerErrorKind =
  | "archive_invalid"
  | "manifest_invalid"
  | "variant_unknown"
  | "file_missing"
  | "json_invalid"
  | "lua_error"
  | "unsupported_api"
  | "not_loaded"
  | "bad_request"
  | "timeout"
  | "worker_failed";

export type TrackerError = {
  kind: TrackerErrorKind;
  message: string;
  file?: string;
  line?: number;
};

export type LogLevel = "info" | "warn" | "error";

export type TrackerResponse =
  | { type: "loaded"; requestId: number; pack: LoadedPack; state: StateUpdate }
  | { type: "state"; requestId: number; state: StateUpdate }
  | { type: "image"; requestId: number; path: string; blob: Blob | null }
  | ({ type: "error"; requestId: number | null } & TrackerError)
  | { type: "log"; level: LogLevel; message: string }
  /** Story 54.6: what the pack asks the Archipelago server for (`Get`, `SetNotify`), sent by the page. */
  | { type: "apSend"; packets: ApOutgoing[] }
  /** Story 54.11: what the pack's frame handlers changed, unasked. */
  | { type: "frame"; state: StateUpdate }
  | { type: "pong"; requestId: number };

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isButton(value: unknown): value is MouseButton {
  return value === "left" || value === "right";
}

function isOptionalNumber(value: unknown): value is number | undefined {
  return value === undefined || typeof value === "number";
}

function isStatePatch(value: unknown): value is ItemStatePatch {
  return (
    isObject(value) &&
    (value.active === undefined || typeof value.active === "boolean") &&
    isOptionalNumber(value.stage) &&
    isOptionalNumber(value.count)
  );
}

export function isEngineAction(value: unknown): value is EngineAction {
  if (!isObject(value)) return false;
  switch (value.type) {
    case "itemClick":
      return typeof value.itemId === "number" && isButton(value.button);
    case "itemSet":
      return typeof value.itemId === "number" && isStatePatch(value.state);
    case "sectionClear":
      return typeof value.sectionId === "number" && isOptionalNumber(value.count);
    case "sectionUnclear":
      return typeof value.sectionId === "number";
    default:
      return false;
  }
}

export function isTrackerRequest(value: unknown): value is TrackerRequest {
  if (!isObject(value) || typeof value.requestId !== "number") return false;
  switch (value.type) {
    case "load":
      return (
        value.archive instanceof ArrayBuffer && typeof value.rootPath === "string" && typeof value.variantUid === "string"
      );
    case "bulk":
      return Array.isArray(value.actions) && value.actions.every(isEngineAction);
    case "image":
      return typeof value.path === "string";
    case "ap":
      return Array.isArray(value.events) && value.events.every(isApEvent);
    case "ping":
      return true;
    default:
      return isEngineAction(value);
  }
}

/**
 * Story 54.13: what the pack may have the page send to the Archipelago server, checked again on the page side: only
 * reads of data storage keys, whatever a Worker the pack escaped into would post.
 */
export function isApOutgoing(value: unknown): value is ApOutgoing {
  return (
    isObject(value) &&
    (value.cmd === "Get" || value.cmd === "SetNotify") &&
    Array.isArray(value.keys) &&
    value.keys.every((key) => typeof key === "string") &&
    Object.keys(value).every((key) => key === "cmd" || key === "keys")
  );
}

/** Shallow on purpose: the payload comes from our own Worker, only its envelope is checked (apSend aside). */
export function isTrackerResponse(value: unknown): value is TrackerResponse {
  if (!isObject(value)) return false;
  switch (value.type) {
    case "loaded":
      return typeof value.requestId === "number" && isObject(value.pack) && isObject(value.state);
    case "state":
      return typeof value.requestId === "number" && isObject(value.state);
    case "image":
      return typeof value.requestId === "number" && typeof value.path === "string";
    case "error":
      return (
        (typeof value.requestId === "number" || value.requestId === null) &&
        typeof value.kind === "string" &&
        typeof value.message === "string"
      );
    case "log":
      return typeof value.level === "string" && typeof value.message === "string";
    case "apSend":
      return Array.isArray(value.packets) && value.packets.every(isApOutgoing);
    case "frame":
      return isObject(value.state);
    case "pong":
      return typeof value.requestId === "number";
    default:
      return false;
  }
}
