/**
 * Accessibility levels and highlights of the PopTracker pack interface (story 54.4). The numbers order the levels
 * from worst to best, so that combining rules is a min (AND) or a max (OR).
 */
export const AccessibilityLevel = {
  None: 0,
  Partial: 1,
  Inspect: 3,
  SequenceBreak: 5,
  Normal: 6,
  Cleared: 7,
} as const;

export type AccessibilityLevel = (typeof AccessibilityLevel)[keyof typeof AccessibilityLevel];

export const Highlight = {
  Avoid: -1,
  None: 0,
  NoPriority: 1,
  Unspecified: 2,
  Priority: 3,
} as const;

export type Highlight = (typeof Highlight)[keyof typeof Highlight];

const LEVELS: readonly number[] = Object.values(AccessibilityLevel);

export function isAccessibilityLevel(value: unknown): value is AccessibilityLevel {
  return typeof value === "number" && LEVELS.includes(value);
}

/** The level a number returned by a pack stands for: an exact level, else the closest one below it. */
export function toAccessibilityLevel(value: number): AccessibilityLevel {
  if (isAccessibilityLevel(value)) return value;
  let best: AccessibilityLevel = AccessibilityLevel.None;
  for (const level of Object.values(AccessibilityLevel)) {
    if (level <= value && level > best) best = level;
  }
  return best;
}

/**
 * The PopTracker version the engine stands for: packs compare `PopVersion` as a string. Story 54.11: 0.35.4, the
 * last version whose additions the engine covers whole (0.36.0 adds pin shapes not drawn yet), so a pack that tests
 * for 0.36 falls back on what an older PopTracker draws.
 */
export const EMULATED_POPTRACKER_VERSION = "0.35.4";

/** Story 54.11: compares two dotted versions (`0.25.9` < `0.26.0`), numerically part by part. */
export function compareVersions(a: string, b: string): number {
  const left = a.split(".").map((part) => Number.parseInt(part, 10) || 0);
  const right = b.split(".").map((part) => Number.parseInt(part, 10) || 0);
  for (let index = 0; index < Math.max(left.length, right.length); index += 1) {
    const diff = (left[index] ?? 0) - (right[index] ?? 0);
    if (diff !== 0) return diff;
  }
  return 0;
}
