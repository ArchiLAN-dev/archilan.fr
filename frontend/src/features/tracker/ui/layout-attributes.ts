/**
 * The visual attributes of PopTracker layout widgets that the screen honours (story 54.5, decision 3): `item_size`,
 * `item_margin`, `margin`, `h_alignment`, `v_alignment`, `background`. Anything else is ignored.
 */
export type LayoutNode = Record<string, unknown> & { type?: unknown };

export function isLayoutNode(value: unknown): value is LayoutNode {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

export function readText(node: LayoutNode, key: string): string | null {
  const value = node[key];
  return typeof value === "string" ? value : null;
}

/** `content` is one node or a list of nodes depending on the widget. */
export function readChildren(node: LayoutNode): LayoutNode[] {
  const content = node.content;
  if (Array.isArray(content)) return content.filter(isLayoutNode);
  return isLayoutNode(content) ? [content] : [];
}

function numbers(value: unknown): number[] {
  if (typeof value === "number" && Number.isFinite(value)) return [value];
  if (typeof value !== "string") return [];
  return value
    .split(",")
    .map((part) => Number(part.trim()))
    .filter((part) => Number.isFinite(part));
}

export type Size = { width: number; height: number };

export const DEFAULT_ITEM_SIZE: Size = { width: 32, height: 32 };

/** `32` or `"32,24"`; a missing or broken value means the inherited one. */
export function readSize(value: unknown): Size | null {
  const parts = numbers(value);
  const [width, height] = parts;
  if (width === undefined || width <= 0) return null;
  return { width, height: height !== undefined && height > 0 ? height : width };
}

/**
 * The item size a widget sets for its items: `item_size`, then `item_width` / `item_height` (PopTracker's way to draw
 * items that are not square, such as a pack's settings drawn as 320x64 labels), each falling back on `base`.
 */
export function readItemSize(node: LayoutNode, base: Size): Size {
  const size = readSize(node.item_size) ?? base;
  const [width] = numbers(node.item_width);
  const [height] = numbers(node.item_height);
  return {
    width: width !== undefined && width > 0 ? width : size.width,
    height: height !== undefined && height > 0 ? height : size.height,
  };
}

export type Spacing = { x: number; y: number };

/** `item_margin`: `4` or `"4,0"` (horizontal, vertical). */
export function readSpacing(value: unknown): Spacing | null {
  const [x, y] = numbers(value);
  if (x === undefined) return null;
  return { x: Math.max(0, x), y: Math.max(0, y ?? x) };
}

export type Margin = { left: number; top: number; right: number; bottom: number };

/** `margin`: one value, `"x,y"` or `"left,top,right,bottom"`. */
export function readMargin(value: unknown): Margin | null {
  const parts = numbers(value).map((part) => Math.max(0, part));
  if (parts.length === 0) return null;
  const [a = 0, b = a, c = a, d = b] = parts;
  if (parts.length < 4) return { left: a, top: b, right: a, bottom: b };
  return { left: a, top: b, right: c, bottom: d };
}

export function marginStyle(margin: Margin | null): { padding: string } | undefined {
  if (margin === null) return undefined;
  return { padding: `${margin.top}px ${margin.right}px ${margin.bottom}px ${margin.left}px` };
}

const H_ALIGN: Record<string, string> = {
  left: "self-start",
  center: "self-center",
  right: "self-end",
  stretch: "self-stretch",
};

const V_ALIGN: Record<string, string> = {
  top: "self-start",
  center: "self-center",
  bottom: "self-end",
  stretch: "self-stretch",
};

/**
 * Alignment of a widget in its parent: in a column the horizontal one applies, in a row the vertical one. The parent
 * says which axis is its cross axis.
 */
export function alignmentClass(node: LayoutNode, crossAxis: "horizontal" | "vertical"): string {
  const value = readText(node, crossAxis === "horizontal" ? "h_alignment" : "v_alignment");
  if (value === null) return "";
  return (crossAxis === "horizontal" ? H_ALIGN : V_ALIGN)[value] ?? "";
}

/** `#RRGGBB`, `#AARRGGBB` (PopTracker puts alpha first) or a CSS color name. */
export function readColor(value: unknown): string | null {
  if (typeof value !== "string") return null;
  const color = value.trim();
  const argb = /^#([0-9a-f]{2})([0-9a-f]{6})$/i.exec(color);
  if (argb !== null) {
    const [, alpha = "ff", rgb = "000000"] = argb;
    return `#${rgb}${alpha}`;
  }
  if (/^#[0-9a-f]{3,6}$/i.test(color) || /^[a-z]+$/i.test(color)) return color;
  return null;
}
