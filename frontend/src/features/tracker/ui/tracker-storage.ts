import type { ItemState } from "../engine/items";
import type { EngineAction, LoadedPack } from "../engine/protocol";
import type { TrackerView } from "./tracker-view";

/**
 * The manual state of a tracker, kept in the browser (story 54.5, decision 8). Nothing goes to the API. The key
 * names the pack version and the variant: item and section ids are stable for one of them, since the engine numbers
 * them in load order. Story 54.7 adds the slot to the key.
 */
export function trackerStorageKey(pack: LoadedPack): string {
  return `tracker:${pack.manifest.packageUid}:${pack.manifest.packageVersion}:${pack.variantUid}`;
}

type SavedItem = { active: boolean; stage: number; count: number };

export type SavedTracker = {
  version: 1;
  items: Record<string, SavedItem>;
  /**
   * Section id to the checks left. Compared with the state right after loading, not with `item_count`: a pack's
   * Lua may set a section's count when it loads (Majora's Mask does for its counters).
   */
  sections: Record<string, number>;
};

function sameItem(a: ItemState, b: ItemState): boolean {
  return a.active === b.active && a.stage === b.stage && a.count === b.count;
}

/** What changed since the pack loaded: a pack whose `init.lua` sets items needs no copy of them. */
export function snapshot(pack: LoadedPack, initial: TrackerView, view: TrackerView): SavedTracker {
  const items: Record<string, SavedItem> = {};
  for (const [id, state] of Object.entries(view.items)) {
    const start = initial.items[Number(id)];
    if (start !== undefined && sameItem(start, state)) continue;
    items[id] = { active: state.active, stage: state.stage, count: state.count };
  }
  const sections: Record<string, number> = {};
  for (const location of pack.locations) {
    for (const section of location.sections) {
      if (section.refId !== null || section.itemCount === 0) continue;
      const state = view.sections[section.id];
      const start = initial.sections[section.id];
      if (state === undefined || start === undefined || state.available === start.available) continue;
      sections[String(section.id)] = state.available;
    }
  }
  return { version: 1, items, sections };
}

function isSavedItem(value: unknown): value is SavedItem {
  return (
    typeof value === "object" &&
    value !== null &&
    "active" in value &&
    typeof value.active === "boolean" &&
    "stage" in value &&
    typeof value.stage === "number" &&
    "count" in value &&
    typeof value.count === "number"
  );
}

export function isSavedTracker(value: unknown): value is SavedTracker {
  if (typeof value !== "object" || value === null) return false;
  if (!("version" in value) || value.version !== 1) return false;
  if (!("items" in value) || typeof value.items !== "object" || value.items === null) return false;
  if (!("sections" in value) || typeof value.sections !== "object" || value.sections === null) return false;
  return (
    Object.values(value.items).every(isSavedItem) &&
    Object.values(value.sections).every((count) => typeof count === "number")
  );
}

/**
 * The actions that bring a freshly loaded pack (`loaded`, its state) back to a saved state; unknown ids are dropped.
 * A section with more checks left than at load time was undone past it: undoing it again gets there.
 */
export function restoreActions(pack: LoadedPack, loaded: TrackerView, saved: SavedTracker): EngineAction[] {
  const itemIds = new Set(pack.items.map((item) => item.id));
  const sections = new Map(pack.locations.flatMap((location) => location.sections.map((section) => [section.id, section])));
  const actions: EngineAction[] = [];
  for (const [id, state] of Object.entries(saved.items)) {
    if (itemIds.has(Number(id))) actions.push({ type: "itemSet", itemId: Number(id), state });
  }
  for (const [id, available] of Object.entries(saved.sections)) {
    const section = sections.get(Number(id));
    const now = loaded.sections[Number(id)]?.available;
    if (section === undefined || now === undefined) continue;
    const target = Math.min(Math.max(0, Math.trunc(available)), section.itemCount);
    if (target < now) actions.push({ type: "sectionClear", sectionId: section.id, count: now - target });
    else if (target > now) {
      const steps = section.clearAsGroup ? 1 : target - now;
      for (let step = 0; step < steps; step += 1) actions.push({ type: "sectionUnclear", sectionId: section.id });
      // A group comes back whole: take off what was left done.
      if (section.clearAsGroup && target < section.itemCount) {
        actions.push({ type: "sectionClear", sectionId: section.id, count: section.itemCount - target });
      }
    }
  }
  return actions;
}

/** `localStorage` may be missing or refuse (private browsing, quota): the tracker then simply forgets. */
export function readSaved(key: string): SavedTracker | null {
  try {
    const raw = window.localStorage.getItem(key);
    if (raw === null) return null;
    const parsed: unknown = JSON.parse(raw);
    return isSavedTracker(parsed) ? parsed : null;
  } catch {
    return null;
  }
}

export function writeSaved(key: string, saved: SavedTracker): void {
  try {
    if (Object.keys(saved.items).length === 0 && Object.keys(saved.sections).length === 0) {
      window.localStorage.removeItem(key);
      return;
    }
    window.localStorage.setItem(key, JSON.stringify(saved));
  } catch {
    // Nothing to do: the state lives on until the page closes.
  }
}

export function clearSaved(key: string): void {
  try {
    window.localStorage.removeItem(key);
  } catch {
    // Same as above.
  }
}

const SETTINGS_KEY = "tracker:settings";
/** Same-tab writes: the `storage` event only reaches the other tabs. */
const SETTINGS_EVENT = "tracker-settings";

export function subscribeSettings(listener: () => void): () => void {
  window.addEventListener("storage", listener);
  window.addEventListener(SETTINGS_EVENT, listener);
  return () => {
    window.removeEventListener("storage", listener);
    window.removeEventListener(SETTINGS_EVENT, listener);
  };
}

/** The raw stored settings, a string so that `useSyncExternalStore` compares snapshots by value. */
export function readSettingsRaw(): string | null {
  try {
    return window.localStorage.getItem(SETTINGS_KEY);
  } catch {
    return null;
  }
}

export function parseSettings<T>(raw: string | null, fallback: T, isValid: (value: unknown) => value is T): T {
  if (raw === null) return fallback;
  try {
    const parsed: unknown = JSON.parse(raw);
    return isValid(parsed) ? parsed : fallback;
  } catch {
    return fallback;
  }
}

export function writeSettings(settings: unknown): void {
  try {
    window.localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
  } catch {
    // Settings are a convenience.
  }
  window.dispatchEvent(new Event(SETTINGS_EVENT));
}
