import type { CSSProperties } from "react";

/**
 * The part of an uploaded image a profile shows (story 30.43): the point aimed at, in percent of the image, and a
 * zoom in percent (100 to 300). Mirrors `ImageFraming` on the API. Applied in CSS, never by cutting the file, so a
 * GIF keeps moving and its first frame takes the same framing.
 */
export type ImageFraming = { x: number; y: number; zoom: number };

export const CENTRED_FRAMING: ImageFraming = { x: 50, y: 50, zoom: 100 };
export const MIN_ZOOM = 100;
export const MAX_ZOOM = 300;
const NUDGE = 5;

type Size = { width: number; height: number };

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

export function isImageFraming(value: unknown): value is ImageFraming {
  return (
    typeof value === "object" &&
    value !== null &&
    "x" in value &&
    typeof value.x === "number" &&
    "y" in value &&
    typeof value.y === "number" &&
    "zoom" in value &&
    typeof value.zoom === "number"
  );
}

export function sameFraming(a: ImageFraming, b: ImageFraming): boolean {
  return a.x === b.x && a.y === b.y && a.zoom === b.zoom;
}

/**
 * The style of an `object-cover` image inside an `overflow-hidden` frame: positioned on the point, zoomed around
 * it. A zoom of at least 100 % around a point of the image never uncovers the frame.
 */
export function framingStyle(framing: ImageFraming | null | undefined): CSSProperties {
  if (!framing || sameFraming(framing, CENTRED_FRAMING)) return {};
  const position = `${framing.x}% ${framing.y}%`;
  if (framing.zoom <= MIN_ZOOM) return { objectPosition: position };
  return { objectPosition: position, transform: `scale(${framing.zoom / 100})`, transformOrigin: position };
}

/**
 * The framing after dragging the image by (dx, dy) pixels in a frame: the image follows the pointer. Each axis
 * pans over the part of the image (covered, then zoomed) that overflows the frame.
 */
export function dragFraming(framing: ImageFraming, dx: number, dy: number, frame: Size, image: Size): ImageFraming {
  if (image.width <= 0 || image.height <= 0) return framing;
  const cover = Math.max(frame.width / image.width, frame.height / image.height) * (framing.zoom / 100);
  const pan = (value: number, delta: number, rendered: number, visible: number) => {
    const room = rendered - visible;
    return room < 0.5 ? value : clamp(Math.round(value - (delta * 100) / room), 0, 100);
  };

  return {
    x: pan(framing.x, dx, image.width * cover, frame.width),
    y: pan(framing.y, dy, image.height * cover, frame.height),
    zoom: framing.zoom,
  };
}

/** The framing after an arrow key (the image moves towards what the key points to), or null for another key. */
export function nudgeFraming(framing: ImageFraming, key: string): ImageFraming | null {
  const moves: Record<string, [number, number]> = {
    ArrowLeft: [-NUDGE, 0],
    ArrowRight: [NUDGE, 0],
    ArrowUp: [0, -NUDGE],
    ArrowDown: [0, NUDGE],
  };
  const move = moves[key];
  if (!move) return null;
  return { x: clamp(framing.x + move[0], 0, 100), y: clamp(framing.y + move[1], 0, 100), zoom: framing.zoom };
}

export function zoomFraming(framing: ImageFraming, zoom: number): ImageFraming {
  return { ...framing, zoom: clamp(Math.round(zoom), MIN_ZOOM, MAX_ZOOM) };
}
