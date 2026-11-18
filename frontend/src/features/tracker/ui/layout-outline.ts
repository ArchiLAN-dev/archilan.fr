import { DEFAULT_ITEM_SIZE, isLayoutNode, readChildren, readItemSize, readText, type LayoutNode, type Size } from "./layout-attributes";

/**
 * Story 54.9: what the site takes out of a pack's layout to lay it out its own way. The pack's tree of docks,
 * containers and tabs is walked once: its maps go to the map area (one at a time, named by the tabs that led to them),
 * its blocks of items go to the side panel as sections, in the pack's order, titled by the pack's group headers or
 * tab titles.
 */
export type OutlineMap = {
  /** The pack map (`maps.json` name); for a page, a key of its own. */
  name: string;
  /** The tab titles from the root down to the map; empty when no tab led to it. */
  path: string[];
  /**
   * A page rather than a map: a tab beside the maps too big for the side panel (a pack's settings, with tabs of their
   * own or wide labels). The map area draws it in place of a map.
   */
  page?: LayoutNode;
};

export type OutlineSection = { title: string | null; nodes: LayoutNode[] };

export type LayoutOutline = { maps: OutlineMap[]; sections: OutlineSection[] };

/** Layouts reference each other by name; a pack that loops is cut after this many levels. */
const MAX_DEPTH = 32;

/** The widgets that show items: what makes a block worth a place in the side panel. */
const ITEM_TYPES = new Set(["item", "itemgrid", "canvas"]);

/** The room inside the side panel (about 20rem less its padding): a block of items wider than this is a page. */
const PANEL_WIDTH = 300;

type Layouts = Readonly<Record<string, unknown>>;

type Walk = { layouts: Layouts; allMaps: readonly string[]; maps: OutlineMap[]; sections: OutlineSection[]; seenMaps: Set<string> };

/** The node a `layout` reference points at, followed through references; the node itself otherwise. */
function resolve(node: LayoutNode, layouts: Layouts, depth = 0): LayoutNode | null {
  if (node.type !== "layout") return node;
  if (depth > MAX_DEPTH) return null;
  const key = readText(node, "key");
  const target = key === null ? undefined : layouts[key];
  return isLayoutNode(target) ? resolve(target, layouts, depth + 1) : null;
}

type Tab = { title: string | null; content: LayoutNode };

function readTabs(node: LayoutNode): Tab[] {
  if (!Array.isArray(node.tabs)) return [];
  return node.tabs.filter(isLayoutNode).flatMap((tab) =>
    isLayoutNode(tab.content) ? [{ title: readText(tab, "title"), content: tab.content }] : [],
  );
}

/** The direct sub-widgets of a node, tabs included, references followed. */
function subNodes(node: LayoutNode, layouts: Layouts): LayoutNode[] {
  const children = node.type === "tabbed" ? readTabs(node).map((tab) => tab.content) : readChildren(node);
  return children.flatMap((child) => {
    const resolved = resolve(child, layouts);
    return resolved === null ? [] : [resolved];
  });
}

function contains(node: LayoutNode, layouts: Layouts, test: (type: string) => boolean, depth = 0): boolean {
  if (depth > MAX_DEPTH) return false;
  const type = typeof node.type === "string" ? node.type : "";
  if (test(type)) return true;
  return subNodes(node, layouts).some((child) => contains(child, layouts, test, depth + 1));
}

/**
 * Whether a tab beside the maps is better as a page of the map area than as a section of the side panel: it has tabs
 * of its own, or a row of items wider than the panel.
 */
function isPage(node: LayoutNode, layouts: Layouts, itemSize: Size = DEFAULT_ITEM_SIZE, depth = 0): boolean {
  if (depth > MAX_DEPTH) return false;
  if (node.type === "tabbed") return true;
  const size = readItemSize(node, itemSize);
  if (node.type === "itemgrid" && Array.isArray(node.rows)) {
    const widest = Math.max(0, ...node.rows.map((row) => (Array.isArray(row) ? row.length : 0)));
    if (widest * size.width > PANEL_WIDTH) return true;
  }
  return subNodes(node, layouts).some((child) => isPage(child, layouts, size, depth + 1));
}

/** The maps a `map` widget shows; without `maps`, every map of the pack, as PopTracker does (story 54.11). */
export function mapNames(node: LayoutNode, allMaps: readonly string[]): string[] {
  if (!Array.isArray(node.maps)) return [...allMaps];
  return node.maps.filter((name): name is string => typeof name === "string" && name !== "");
}

function walk(node: LayoutNode, path: string[], tabTitle: string | null, state: Walk, depth: number): void {
  if (depth > MAX_DEPTH) return;
  const resolved = resolve(node, state.layouts);
  if (resolved === null) return;

  if (!contains(resolved, state.layouts, (type) => type === "map")) {
    if (!contains(resolved, state.layouts, (type) => ITEM_TYPES.has(type))) return;
    // A block of items: the group's header names it (its children are drawn without repeating it), or the tab it
    // sits in does.
    const header = resolved.type === "group" ? readText(resolved, "header") : null;
    if (header !== null) {
      state.sections.push({ title: header, nodes: readChildren(resolved) });
      return;
    }
    // A column of titled groups (the usual item dock) gives one section per group, not one untitled block.
    const children = resolved.type === "tabbed" ? [] : subNodes(resolved, state.layouts);
    if (children.some((child) => child.type === "group" && readText(child, "header") !== null)) {
      for (const child of children) walk(child, path, null, state, depth + 1);
      return;
    }
    state.sections.push({ title: tabTitle, nodes: [resolved] });
    return;
  }

  if (resolved.type === "map") {
    for (const name of mapNames(resolved, state.allMaps)) {
      if (state.seenMaps.has(name)) continue;
      state.seenMaps.add(name);
      state.maps.push({ name, path });
    }
    return;
  }

  if (resolved.type === "tabbed") {
    for (const tab of readTabs(resolved)) {
      const title = tab.title ?? null;
      const tabPath = title === null ? path : [...path, title];
      const content = resolve(tab.content, state.layouts);
      if (
        content !== null &&
        !contains(content, state.layouts, (type) => type === "map") &&
        contains(content, state.layouts, (type) => ITEM_TYPES.has(type)) &&
        isPage(content, state.layouts)
      ) {
        state.maps.push({ name: `page:${tabPath.join("/")}:${state.maps.length}`, path: tabPath, page: content });
        continue;
      }
      walk(tab.content, tabPath, title, state, depth + 1);
    }
    return;
  }

  for (const child of subNodes(resolved, state.layouts)) walk(child, path, null, state, depth + 1);
}

export function outlineLayout(root: LayoutNode, layouts: Layouts, allMaps: readonly string[] = []): LayoutOutline {
  const state: Walk = { layouts, allMaps, maps: [], sections: [], seenMaps: new Set() };
  walk(root, [], null, state, 0);
  return { maps: state.maps, sections: state.sections };
}

/** The name a map goes by in the map area: the tab that led to it, or its pack name. */
export function mapLabel(map: OutlineMap): string {
  return map.path.at(-1) ?? map.name;
}

/** The full way to a map, for a list of many: « Dungeons › Main › Woodfall Temple ». */
export function mapPathLabel(map: OutlineMap): string {
  return map.path.length === 0 ? map.name : map.path.join(" › ");
}

export type GroupedMessage = { message: string; count: number };

/** Story 54.9: a pack that fails on every frame repeats itself; each message once, with how often it came. */
export function groupMessages(messages: readonly { message: string }[]): GroupedMessage[] {
  const counts = new Map<string, number>();
  for (const { message } of messages) counts.set(message, (counts.get(message) ?? 0) + 1);
  return [...counts].map(([message, count]) => ({ message, count }));
}
