import type { ItemDefinition, ItemState } from "../engine/items";
import { AccessibilityLevel } from "../engine/levels";
import type { LoadedLocation, LoadedPack, LoadedSection, SectionState, StateUpdate } from "../engine/protocol";

/**
 * What the tracker screen draws (story 54.5): the engine sends diffs by id, the screen keeps the whole picture.
 */
export type TrackerView = {
  items: Record<number, ItemState>;
  sections: Record<number, SectionState>;
  locations: Record<number, AccessibilityLevel>;
};

export const EMPTY_VIEW: TrackerView = { items: {}, sections: {}, locations: {} };

export function mergeView(view: TrackerView, update: StateUpdate): TrackerView {
  return {
    items: { ...view.items, ...update.items },
    sections: { ...view.sections, ...update.sections },
    locations: { ...view.locations, ...update.locations },
  };
}

/** Screen settings (decision 7). */
export type TrackerSettings = { showSequenceBreaks: boolean; hideCleared: boolean };

export const DEFAULT_SETTINGS: TrackerSettings = { showSequenceBreaks: true, hideCleared: false };

/** Without sequence breaks, what needs one reads as out of reach, as in PopTracker. */
export function displayLevel(level: AccessibilityLevel, settings: TrackerSettings): AccessibilityLevel {
  if (level === AccessibilityLevel.SequenceBreak && !settings.showSequenceBreaks) return AccessibilityLevel.None;
  return level;
}

export type LevelStyle = { label: string; className: string };

/** The legend, worst to best: one color per level, from the site tokens. */
export const LEVEL_STYLES: Record<AccessibilityLevel, LevelStyle> = {
  [AccessibilityLevel.None]: { label: "Inaccessible", className: "bg-tracker-none" },
  [AccessibilityLevel.Partial]: { label: "En partie accessible", className: "bg-tracker-partial" },
  [AccessibilityLevel.Inspect]: { label: "À inspecter", className: "bg-tracker-inspect" },
  [AccessibilityLevel.SequenceBreak]: { label: "Séquence break", className: "bg-tracker-sequence" },
  [AccessibilityLevel.Normal]: { label: "Accessible", className: "bg-tracker-normal" },
  [AccessibilityLevel.Cleared]: { label: "Fait", className: "bg-tracker-cleared" },
};

export const LEGEND_ORDER: AccessibilityLevel[] = [
  AccessibilityLevel.Normal,
  AccessibilityLevel.Partial,
  AccessibilityLevel.SequenceBreak,
  AccessibilityLevel.Inspect,
  AccessibilityLevel.None,
  AccessibilityLevel.Cleared,
];

/** A `ref` section shows the state of the section it points to. */
export function sectionStateOf(section: LoadedSection, view: TrackerView): SectionState | undefined {
  return view.sections[section.refId ?? section.id];
}

/** The level of a pin: `null` when the location has nothing visible to show. */
export function locationLevel(location: LoadedLocation, view: TrackerView): AccessibilityLevel | null {
  if (location.sections.length === 0) return null;
  const visible = location.sections.some((section) => sectionStateOf(section, view)?.visible !== false);
  if (!visible) return null;
  return view.locations[location.id] ?? null;
}

/**
 * Items by code, the first one that gives it winning (as the engine's `FindObjectForCode`). Layouts name items by
 * code, so does a section's `hosted_item`.
 */
export function indexItemsByCode(items: readonly ItemDefinition[]): Map<string, ItemDefinition> {
  const byCode = new Map<string, ItemDefinition>();
  for (const item of items) {
    for (const stage of item.stages) {
      for (const code of stage.codes) {
        if (!byCode.has(code)) byCode.set(code, item);
      }
    }
  }
  return byCode;
}

/** The root layout of the screen, as PopTracker picks it. */
export function rootLayoutName(pack: LoadedPack): string | null {
  for (const name of ["tracker_default", "tracker_horizontal", "tracker_vertical"]) {
    if (name in pack.layouts) return name;
  }
  return null;
}
