// Query-param driven customization for OBS overlays - tweakable without a rebuild. Parsed once from
// the URL and passed to the widgets. All values fall back to sane defaults; invalid input is ignored.

export type OverlayPos =
  | "top-left"
  | "top-center"
  | "top-right"
  | "bottom-left"
  | "bottom-center"
  | "bottom-right"
  | "center";

export type OverlayParams = {
  // Slots to keep, from a comma-separated `?slot=` (e.g. "1,3"). Empty = no filter (all players).
  slots: string[];
  scale: number;
  pos: OverlayPos;
  duration: number; // seconds; 0 = widget default
  theme: string | null;
  sound: boolean;
  spoilers: boolean;
  bg: string | null; // 6-hex chroma key without leading '#', or null for transparent
  demo: boolean;
};

const POSITIONS: readonly OverlayPos[] = [
  "top-left",
  "top-center",
  "top-right",
  "bottom-left",
  "bottom-center",
  "bottom-right",
  "center",
];

function clampNumber(raw: string | null, min: number, max: number, fallback: number): number {
  const n = Number(raw);
  if (!Number.isFinite(n) || n <= 0) return fallback;
  return Math.min(max, Math.max(min, n));
}

function parseSlots(raw: string | null): string[] {
  if (!raw) return [];
  return raw
    .split(",")
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
}

export function parseOverlayParams(sp: URLSearchParams): OverlayParams {
  const posRaw = sp.get("pos");
  const bgRaw = sp.get("bg");

  return {
    slots: parseSlots(sp.get("slot")),
    scale: clampNumber(sp.get("scale"), 0.25, 4, 1),
    pos: POSITIONS.includes(posRaw as OverlayPos) ? (posRaw as OverlayPos) : "top-center",
    duration: sp.get("duration") ? clampNumber(sp.get("duration"), 1, 60, 0) : 0,
    theme: sp.get("theme"),
    sound: sp.get("sound") === "1",
    spoilers: sp.get("spoilers") === "1",
    bg: bgRaw && /^[0-9a-fA-F]{6}$/.test(bgRaw) ? bgRaw : null,
    demo: sp.get("demo") === "1",
  };
}

/** Flex alignment classes for a full-viewport container, honoring the `pos` anchor. */
export function posToContainerClasses(pos: OverlayPos): string {
  const vertical =
    pos.startsWith("top") ? "items-start" : pos.startsWith("bottom") ? "items-end" : "items-center";
  const horizontal = pos.endsWith("left")
    ? "justify-start"
    : pos.endsWith("right")
      ? "justify-end"
      : "justify-center";
  return `${vertical} ${horizontal}`;
}

/**
 * CSS transform-origin matching the `pos` anchor, so scaling grows the content *away* from the edge it
 * is pinned to (into the screen) rather than off-canvas.
 */
export function posToTransformOrigin(pos: OverlayPos): string {
  const vertical = pos.startsWith("top") ? "top" : pos.startsWith("bottom") ? "bottom" : "center";
  const horizontal = pos.endsWith("left") ? "left" : pos.endsWith("right") ? "right" : "center";
  return `${vertical} ${horizontal}`;
}
