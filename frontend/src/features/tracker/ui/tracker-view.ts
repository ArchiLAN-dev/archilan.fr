import type { ItemDefinition, ItemState } from "../engine/items";
import { AccessibilityLevel, Highlight } from "../engine/levels";
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
  // Story 54.9: plain words rather than PopTracker's jargon (« sequence break », « inspect »).
  [AccessibilityLevel.None]: { label: "Pas encore", className: "bg-tracker-none" },
  [AccessibilityLevel.Partial]: { label: "En partie faisable", className: "bg-tracker-partial" },
  [AccessibilityLevel.Inspect]: { label: "À regarder", className: "bg-tracker-inspect" },
  [AccessibilityLevel.SequenceBreak]: { label: "Hors logique", className: "bg-tracker-sequence" },
  [AccessibilityLevel.Normal]: { label: "Faisable", className: "bg-tracker-normal" },
  [AccessibilityLevel.Cleared]: { label: "Fait", className: "bg-tracker-cleared" },
};

/**
 * Story 54.9: the legend reads as PopTracker's. « En partie accessible » is not a colour of its own there: a location
 * whose sections differ shows each state in a part of its pin (`PIN_PARTS`).
 */
export const LEGEND_ORDER: AccessibilityLevel[] = [
  AccessibilityLevel.Normal,
  AccessibilityLevel.SequenceBreak,
  AccessibilityLevel.Inspect,
  AccessibilityLevel.None,
  AccessibilityLevel.Cleared,
];

/**
 * Story 54.9: the states a location's pin gathers from its sections, as PopTracker's `CalculateLocationState`: one
 * bit per state met among the visible sections still to do; 0 when all are done.
 */
export const PinMark = { Reachable: 1, Unreachable: 2, SequenceBreak: 4, Inspect: 8 } as const;

/** What a section still to do adds to its pin; PopTracker counts any other level (a partial one) as a glitch. */
function markOf(level: AccessibilityLevel): number {
  if (level === AccessibilityLevel.Normal) return PinMark.Reachable;
  if (level === AccessibilityLevel.None) return PinMark.Unreachable;
  if (level === AccessibilityLevel.Inspect) return PinMark.Inspect;
  return PinMark.SequenceBreak;
}

/** The pin's states, `null` when nothing of the location is visible. */
export function locationMarks(location: LoadedLocation, view: TrackerView, settings: TrackerSettings): number | null {
  let marks = 0;
  let visible = false;
  for (const section of location.sections) {
    const state = sectionStateOf(section, view);
    if (state === undefined || !state.visible) continue;
    visible = true;
    if (state.level === AccessibilityLevel.Cleared) continue;
    marks |= markOf(displayLevel(state.level, settings));
  }
  return visible ? marks : null;
}

/**
 * PopTracker's split pin: the colour of each quarter (top, left, bottom, right, cut along the diagonals) for each
 * combination of states; a value is a `PinMark`, 0 for done.
 */
export const PIN_PARTS: readonly (readonly [number, number, number, number])[] = [
  [0, 0, 0, 0],
  [1, 1, 1, 1],
  [2, 2, 2, 2],
  [2, 2, 1, 1],
  [4, 4, 4, 4],
  [4, 4, 1, 1],
  [2, 2, 4, 4],
  [4, 2, 1, 1],
  [8, 8, 8, 8],
  [8, 8, 1, 1],
  [2, 2, 8, 8],
  [8, 2, 1, 1],
  [4, 4, 8, 8],
  [4, 8, 1, 1],
  [4, 2, 8, 8],
  [4, 2, 1, 8],
];

const MARK_COLORS: Record<number, string> = {
  0: "var(--color-tracker-cleared)",
  [PinMark.Reachable]: "var(--color-tracker-normal)",
  [PinMark.Unreachable]: "var(--color-tracker-none)",
  [PinMark.SequenceBreak]: "var(--color-tracker-sequence)",
  [PinMark.Inspect]: "var(--color-tracker-inspect)",
};

const MARK_LABELS: [number, string][] = [
  [PinMark.Reachable, "faisable"],
  [PinMark.SequenceBreak, "hors logique"],
  [PinMark.Inspect, "à regarder"],
  [PinMark.Unreachable, "pas encore"],
];

/** The background of a pin: one colour, or its quarters cut along the diagonals. */
export function pinBackground(marks: number): string {
  const [top, left, bottom, right] = (PIN_PARTS[marks] ?? PIN_PARTS[0]!).map((mark) => MARK_COLORS[mark] ?? MARK_COLORS[0]!);
  if (top === left && left === bottom && bottom === right) return top ?? "";
  return `conic-gradient(from -45deg, ${top} 0 25%, ${right} 0 50%, ${bottom} 0 75%, ${left} 0 100%)`;
}

/** What the pin says: « Faisable », « En partie : faisable, pas encore », « Fait ». */
export function pinLabel(marks: number): string {
  if (marks === 0) return "Fait";
  const names = MARK_LABELS.filter(([mark]) => (marks & mark) !== 0).map(([, name]) => name);
  if (names.length <= 1) {
    const name = names[0] ?? "";
    return name.charAt(0).toUpperCase() + name.slice(1);
  }
  return `En partie : ${names.join(", ")}`;
}

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

/** Story 54.6: a hint marked by the pack (from the room's hints), as a short label. `None` shows nothing. */
export const HINT_LABELS: Record<Highlight, string | null> = {
  [Highlight.Priority]: "indice : important",
  [Highlight.Unspecified]: "indice",
  [Highlight.NoPriority]: "indice : sans importance",
  [Highlight.Avoid]: "indice : à éviter",
  [Highlight.None]: null,
};

const HINT_ORDER: readonly Highlight[] = [Highlight.Priority, Highlight.Unspecified, Highlight.NoPriority, Highlight.Avoid];

/** The strongest hint among the sections still to do of a location, or `None`. */
export function locationHighlight(location: LoadedLocation, view: TrackerView): Highlight {
  let best: Highlight = Highlight.None;
  for (const section of location.sections) {
    const state = sectionStateOf(section, view);
    if (state === undefined || !state.visible || state.level === AccessibilityLevel.Cleared) continue;
    const rank = HINT_ORDER.indexOf(state.highlight);
    if (rank !== -1 && (best === Highlight.None || rank < HINT_ORDER.indexOf(best))) best = state.highlight;
  }
  return best;
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

/**
 * Story 54.9: what a pin counts as in the legend's counters. One state gives its own category, several give « mixed »
 * (the split pin), none left to do gives « done ».
 */
export type PinCategory = "reachable" | "sequence" | "inspect" | "unreachable" | "mixed" | "done";

export const PIN_CATEGORIES: readonly PinCategory[] = ["reachable", "sequence", "inspect", "mixed", "unreachable", "done"];

export function pinCategory(marks: number): PinCategory {
  switch (marks) {
    case 0:
      return "done";
    case PinMark.Reachable:
      return "reachable";
    case PinMark.SequenceBreak:
      return "sequence";
    case PinMark.Inspect:
      return "inspect";
    case PinMark.Unreachable:
      return "unreachable";
    default:
      return "mixed";
  }
}

/** The pins of a map, by category, as the map draws them (hidden ones left out). */
export function countPins(
  locations: readonly LoadedLocation[],
  map: string,
  view: TrackerView,
  settings: TrackerSettings,
): Record<PinCategory, number> {
  const counts: Record<PinCategory, number> = { reachable: 0, sequence: 0, inspect: 0, unreachable: 0, mixed: 0, done: 0 };
  for (const location of locations) {
    const spots = location.mapLocations.filter((spot) => spot.map === map).length;
    if (spots === 0) continue;
    const marks = locationMarks(location, view, settings);
    if (marks === null) continue;
    if (settings.hideCleared && marks === 0) continue;
    counts[pinCategory(marks)] += spots;
  }
  return counts;
}
