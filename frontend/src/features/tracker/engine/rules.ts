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

/** No rule at all means always accessible. */
export function evaluateRules(entries: readonly RuleEntry[], context: RuleContext): AccessibilityLevel {
  if (entries.length === 0) return AccessibilityLevel.Normal;
  let best: AccessibilityLevel = AccessibilityLevel.None;
  for (const entry of entries) {
    const level = evaluateEntry(entry, context);
    if (level > best) best = level;
    if (best === AccessibilityLevel.Normal) break;
  }
  return best;
}

function evaluateEntry(entry: RuleEntry, context: RuleContext): AccessibilityLevel {
  let level: AccessibilityLevel = AccessibilityLevel.Normal;
  let hasInspect = false;
  for (const token of entry) {
    if (token.inspect) hasInspect = true;
    let tokenLevel = evaluateToken(token, context);
    if (token.optional && tokenLevel < AccessibilityLevel.SequenceBreak) tokenLevel = AccessibilityLevel.SequenceBreak;
    if (tokenLevel < level) level = tokenLevel;
    if (level === AccessibilityLevel.None) return level;
  }
  if (hasInspect && level > AccessibilityLevel.Inspect) return AccessibilityLevel.Inspect;
  return level;
}

function evaluateToken(token: RuleToken, context: RuleContext): AccessibilityLevel {
  const { rule } = token;
  if (rule === "") return AccessibilityLevel.Normal;
  if (rule.startsWith("@")) {
    const level = context.referenceLevel(rule.slice(1));
    return level === AccessibilityLevel.Cleared ? AccessibilityLevel.Normal : level;
  }
  if (rule.startsWith("^$")) {
    const value = callRule(rule.slice(2), context);
    if (value === null || value === false) return AccessibilityLevel.None;
    if (value === true) return AccessibilityLevel.Normal;
    return toAccessibilityLevel(value);
  }
  if (rule.startsWith("$")) {
    const value = callRule(rule.slice(1), context);
    const count = value === true ? 1 : typeof value === "number" ? value : 0;
    return count >= token.count ? AccessibilityLevel.Normal : AccessibilityLevel.None;
  }
  return context.providerCount(rule) >= token.count ? AccessibilityLevel.Normal : AccessibilityLevel.None;
}

function callRule(call: string, context: RuleContext): number | boolean | null {
  const [name, ...args] = call.split("|");
  return context.callFunction(name, args);
}
