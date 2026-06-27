"use client";

import { load as loadYaml } from "js-yaml";

// Shared read-only Archipelago YAML options view. Renders the game block of a YAML config as a
// labelled grid, with weighted dicts shown as distribution bars. Used by the weekly-run game page and
// the personal-run participant detail page so both surfaces present configs identically.

export type ApOptionValue =
  | boolean
  | number
  | string
  | ApOptionValue[]
  | { [key: string]: ApOptionValue };

// Top-level YAML keys that are metadata, never part of the game's option block.
const METADATA_KEYS = new Set(["name", "description", "game", "requires"]);

function isWeightedDict(v: unknown): v is Record<string, number> {
  if (typeof v !== "object" || v === null || Array.isArray(v)) return false;
  return Object.values(v).every((w) => typeof w === "number");
}

function isRange(v: unknown): v is [number, number] {
  return Array.isArray(v) && v.length === 2 && typeof v[0] === "number" && typeof v[1] === "number";
}

export function formatOptionName(key: string): string {
  return key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

/** Renders a scalar (or nested list/dict) option value as plain text - never "[object Object]". */
export function formatScalar(value: ApOptionValue): string {
  if (typeof value === "boolean") return value ? "Oui" : "Non";
  if (Array.isArray(value)) return value.map(formatScalar).join(", ");
  if (typeof value === "object" && value !== null) {
    return Object.entries(value)
      .map(([k, v]) => `${formatOptionName(k)}: ${formatScalar(v)}`)
      .join(", ");
  }
  return String(value);
}

/**
 * Resolves the game option block from a parsed YAML doc. Tries the explicit game name, then the doc's
 * own `game:` field, then the first non-metadata object key - so it works whether the caller knows the
 * Archipelago game name or not.
 */
export function parseGameOptions(
  yamlConfig: string,
  gameName?: string,
): Record<string, ApOptionValue> | null {
  let doc: unknown;
  try {
    doc = loadYaml(yamlConfig);
  } catch {
    return null;
  }
  if (typeof doc !== "object" || doc === null) return null;
  const record = doc as Record<string, unknown>;

  const candidates: string[] = [];
  if (gameName !== undefined && gameName !== "") candidates.push(gameName);
  if (typeof record["game"] === "string") candidates.push(record["game"]);
  for (const key of Object.keys(record)) {
    if (!METADATA_KEYS.has(key)) candidates.push(key);
  }

  for (const key of candidates) {
    const raw = record[key];
    if (typeof raw === "object" && raw !== null && !Array.isArray(raw)) {
      return raw as Record<string, ApOptionValue>;
    }
  }
  return null;
}

export function OptionValue({ value }: { value: ApOptionValue }) {
  if (isWeightedDict(value)) {
    const entries = Object.entries(value);
    const total = entries.reduce((s, [, w]) => s + w, 0);
    if (total === 0) return <span className="text-muted-foreground">-</span>;

    return (
      <ul className="mt-1 flex flex-col gap-1.5">
        {entries
          .sort(([, a], [, b]) => b - a)
          .map(([label, weight]) => {
            const pct = Math.round((weight / total) * 100);
            return (
              <li className="flex items-center gap-2" key={label}>
                <div className="h-1.5 w-24 shrink-0 overflow-hidden rounded-full bg-surface-2">
                  <div className="h-full rounded-full bg-accent-text" style={{ width: `${pct}%` }} />
                </div>
                <span className="w-9 shrink-0 text-right text-xs text-muted-foreground">{pct}%</span>
                <span className="text-xs text-foreground">{label}</span>
              </li>
            );
          })}
      </ul>
    );
  }

  if (isRange(value)) {
    return (
      <span className="text-foreground">
        entre <span className="font-mono">{value[0]}</span> et{" "}
        <span className="font-mono">{value[1]}</span>
      </span>
    );
  }

  // Plain list (non-range array): comma-separated, or "-" when empty.
  if (Array.isArray(value)) {
    if (value.length === 0) return <span className="text-muted-foreground">-</span>;
    return <span className="font-mono text-foreground">{value.map(formatScalar).join(", ")}</span>;
  }

  // Literal dict (e.g. game_options): a non-weighted mapping of named sub-settings. Render each
  // pair instead of String(value), which previously produced "[object Object]".
  if (typeof value === "object" && value !== null) {
    const entries = Object.entries(value);
    if (entries.length === 0) return <span className="text-muted-foreground">-</span>;
    return (
      <ul className="mt-1 flex flex-col gap-1">
        {entries.map(([k, v]) => (
          <li className="flex flex-wrap items-baseline gap-x-2 text-xs" key={k}>
            <span className="text-muted-foreground">{formatOptionName(k)}</span>
            <span className="font-mono text-foreground">{formatScalar(v)}</span>
          </li>
        ))}
      </ul>
    );
  }

  if (typeof value === "boolean") {
    return (
      <span className={value ? "text-emerald-400" : "text-muted-foreground"}>
        {value ? "Oui" : "Non"}
      </span>
    );
  }

  return <span className="font-mono text-foreground">{String(value)}</span>;
}

/** The labelled options grid for a game block. Returns null when the YAML has no parseable options. */
export function YamlOptionsView({
  yamlConfig,
  gameName,
}: {
  yamlConfig: string;
  gameName?: string;
}) {
  const gameOptions = parseGameOptions(yamlConfig, gameName);
  if (gameOptions === null || Object.keys(gameOptions).length === 0) return null;

  return (
    <dl className="grid gap-4 sm:grid-cols-2">
      {Object.entries(gameOptions).map(([key, value]) => (
        <div key={key}>
          <dt className="text-xs font-semibold text-muted-foreground">{formatOptionName(key)}</dt>
          <dd className="mt-0.5 text-sm">
            <OptionValue value={value} />
          </dd>
        </div>
      ))}
    </dl>
  );
}
