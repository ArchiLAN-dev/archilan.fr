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

/** The PopTracker version the engine stands for: packs compare `PopVersion` as a string. */
export const EMULATED_POPTRACKER_VERSION = "0.34.0";
