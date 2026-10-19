/**
 * Story 9.58: the item names and item groups of a game's apworld, as the YAML editors suggest them.
 *
 * Like the locations (story 4.14), a static hint: a world may create items from its options, so an unknown name
 * is only ever flagged, never refused.
 */

/** Archipelago gives every world this group, holding all its items; introspection leaves it out of the groups. */
export const EVERYTHING_GROUP = "Everything";

/** The options whose values (or, for a dict, whose keys) are item names. */
export const ITEM_LIST_OPTION_KEYS: ReadonlySet<string> = new Set(["start_hints", "local_items", "non_local_items"]);
export const ITEM_DICT_OPTION_KEYS: ReadonlySet<string> = new Set(["start_inventory", "start_inventory_from_pool"]);

export type ItemCatalog = {
  /** Groups first (a whole family at once is what one usually wants), then the items. */
  suggestions: string[];
  /** What the dropdown shows beside a group. */
  annotations: Record<string, string>;
  known: ReadonlySet<string>;
};

/** Validates an API `itemNames` payload (unknown) into a string[], or null. */
export function asItemNames(value: unknown): string[] | null {
  if (!Array.isArray(value)) return null;
  const out = value.filter((v): v is string => typeof v === "string" && v !== "");
  return out.length > 0 ? out : null;
}

/** Validates an API `itemGroups` payload (unknown) into group → items, or null. */
export function asItemGroups(value: unknown): Record<string, string[]> | null {
  if (typeof value !== "object" || value === null || Array.isArray(value)) return null;
  const out: Record<string, string[]> = {};
  for (const [name, members] of Object.entries(value)) {
    const items = asItemNames(members);
    if (name !== "" && items !== null) out[name] = items;
  }
  return Object.keys(out).length > 0 ? out : null;
}

function itemsLabel(count: number): string {
  return `groupe, ${count} ${count > 1 ? "items" : "item"}`;
}

/** Null when the game has no introspected items: the item fields then stay plain text, as before. */
export function buildItemCatalog(items: string[] | null | undefined, groups: Record<string, string[]> | null | undefined): ItemCatalog | null {
  if (!items || items.length === 0) return null;

  const groupNames = Object.keys(groups ?? {}).sort((a, b) => a.localeCompare(b));
  const annotations: Record<string, string> = { [EVERYTHING_GROUP]: "groupe, tous les items" };
  for (const name of groupNames) annotations[name] = itemsLabel(groups?.[name]?.length ?? 0);

  const suggestions = [...groupNames, EVERYTHING_GROUP, ...items.filter((item) => !annotations[item])];
  return { suggestions, annotations, known: new Set([...suggestions, ...items]) };
}

/** Whether a typed name is an item or a group of the game. Blank counts as known: there is nothing to flag yet. */
export function isKnownItemName(catalog: ItemCatalog | null, name: string): boolean {
  const trimmed = name.trim();
  return catalog === null || trimmed === "" || catalog.known.has(trimmed);
}
