import * as yaml from "js-yaml";

import {
  STANDARD_KEYS,
  createRangeEntry,
  dumpFreeformValue,
  isValidSlotName,
  labelFromAlias,
  parseItemLinkEntries,
  parsePlandoEntries,
  SLOT_NAME_MAX_LENGTH,
  type GameOption,
  type ParsedYaml,
} from "./archipelago-yaml";

/**
 * Story 9.57: importing a YAML written elsewhere into the option editor.
 *
 * The editor only shows the options of the game's default YAML, in the weighted shape Archipelago
 * writes in its templates. A hand-written YAML rarely looks like that: `Goal: hollowknight`,
 * `StartingGeo: 300`, `RandomizeSkills: true`. Merging it as-is through `mergePlayerValues` would
 * read those scalars as text and drop them silently, since the types differ. So every value is
 * checked against the option it lands on, normalized to that option's model, and anything the
 * editor cannot represent is reported instead of vanishing.
 *
 * The check is a guard, not a guarantee: a misspelt item name in `start_hints` or an impossible
 * combination of options passes it. Only a test generation proves a YAML works.
 */

/** A player YAML is a few kilobytes; same bound as the admin candidate test (story 38.14). */
export const IMPORT_MAX_BYTES = 100 * 1024;

export type ImportIssue = { path: string; message: string };

export type ImportCheck = {
  errors: ImportIssue[];
  warnings: ImportIssue[];
  /** The editor content once the import is applied; null while there is an error. */
  result: ParsedYaml | null;
};

type Options = {
  /** List items checked against the static location list when it is known (story 4.14). */
  locationNames?: readonly string[] | null;
};

const LOCATION_KEYS = new Set(["priority_locations", "exclude_locations", "start_location_hints"]);
const TRUE_WORDS = new Set(["true", "on", "yes"]);
const FALSE_WORDS = new Set(["false", "off", "no"]);

type Outcome = { option: GameOption } | { error: string };

function isMapping(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function isScalar(value: unknown): value is string | number | boolean {
  return typeof value === "string" || typeof value === "number" || typeof value === "boolean";
}

function clampWeight(weight: number): number {
  return Math.max(0, Math.min(100, Math.round(weight)));
}

/**
 * Reads a weighted mapping (`value: weight`). A scalar is the same thing with a single value, which
 * is how Archipelago reads `Goal: hollowknight`.
 */
function readWeights(value: unknown): Map<string, number> | string {
  if (isScalar(value)) return new Map([[String(value), 50]]);
  if (!isMapping(value)) return "une valeur ou des pondérations « valeur: poids » sont attendues";

  const weights = new Map<string, number>();
  for (const [key, weight] of Object.entries(value)) {
    if (typeof weight !== "number" || !Number.isFinite(weight) || weight < 0) {
      return `le poids de « ${key} » doit être un nombre positif`;
    }
    weights.set(key, clampWeight(weight));
  }
  if (weights.size === 0) return "aucune valeur";
  if ([...weights.values()].every((w) => w === 0)) return "tous les poids sont à 0, la génération échouerait";
  return weights;
}

function toggleValue(raw: string): boolean | null {
  const word = raw.toLowerCase();
  if (TRUE_WORDS.has(word) || word === "1") return true;
  if (FALSE_WORDS.has(word) || word === "0") return false;
  return null;
}

function applyValue(option: GameOption, value: unknown): Outcome {
  switch (option.type) {
    case "text":
      return isScalar(value) ? { option: { ...option, value: String(value) } } : { error: "une valeur simple est attendue" };

    case "toggle": {
      const weights = readWeights(value);
      if (typeof weights === "string") return { error: weights };
      let weightFalse = 0;
      let weightTrue = 0;
      for (const [key, weight] of weights) {
        const side = toggleValue(key);
        if (side === null) return { error: `« ${key} » n'est pas une valeur d'interrupteur (true ou false)` };
        if (side) weightTrue += weight;
        else weightFalse += weight;
      }
      return { option: { ...option, weightFalse: clampWeight(weightFalse), weightTrue: clampWeight(weightTrue) } };
    }

    case "choice": {
      const weights = readWeights(value);
      if (typeof weights === "string") return { error: weights };
      const canonical = new Map(option.choices.map((c) => [c.value.toLowerCase(), c.value]));
      const chosen = new Map<string, number>();
      for (const [key, weight] of weights) {
        const match = canonical.get(key.toLowerCase());
        if (match === undefined) {
          return { error: `« ${key} » n'est pas un choix possible (${option.choices.map((c) => c.value).join(", ")})` };
        }
        chosen.set(match, (chosen.get(match) ?? 0) + weight);
      }
      return { option: { ...option, choices: option.choices.map((c) => ({ ...c, weight: clampWeight(chosen.get(c.value) ?? 0) })) } };
    }

    case "range": {
      const weights = readWeights(value);
      if (typeof weights === "string") return { error: weights };
      const named = new Set(option.entries.filter((e) => isNaN(Number(e.key))).map((e) => e.key));
      const byKey = new Map(option.entries.map((e) => [e.key, e]));
      const entries = [];
      for (const [rawKey, weight] of weights) {
        const key = rawKey.trim();
        if (key !== "" && !isNaN(Number(key))) {
          const n = Number(key);
          if (!Number.isInteger(n)) return { error: `${key} n'est pas un nombre entier` };
          if (option.max > option.min && (n < option.min || n > option.max)) {
            return { error: `${key} est hors limites (${option.min} à ${option.max})` };
          }
        } else if (!named.has(key) && labelFromAlias(key) === null) {
          return { error: `« ${key} » n'est ni un nombre ni une valeur nommée de cette option` };
        }
        const known = byKey.get(key);
        entries.push(known ? { ...known, weight } : createRangeEntry(key, weight, true));
      }
      return { option: { ...option, entries } };
    }

    case "freeform":
      if (option.kind === "list") {
        if (!Array.isArray(value)) return { error: "une liste est attendue" };
        return { option: { ...option, items: value.map((item) => (typeof item === "string" ? item : dumpFreeformValue(item))) } };
      }
      if (!isMapping(value)) return { error: "un dictionnaire « clé: valeur » est attendu" };
      if (option.fixedKeys) {
        return {
          option: {
            ...option,
            entries: option.entries.map((e) => (e.k in value ? { ...e, v: dumpFreeformValue(value[e.k]) } : e)),
          },
        };
      }
      return {
        option: {
          ...option,
          entries: Object.entries(value).map(([k, v], i) => ({ id: `import-${option.key}-${i}`, k, v: dumpFreeformValue(v) })),
        },
      };

    case "plando_items":
      return Array.isArray(value) ? { option: { ...option, entries: parsePlandoEntries(value) } } : { error: "une liste est attendue" };

    case "item_links":
      return Array.isArray(value) ? { option: { ...option, entries: parseItemLinkEntries(value) } } : { error: "une liste est attendue" };
  }
}

/** The YAML's `game` must name this game, and only this one: the editor configures a single game. */
function gameError(game: unknown, gameName: string): string | null {
  if (game === undefined || game === null) return "Le champ « game » est absent.";
  if (isScalar(game)) {
    return String(game) === gameName ? null : `Ce YAML est pour « ${String(game)} », pas pour « ${gameName} ».`;
  }
  if (!isMapping(game)) return "Le champ « game » doit être le nom du jeu.";
  const own = game[gameName];
  if (typeof own !== "number" || own <= 0) return `Ce YAML ne choisit pas « ${gameName} ».`;
  const others = Object.entries(game).filter(([name, weight]) => name !== gameName && typeof weight === "number" && weight > 0);
  if (others.length > 0) return `Ce YAML tire au sort entre plusieurs jeux (${others.map(([name]) => name).join(", ")}) : l'éditeur n'en configure qu'un.`;
  return null;
}

function readDocument(text: string): { doc: Record<string, unknown> } | { error: string } {
  if (text.trim() === "") return { error: "Le fichier est vide." };
  if (new TextEncoder().encode(text).length > IMPORT_MAX_BYTES) return { error: "Le fichier dépasse 100 Ko." };

  let docs: unknown[];
  try {
    docs = yaml.loadAll(text, null, { schema: yaml.CORE_SCHEMA }).filter((d) => d !== null && d !== undefined);
  } catch (e) {
    const reason = e instanceof yaml.YAMLException ? e.reason : "syntaxe invalide";
    const line = e instanceof yaml.YAMLException && e.mark !== undefined ? ` (ligne ${e.mark.line + 1})` : "";
    return { error: `YAML illisible : ${reason}${line}.` };
  }
  if (docs.length === 0) return { error: "Le fichier est vide." };
  if (docs.length > 1) return { error: "Le fichier contient plusieurs YAML (séparés par ---) : importe-les un par un." };
  const [doc] = docs;
  if (!isMapping(doc)) return { error: "Le YAML doit être un dictionnaire (name, game, puis la section du jeu)." };
  return { doc };
}

/**
 * Checks `text` against the game's default YAML (`base`, already parsed with the introspected option
 * types) and returns it applied over those defaults: an option the file does not set keeps its
 * default. Errors block the import; warnings name what is dropped.
 */
export function checkImportedYaml(text: string, base: ParsedYaml, options: Options = {}): ImportCheck {
  const errors: ImportIssue[] = [];
  const warnings: ImportIssue[] = [];

  const read = readDocument(text);
  if ("error" in read) return { errors: [{ path: "", message: read.error }], warnings, result: null };
  const { doc } = read;
  const gameName = base.gameName;

  const wrongGame = gameError(doc["game"], gameName);
  if (wrongGame !== null) errors.push({ path: "game", message: wrongGame });

  let playerName = base.playerName;
  if (doc["name"] !== undefined) {
    const name = isScalar(doc["name"]) ? String(doc["name"]).trim() : "";
    if (isValidSlotName(name)) playerName = name;
    else errors.push({ path: "name", message: `Nom invalide : lettres, chiffres, _ et {number}/{player} uniquement (${SLOT_NAME_MAX_LENGTH} caractères max).` });
  }

  const section = doc[gameName];
  if (!isMapping(section)) {
    errors.push({ path: gameName, message: `La section « ${gameName}: » des options du jeu est absente.` });
    return { errors, warnings, result: null };
  }

  for (const key of Object.keys(doc)) {
    if (key !== gameName && !STANDARD_KEYS.has(key)) {
      warnings.push({ path: key, message: "Section inconnue, ignorée." });
    }
  }

  const byKey = new Map(base.options.map((o) => [o.key, o]));
  // Archipelago reads the common options (start_hints...) at the top level as well as in the game section.
  const sources: [string, unknown][] = [
    ...Object.entries(doc).filter(([k]) => STANDARD_KEYS.has(k) && k !== "name" && k !== "game" && k !== "description" && k !== "requires" && !(k in section)),
    ...Object.entries(section),
  ];

  const applied = new Map<string, GameOption>();
  for (const [key, value] of sources) {
    const path = key in section ? `${gameName}.${key}` : key;
    const option = byKey.get(key);
    if (option === undefined) {
      warnings.push({ path, message: "Option inconnue de ce jeu, ignorée." });
      continue;
    }
    const outcome = applyValue(option, value);
    if ("error" in outcome) {
      errors.push({ path, message: `${option.label} : ${outcome.error}.` });
      continue;
    }
    applied.set(key, outcome.option);

    const locations = options.locationNames;
    if (LOCATION_KEYS.has(key) && locations && locations.length > 0 && Array.isArray(value)) {
      const known = new Set(locations);
      const unknown = value.filter((v): v is string => typeof v === "string" && !known.has(v));
      if (unknown.length > 0) warnings.push({ path, message: `Lieux inconnus : ${unknown.join(", ")}.` });
    }
  }

  if (errors.length > 0) return { errors, warnings, result: null };
  return {
    errors,
    warnings,
    result: { ...base, playerName, options: base.options.map((o) => applied.get(o.key) ?? o) },
  };
}
