import { AccessibilityLevel } from "./levels";
import { evaluateRules, parseRules, type RuleContext, type RuleOptions } from "./rules";

const { None, Inspect, SequenceBreak, Normal, Cleared, Partial } = AccessibilityLevel;

function context(
  items: Record<string, number>,
  functions: Record<string, (...args: string[]) => number | boolean | null> = {},
  references: Record<string, AccessibilityLevel> = {},
  visible: Record<string, boolean> = {},
): RuleContext {
  return {
    providerCount: (code) => items[code] ?? 0,
    callFunction: (name, args) => functions[name]?.(...args) ?? null,
    referenceLevel: (path) => references[path] ?? None,
    referenceVisible: (path) => visible[path] ?? false,
  };
}

function level(rules: unknown, ctx: RuleContext, options: RuleOptions = {}): AccessibilityLevel {
  return evaluateRules(parseRules(rules), ctx, options);
}

describe("parseRules", () => {
  it("splits an entry on commas and trims", () => {
    expect(parseRules(["a, b ,c"])[0].map((token) => token.rule)).toEqual(["a", "b", "c"]);
  });

  it("keeps commas inside array entries", () => {
    expect(parseRules([["$f|a,b", "c"]])[0].map((token) => token.rule)).toEqual(["$f|a,b", "c"]);
  });

  it("reads a single string as one entry", () => {
    expect(parseRules("a,b")).toHaveLength(1);
  });

  it("reads counts on codes and functions, not on @ and ^ rules", () => {
    expect(parseRules(["bombs:2"])[0][0]).toMatchObject({ rule: "bombs", count: 2 });
    expect(parseRules(["$f|x:3"])[0][0]).toMatchObject({ rule: "$f|x", count: 3 });
    expect(parseRules(["@A/B:2"])[0][0]).toMatchObject({ rule: "@A/B:2", count: 1 });
  });

  it("carries brackets over several tokens", () => {
    const [entry] = parseRules(["a, [b, c], d"]);
    expect(entry.map((token) => token.optional)).toEqual([false, true, true, false]);
    const [inspect] = parseRules(["{a, b}"]);
    expect(inspect.map((token) => token.inspect)).toEqual([true, true]);
  });

  it("ignores what is not a rule", () => {
    expect(parseRules(undefined)).toEqual([]);
    expect(parseRules([1, null])).toEqual([]);
  });
});

describe("evaluateRules", () => {
  it("is open without rules or with an empty rule", () => {
    expect(level(undefined, context({}))).toBe(Normal);
    expect(level([""], context({}))).toBe(Normal);
  });

  it("ANDs the tokens of an entry and ORs the entries", () => {
    const ctx = context({ a: 1, c: 1 });
    expect(level(["a,b"], ctx)).toBe(None);
    expect(level(["a,b", "c"], ctx)).toBe(Normal);
  });

  it("checks item counts", () => {
    expect(level(["bombs:2"], context({ bombs: 1 }))).toBe(None);
    expect(level(["bombs:2"], context({ bombs: 2 }))).toBe(Normal);
  });

  it("calls Lua functions with their arguments, boolean or count", () => {
    const calls: string[][] = [];
    const ctx = context({}, {
      has: (...args) => {
        calls.push(args);
        return args[0] === "yes";
      },
      count: () => 2,
      missing: () => null,
    });
    expect(level(["$has|yes|1"], ctx)).toBe(Normal);
    expect(calls).toEqual([["yes", "1"]]);
    expect(level(["$has|no"], ctx)).toBe(None);
    expect(level(["$count:2"], ctx)).toBe(Normal);
    expect(level(["$count:3"], ctx)).toBe(None);
    expect(level(["$missing"], ctx)).toBe(None);
  });

  it("reads ^$ functions as accessibility levels", () => {
    const ctx = context({}, { seq: () => SequenceBreak, yes: () => true, no: () => false });
    expect(level(["^$seq"], ctx)).toBe(SequenceBreak);
    expect(level(["^$yes"], ctx)).toBe(Normal);
    expect(level(["^$no"], ctx)).toBe(None);
  });

  it("follows @ references, a cleared one counting as open", () => {
    const ctx = context({}, {}, { "A/B": Inspect, "A/C": Cleared });
    // Story 54.11, as PopTracker: a target only inspectable does not hold back a rule outside {} (ignored there),
    // and makes an inspect rule inspectable.
    expect(level(["@A/B"], ctx)).toBe(Normal);
    expect(level(["{@A/B}"], ctx)).toBe(Inspect);
    expect(level(["@A/C"], ctx)).toBe(Normal);
    expect(level(["@A/D"], ctx)).toBe(None);
  });

  it("downgrades a missing optional rule to sequence break", () => {
    expect(level(["a, [b]"], context({ a: 1 }))).toBe(SequenceBreak);
    expect(level(["a, [b]"], context({ a: 1, b: 1 }))).toBe(Normal);
    expect(level(["[b], c"], context({}))).toBe(None);
  });

  it("caps an inspect entry at Inspect", () => {
    expect(level(["{a}"], context({ a: 1 }))).toBe(Inspect);
    expect(level(["x", "{a}"], context({ a: 1, x: 1 }))).toBe(Normal);
    expect(level(["{a}"], context({}))).toBe(None);
  });

  it("keeps the best entry", () => {
    expect(level(["[a]", "b"], context({}))).toBe(SequenceBreak);
    // Story 54.11, as PopTracker: a partial target is not « none », the rule holds.
    expect(level(["@P"], context({}, {}, { P: Partial }))).toBe(Normal);
  });

  it("combines sequence break and inspect across entries as PopTracker (story 54.11)", () => {
    expect(level(["[a]", "{b}"], context({ b: 1 }))).toBe(Inspect);
    expect(level(["[a]", "{b}"], context({ b: 1 }), { inspectableSequenceBreak: true })).toBe(SequenceBreak);
    expect(level(["{[a]}"], context({}))).toBe(Inspect);
  });

  it("asks an @ in visibility rules for the target's visibility (story 54.11)", () => {
    const ctx = context({}, {}, { Shown: None }, { Shown: true });
    expect(level(["@Shown"], ctx, { visibility: true })).toBe(Normal);
    expect(level(["@Hidden"], ctx, { visibility: true })).toBe(None);
    expect(level(["@Shown"], ctx)).toBe(None);
  });
});
