import { AccessibilityLevel, toAccessibilityLevel } from "./levels";

/**
 * Access and visibility rules of the PopTracker spec (story 54.4), `doc/PACKS.md` section "Locations".
 *
 * A rule list is an OR of entries; an entry is an AND of tokens, written `"a,b"` or `["a", "b"]`. Tokens:
 * - `code` or `code:n`: an item code, provided at least n times (1 by default);
 * - `$f|a|b` or `$f|a|b:n`: the Lua function `f("a", "b")`, a boolean or a count;
 * - `^$f|a`: the Lua function returns an accessibility level;
 * - `@Location/Section` or `@Location`: the accessibility of another section or location;
 * - `[...]`: optional, the section is then reachable by sequence break;
 * - `{...}`: the section can be inspected (seen, not collected).
 */
export type RuleToken = {
  /** The token without brackets and without the count suffix. */
  rule: string;
  count: number;
  optional: boolean;
  inspect: boolean;
};

export type RuleEntry = RuleToken[];

export type RuleContext = {
  providerCount(code: string): number;
  /** The value a Lua function returns: null when it is missing or failed (counts as false). */
  callFunction(name: string, args: string[]): number | boolean | null;
  /** The accessibility of a section or location by its path, without the leading `@`. */
  referenceLevel(path: string): AccessibilityLevel;
  /** Story 54.11: whether a section or location is visible, for an `@` in visibility rules. */
  referenceVisible(path: string): boolean;
};

/** A single string is one entry with one token list (`"a,b"` = `[["a,b"]]`), as in PopTracker since 0.25.6. */
export function parseRules(raw: unknown): RuleEntry[] {
  if (typeof raw === "string") return [parseEntry(raw)];
  if (!Array.isArray(raw)) return [];
  const entries: RuleEntry[] = [];
  for (const entry of raw) {
    if (typeof entry === "string") entries.push(parseEntry(entry));
    else if (Array.isArray(entry)) entries.push(tokensOf(entry.filter((part): part is string => typeof part === "string")));
  }
  return entries;
}

function parseEntry(entry: string): RuleEntry {
  return tokensOf(entry.split(","));
}

/** Brackets may span several tokens: `"[a, b]"` makes both optional. */
function tokensOf(parts: string[]): RuleEntry {
  const tokens: RuleEntry = [];
  let optional = false;
  let inspect = false;
  for (const part of parts) {
    let text = part.trim();
    if (text.startsWith("{")) {
      inspect = true;
      text = text.slice(1).trim();
    }
    if (text.startsWith("[")) {
      optional = true;
      text = text.slice(1).trim();
    }
    let closeOptional = false;
    let closeInspect = false;
    if (text.endsWith("}")) {
      closeInspect = true;
      text = text.slice(0, -1).trim();
    }
    if (text.endsWith("]")) {
      closeOptional = true;
      text = text.slice(0, -1).trim();
    }
    if (text.endsWith("}")) {
      closeInspect = true;
      text = text.slice(0, -1).trim();
    }
    const { rule, count } = splitCount(text);
    tokens.push({ rule, count, optional, inspect });
    if (closeOptional) optional = false;
    if (closeInspect) inspect = false;
  }
  return tokens;
}

/** `code:3` gives 3; `@` and `^` rules keep their text as is (the count has no effect on them). */
function splitCount(text: string): { rule: string; count: number } {
  if (text.startsWith("@") || text.startsWith("^")) return { rule: text, count: 1 };
  const match = /^(.*):(\d+)$/.exec(text);
  if (match === null) return { rule: text, count: 1 };
  return { rule: match[1], count: Number(match[2]) };
}

export type RuleOptions = {
  /** Visibility rules: an `@` reference asks whether the target is visible, not whether it is accessible. */
  visibility?: boolean;
  /**
   * `inspectable_sequence_break` of the location or section: a rule both inspectable and reachable by sequence
   * break counts as a sequence break, not as inspectable.
   */
  inspectableSequenceBreak?: boolean;
};

/**
 * No rule at all means always accessible. Story 54.11: the levels combine as PopTracker's `Tracker::resolveRules`
 * (src/core/tracker.cpp, v0.36.0), entry by entry, then across entries: an entry fully met wins; otherwise one
 * reachable by sequence break and one inspectable give « inspect » (or « sequence break » with
 * `inspectable_sequence_break`).
 */
export function evaluateRules(entries: readonly RuleEntry[], context: RuleContext, options: RuleOptions = {}): AccessibilityLevel {
  if (entries.length === 0) return AccessibilityLevel.Normal;
  let glitched = false;
  let inspectable = false;
  for (const entry of entries) {
    if (entry.length === 0) return AccessibilityLevel.Normal;
    const { level, inspect } = evaluateEntry(entry, context, options.visibility === true);
    if (level === AccessibilityLevel.Normal && !inspect) return AccessibilityLevel.Normal;
    if (level !== AccessibilityLevel.None && inspect) inspectable = true;
    if (level === AccessibilityLevel.SequenceBreak) glitched = true;
  }
  if (glitched && inspectable && options.inspectableSequenceBreak !== true) return AccessibilityLevel.Inspect;
  if (glitched) return AccessibilityLevel.SequenceBreak;
  return inspectable ? AccessibilityLevel.Inspect : AccessibilityLevel.None;
}

type EntryResult = { level: AccessibilityLevel; inspect: boolean };

/** One entry (AND), as PopTracker: `level` is None, SequenceBreak or Normal; `inspect` once a `{` token was met. */
function evaluateEntry(entry: RuleEntry, context: RuleContext, visibility: boolean): EntryResult {
  let reachable: AccessibilityLevel = AccessibilityLevel.Normal;
  let inspect = false;
  for (const token of entry) {
    if (token.inspect) inspect = true;
    const { rule } = token;
    if (rule === "") continue;
    if (rule.startsWith("@") || rule.startsWith("^$")) {
      let sub = levelOf(rule, context, visibility);
      // A target only inspectable, outside `{}`, does not hold the entry back (PopTracker ignores it); from `^$`, it
      // makes the entry inspectable.
      if (!inspect && sub === AccessibilityLevel.Inspect) {
        if (rule.startsWith("^$")) inspect = true;
      } else if (token.optional && sub === AccessibilityLevel.None) {
        sub = AccessibilityLevel.SequenceBreak;
      } else if (sub === AccessibilityLevel.None) {
        reachable = AccessibilityLevel.None;
      }
      if (sub === AccessibilityLevel.SequenceBreak && reachable !== AccessibilityLevel.None) reachable = AccessibilityLevel.SequenceBreak;
      if (reachable === AccessibilityLevel.None) break;
      continue;
    }
    if (countOf(rule, context) >= token.count) continue;
    if (token.optional) {
      reachable = AccessibilityLevel.SequenceBreak;
    } else {
      reachable = AccessibilityLevel.None;
      break;
    }
  }
  return { level: reachable, inspect };
}

function levelOf(rule: string, context: RuleContext, visibility: boolean): AccessibilityLevel {
  if (rule.startsWith("@")) {
    const path = rule.slice(1);
    if (visibility) return context.referenceVisible(path) ? AccessibilityLevel.Normal : AccessibilityLevel.None;
    const level = context.referenceLevel(path);
    return level === AccessibilityLevel.Cleared ? AccessibilityLevel.Normal : level;
  }
  const value = callRule(rule.slice(2), context);
  if (value === null || value === false) return AccessibilityLevel.None;
  if (value === true) return AccessibilityLevel.Normal;
  const level = toAccessibilityLevel(value);
  return level === AccessibilityLevel.Cleared ? AccessibilityLevel.Normal : level;
}

function countOf(rule: string, context: RuleContext): number {
  if (!rule.startsWith("$")) return context.providerCount(rule);
  const value = callRule(rule.slice(1), context);
  return value === true ? 1 : typeof value === "number" ? value : 0;
}

function callRule(call: string, context: RuleContext): number | boolean | null {
  const [name, ...args] = call.split("|");
  return context.callFunction(name, args);
}
