"use client";

import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";

import { tabClassName } from "@/components/ui/tabs";

import {
  alignmentClass,
  DEFAULT_ITEM_SIZE,
  isLayoutNode,
  marginStyle,
  readChildren,
  readMargin,
  readItemSize,
  readSpacing,
  readTabContent,
  readText,
  type LayoutNode,
  type Size,
  type Spacing,
} from "./layout-attributes";
import { mapNames } from "./layout-outline";
import { useTracker } from "./tracker-context";
import { TrackerItem } from "./tracker-item";
import { TrackerMap } from "./tracker-map";

/**
 * Draws a pack layout (story 54.5, decision 3), one component per PopTracker widget type. Layouts reference each
 * other by name (`layout`); a pack that loops is cut after `MAX_DEPTH` levels.
 */
const MAX_DEPTH = 32;

const DEFAULT_ITEM_MARGIN: Spacing = { x: 2, y: 2 };

/** What a widget passes down: item sizes inherit, and the parent's axis decides which alignment applies. */
type Inherited = { itemSize: Size; itemMargin: Spacing; depth: number; cross: "horizontal" | "vertical" };

export const ROOT: Inherited = { itemSize: DEFAULT_ITEM_SIZE, itemMargin: DEFAULT_ITEM_MARGIN, depth: 0, cross: "horizontal" };

export function TrackerLayout({ name }: { name: string }) {
  const { pack } = useTracker();
  const node = pack.layouts[name];
  if (!isLayoutNode(node)) return null;
  return <LayoutWidget inherited={ROOT} node={node} />;
}

export type Props = { node: LayoutNode; inherited: Inherited };

/** Fills its parent: the widgets that hold maps need the room. */
const GROW = "flex min-h-0 min-w-0 flex-1 flex-col";

export function LayoutWidget({ node, inherited }: Props) {
  if (inherited.depth > MAX_DEPTH) return null;
  const next: Inherited = {
    ...inherited,
    depth: inherited.depth + 1,
    itemSize: readItemSize(node, inherited.itemSize),
    itemMargin: readSpacing(node.item_margin) ?? inherited.itemMargin,
  };
  // Story 54.9: the site's colours, always - a pack's `background` is not drawn.
  const style: CSSProperties = marginStyle(readMargin(node.margin)) ?? {};
  const align = alignmentClass(node, inherited.cross);
  const type = typeof node.type === "string" ? node.type : "";
  const grows = GROWING.has(type);

  return (
    <div className={`${grows ? GROW : "flex min-w-0 flex-col"} ${align}`} data-widget={type} style={style}>
      <WidgetBody inherited={next} node={node} type={type} />
    </div>
  );
}

const GROWING = new Set(["container", "dock", "tabbed", "map", "layout"]);

function WidgetBody({ node, inherited, type }: Props & { type: string }): ReactNode {
  switch (type) {
    case "container":
      return <Children inherited={inherited} nodes={readChildren(node).slice(0, 1)} />;
    case "dock":
      return <Dock inherited={inherited} nodes={readChildren(node)} />;
    case "array":
      return <ArrayWidget inherited={inherited} node={node} />;
    case "tabbed":
      return <Tabbed inherited={inherited} node={node} />;
    case "group":
      return <Group inherited={inherited} node={node} />;
    case "item": {
      const code = readText(node, "item");
      return code === null ? null : <TrackerItem code={code} size={inherited.itemSize} />;
    }
    case "itemgrid":
      return <ItemGrid inherited={inherited} node={node} />;
    case "layout":
      return <LayoutReference inherited={inherited} node={node} />;
    case "map":
      return <MapWidget node={node} />;
    case "canvas":
      return <Canvas inherited={inherited} node={node} />;
    case "text": {
      const text = readText(node, "text");
      return text === null ? null : <p className="text-sm text-foreground">{text}</p>;
    }
    default:
      return <Unsupported type={type === "" ? "(sans type)" : type} />;
  }
}

function Children({ nodes, inherited }: { nodes: LayoutNode[]; inherited: Inherited }) {
  return nodes.map((child, index) => (
    // Layout widgets have no id, and a pack's layout never reorders: the position is the identity.
    <LayoutWidget inherited={inherited} key={index} node={child} />
  ));
}

const DOCK_DIRECTION: Record<string, string> = {
  top: "flex-col",
  bottom: "flex-col-reverse",
  // Below a laptop width (a tablet held upright) the side panels stack above or below the rest.
  left: "flex-col lg:flex-row",
  right: "flex-col-reverse lg:flex-row-reverse",
};

/**
 * A dock lays its children out like WPF's DockPanel: each one takes its edge of the space left, the last one fills
 * what remains. A child without `dock` is on top, unless it is the last.
 */
function Dock({ nodes, inherited }: { nodes: LayoutNode[]; inherited: Inherited }) {
  const [first, ...rest] = nodes;
  if (first === undefined) return null;
  if (rest.length === 0) return <LayoutWidget inherited={{ ...inherited, cross: "horizontal" }} node={first} />;
  const dock = readText(first, "dock");
  const side = dock !== null && dock in DOCK_DIRECTION ? dock : "top";
  const vertical = side === "top" || side === "bottom";
  return (
    <div className={`${GROW} gap-2 ${DOCK_DIRECTION[side] ?? "flex-col"}`}>
      <div
        className={`flex shrink-0 flex-col ${vertical ? "" : "max-h-[45dvh] overflow-auto lg:max-h-none"}`}
        data-dock={side}
      >
        <LayoutWidget inherited={{ ...inherited, cross: vertical ? "horizontal" : "vertical" }} node={first} />
      </div>
      <div className={GROW}>
        <Dock inherited={inherited} nodes={rest} />
      </div>
    </div>
  );
}

function ArrayWidget({ node, inherited }: Props) {
  const horizontal = readText(node, "orientation") === "horizontal";
  return (
    <div className={`flex gap-1 ${horizontal ? "flex-row flex-wrap items-start" : "flex-col"}`}>
      <Children inherited={{ ...inherited, cross: horizontal ? "vertical" : "horizontal" }} nodes={readChildren(node)} />
    </div>
  );
}

function pixels(value: unknown): number | null {
  return typeof value === "number" && Number.isFinite(value) && value >= 0 ? value : null;
}

/**
 * Story 54.8: `canvas` (PopTracker 0.21.1) places its children at `canvas_left`/`canvas_top`, each with its own width
 * and height (Celeste draws its checkpoints this way). An item child takes that size.
 */
function Canvas({ node, inherited }: Props) {
  const width = pixels(node.width);
  const height = pixels(node.height);
  const board = (
    <div className="relative shrink-0" style={{ width: width ?? undefined, height: height ?? undefined }}>
      {readChildren(node).map((child, index) => {
        const childWidth = pixels(child.width);
        const childHeight = pixels(child.height);
        const size = childWidth !== null && childHeight !== null ? { width: childWidth, height: childHeight } : inherited.itemSize;
        return (
          // Layout widgets have no id, and a pack's layout never reorders: the position is the identity.
          <div
            className="absolute"
            key={index}
            style={{ left: pixels(child.canvas_left) ?? 0, top: pixels(child.canvas_top) ?? 0, width: childWidth ?? undefined, height: childHeight ?? undefined }}
          >
            <LayoutWidget inherited={{ ...inherited, itemSize: size }} node={child} />
          </div>
        );
      })}
    </div>
  );
  return width === null ? board : <FitWidth height={height} width={width}>{board}</FitWidth>;
}

/**
 * Story 54.9: a widget of a fixed pixel width (a canvas) shrinks to the room it has, never wider than the screen; it
 * is never enlarged.
 */
function FitWidth({ width, height, children }: { width: number; height: number | null; children: ReactNode }) {
  const outer = useRef<HTMLDivElement>(null);
  const [scale, setScale] = useState(1);
  useEffect(() => {
    const element = outer.current;
    if (element === null || typeof ResizeObserver === "undefined") return;
    const observer = new ResizeObserver(() => {
      const room = element.clientWidth;
      setScale(room > 0 && width > 0 ? Math.min(1, room / width) : 1);
    });
    observer.observe(element);
    return () => observer.disconnect();
  }, [width]);
  return (
    <div className="w-full max-w-full overflow-hidden" ref={outer} style={{ height: height === null ? undefined : height * scale }}>
      <div className="origin-top-left" style={{ transform: scale === 1 ? undefined : `scale(${scale})`, width }}>
        {children}
      </div>
    </div>
  );
}

function Group({ node, inherited }: Props) {
  const header = readText(node, "header");
  return (
    <section className="flex min-w-0 flex-col rounded-md border border-border bg-surface">
      {header !== null ? (
        <h3 className="border-b border-border px-2 py-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {header}
        </h3>
      ) : null}
      <div className="flex flex-col p-1">
        <Children inherited={{ ...inherited, cross: "horizontal" }} nodes={readChildren(node)} />
      </div>
    </section>
  );
}

type Tab = { title: string; content: LayoutNode | null };

function readTabs(node: LayoutNode): Tab[] {
  const tabs = node.tabs;
  if (!Array.isArray(tabs)) return [];
  return tabs.filter(isLayoutNode).map((tab, index) => ({
    title: readText(tab, "title") ?? `Onglet ${index + 1}`,
    content: readTabContent(tab),
  }));
}

function Tabbed({ node, inherited }: Props) {
  const tabs = readTabs(node);
  const [current, setCurrent] = useState(0);
  const shown = tabs[current] ?? tabs[0];
  if (shown === undefined) return null;
  return (
    <div className={GROW}>
      {/* Story 54.9: a pack's tab bar wraps rather than scrolling sideways (its own `style: "wrap"`, and the rule of
          the site: no horizontal scroll). */}
      <div className="flex flex-wrap gap-x-1 border-b border-border" role="tablist">
        {tabs.map((tab, index) => (
          <button
            aria-selected={tab === shown}
            className={tabClassName(tab === shown)}
            // Tab titles may repeat across a pack, not within one tab bar.
            key={`${tab.title}-${index}`}
            onClick={() => setCurrent(index)}
            role="tab"
            type="button"
          >
            {tab.title}
          </button>
        ))}
      </div>
      <div className={`${GROW} pt-2`} role="tabpanel">
        {shown.content !== null ? (
          <LayoutWidget
            // A new tab is a new subtree: its own nested tabs start on their first one.
            inherited={{ ...inherited, cross: "horizontal" }}
            key={current}
            node={shown.content}
          />
        ) : null}
      </div>
    </div>
  );
}

function readRows(node: LayoutNode): (string | null)[][] {
  const rows = node.rows;
  if (!Array.isArray(rows)) return [];
  return rows
    .filter((row): row is unknown[] => Array.isArray(row))
    .map((row) => row.map((code) => (typeof code === "string" && code !== "" ? code : null)));
}

function ItemGrid({ node, inherited }: Props) {
  const rows = readRows(node);
  const gap: CSSProperties = { columnGap: inherited.itemMargin.x, rowGap: inherited.itemMargin.y };
  return (
    <div className="flex flex-col" style={{ rowGap: inherited.itemMargin.y }}>
      {rows.map((row, rowIndex) => (
        // Grid rows and cells are positions: a pack never reorders them.
        // Story 54.9: a row wider than the room goes on to the next line instead of widening the page.
        <div className="flex flex-row flex-wrap" key={rowIndex} style={gap}>
          {row.map((code, cellIndex) =>
            code === null ? (
              <span
                className="block shrink-0"
                key={cellIndex}
                style={{ width: inherited.itemSize.width, height: inherited.itemSize.height }}
              />
            ) : (
              <TrackerItem code={code} key={cellIndex} size={inherited.itemSize} />
            ),
          )}
        </div>
      ))}
    </div>
  );
}

function LayoutReference({ node, inherited }: Props) {
  const { pack } = useTracker();
  const key = readText(node, "key");
  const target = key === null ? undefined : pack.layouts[key];
  if (!isLayoutNode(target)) return null;
  return <LayoutWidget inherited={inherited} node={target} />;
}

function MapWidget({ node }: { node: LayoutNode }) {
  const { pack } = useTracker();
  const names = mapNames(node, pack.maps.map((map) => map.name));
  return (
    <div className={`${GROW} gap-2`}>
      {names.map((name) => (
        <TrackerMap key={name} name={name} />
      ))}
    </div>
  );
}

function Unsupported({ type }: { type: string }) {
  const { reportUnsupported } = useTracker();
  useEffect(() => {
    reportUnsupported(type);
  }, [reportUnsupported, type]);
  return null;
}
