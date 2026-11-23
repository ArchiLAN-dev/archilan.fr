"use client";

import { Maximize, Minus, Plus } from "lucide-react";
import { useCallback, useEffect, useRef, useState, useSyncExternalStore, type PointerEvent, type CSSProperties } from "react";
import { createPortal } from "react-dom";

import type { LoadedLocation, LoadedMap } from "../engine/protocol";
import type { MapLocation } from "../engine/locations";
import { LocationDetail } from "./location-detail";
import { usePackImage, useTracker } from "./tracker-context";
import { HINT_LABELS, locationHighlight, locationMarks, pinBackground, pinCategory, pinLabel, pinShape, pinShown, type PinCategory, type TrackerView } from "./tracker-view";

/**
 * A pack map with its pins (story 54.5, decision 5): the image at its own pixel size, pins at the pack's
 * coordinates, the whole thing zoomed and moved with a wheel, a pinch or a drag.
 */
const DEFAULT_PIN_SIZE = 16;
const DEFAULT_BORDER = 1;
const MIN_SCALE = 0.1;
const MAX_SCALE = 8;
/** On a finger a pin answers to a touch this many screen pixels wide (story 54.5, AC 6), whatever its drawn size. */
const COARSE_PIN_PX = 28;
/** Past this distance a press is a drag, not a click on a pin. */
const DRAG_THRESHOLD = 6;

type Transform = { scale: number; x: number; y: number };
type Point = { x: number; y: number };

function subscribeCoarse(listener: () => void): () => void {
  const query = window.matchMedia("(pointer: coarse)");
  query.addEventListener("change", listener);
  return () => query.removeEventListener("change", listener);
}

function useCoarsePointer(): boolean {
  return useSyncExternalStore(
    subscribeCoarse,
    () => window.matchMedia("(pointer: coarse)").matches,
    () => false,
  );
}

function clampScale(scale: number, min = MIN_SCALE): number {
  return Math.min(MAX_SCALE, Math.max(min, scale));
}

type Size = { width: number; height: number };

/**
 * Zoom by `factor` keeping the screen point `at` still. Story 54.9: given the image and the viewport, it never goes
 * below the whole map; reaching it, the map is centred again.
 */
export function zoomAt(transform: Transform, factor: number, at: Point, bounds?: { image: Size; viewport: Size }): Transform {
  const whole = bounds === undefined ? null : fitTransform(bounds.image, bounds.viewport);
  const scale = clampScale(transform.scale * factor, whole?.scale ?? MIN_SCALE);
  if (whole !== null && scale <= whole.scale) return whole;
  const ratio = scale / transform.scale;
  return { scale, x: at.x - (at.x - transform.x) * ratio, y: at.y - (at.y - transform.y) * ratio };
}

/** The whole image in the viewport, centred. */
export function fitTransform(image: { width: number; height: number }, viewport: { width: number; height: number }): Transform {
  if (image.width <= 0 || image.height <= 0 || viewport.width <= 0 || viewport.height <= 0) return { scale: 1, x: 0, y: 0 };
  const scale = clampScale(Math.min(viewport.width / image.width, viewport.height / image.height));
  return { scale, x: (viewport.width - image.width * scale) / 2, y: (viewport.height - image.height * scale) / 2 };
}

type Pin = { location: LoadedLocation; spot: MapLocation; key: string };

function pinsOf(locations: readonly LoadedLocation[], map: string, view: TrackerView): Pin[] {
  const pins: Pin[] = [];
  for (const location of locations) {
    location.mapLocations.forEach((spot, index) => {
      if (spot.map === map && pinShown(view, location.id, index)) pins.push({ location, spot, key: `${location.id}-${index}` });
    });
  }
  return pins;
}

/** No filter: every pin shows. */
const ALL_PINS: ReadonlySet<PinCategory> = new Set();

/** `only`: story 54.9, the legend's counters narrow the map to some pins; empty, every pin shows. */
export function TrackerMap({ name, only = ALL_PINS }: { name: string; only?: ReadonlySet<PinCategory> }) {
  const { pack, images } = useTracker();
  const map = pack.maps.find((candidate) => candidate.name === name);
  const url = usePackImage(images, map?.img ?? null);
  if (map === undefined) return null;
  if (url === null) return <p className="text-sm text-muted-foreground">Image de la carte « {name} » absente du pack.</p>;
  if (url === undefined) return <div aria-busy="true" className="min-h-64 flex-1 animate-pulse rounded-md bg-surface" />;
  return <MapCanvas map={map} only={only} url={url} />;
}

function MapCanvas({ map, url, only }: { map: LoadedMap; url: string; only: ReadonlySet<PinCategory> }) {
  const { pack, view } = useTracker();
  const viewport = useRef<HTMLDivElement>(null);
  const [natural, setNatural] = useState<{ width: number; height: number } | null>(null);
  // The wheel listener is set once: it reads the image size from here.
  const naturalRef = useRef<Size | null>(null);
  useEffect(() => {
    naturalRef.current = natural;
  }, [natural]);
  const [transform, setTransform] = useState<Transform>({ scale: 1, x: 0, y: 0 });
  const [selected, setSelected] = useState<Pin | null>(null);
  /** Once the player moved the map, a resize no longer refits it. */
  const touched = useRef(false);
  const pointers = useRef(new Map<number, Point>());
  const gesture = useRef<{ moved: number; pinch: number | null }>({ moved: 0, pinch: null });
  const coarse = useCoarsePointer();
  const pins = pinsOf(pack.locations, map.name, view);

  const fit = useCallback(() => {
    const element = viewport.current;
    if (element === null || natural === null) return;
    setTransform(fitTransform(natural, { width: element.clientWidth, height: element.clientHeight }));
  }, [natural]);

  useEffect(() => {
    const element = viewport.current;
    if (element === null) return;
    const observer = new ResizeObserver(() => {
      if (!touched.current) fit();
    });
    observer.observe(element);
    return () => observer.disconnect();
  }, [fit]);

  // The wheel zooms: a native listener, React's is passive and the page would scroll too.
  useEffect(() => {
    const element = viewport.current;
    if (element === null) return;
    const target: HTMLDivElement = element;
    function onWheel(event: WheelEvent) {
      event.preventDefault();
      touched.current = true;
      const box = target.getBoundingClientRect();
      const at = { x: event.clientX - box.left, y: event.clientY - box.top };
      setTransform((current) => zoomAt(current, event.deltaY < 0 ? 1.2 : 1 / 1.2, at, boundsOf(target, naturalRef.current)));
    }
    element.addEventListener("wheel", onWheel, { passive: false });
    return () => element.removeEventListener("wheel", onWheel);
  }, []);

  function local(event: PointerEvent): Point {
    const box = event.currentTarget.getBoundingClientRect();
    return { x: event.clientX - box.left, y: event.clientY - box.top };
  }

  function onPointerDown(event: PointerEvent<HTMLDivElement>) {
    pointers.current.set(event.pointerId, local(event));
    if (pointers.current.size === 1) gesture.current = { moved: 0, pinch: null };
    if (pointers.current.size === 2) gesture.current.pinch = spread(pointers.current);
  }

  function onPointerMove(event: PointerEvent<HTMLDivElement>) {
    const previous = pointers.current.get(event.pointerId);
    if (previous === undefined) return;
    const point = local(event);
    pointers.current.set(event.pointerId, point);
    const dx = point.x - previous.x;
    const dy = point.y - previous.y;
    gesture.current.moved += Math.abs(dx) + Math.abs(dy);
    if (gesture.current.moved < DRAG_THRESHOLD) return;
    if (!event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.setPointerCapture(event.pointerId);
    touched.current = true;
    if (pointers.current.size >= 2) {
      const distance = spread(pointers.current);
      const before = gesture.current.pinch;
      gesture.current.pinch = distance;
      if (before === null || before === 0) return;
      const center = middle(pointers.current);
      const bounds = boundsOf(event.currentTarget, natural);
      setTransform((current) => zoomAt(current, distance / before, center, bounds));
      return;
    }
    setTransform((current) => ({ ...current, x: current.x + dx, y: current.y + dy }));
  }

  function onPointerUp(event: PointerEvent<HTMLDivElement>) {
    pointers.current.delete(event.pointerId);
    if (pointers.current.size < 2) gesture.current.pinch = null;
  }

  function zoomButton(factor: number) {
    const element = viewport.current;
    if (element === null) return;
    touched.current = true;
    setTransform((current) => zoomAt(current, factor, { x: element.clientWidth / 2, y: element.clientHeight / 2 }, boundsOf(element, natural)));
  }

  return (
    // Story 54.9: on a phone the map area takes the image's proportions (no empty band above and below a wide map),
    // within bounds; on a wide screen it fills the space beside the items.
    <div
      className={`relative flex min-w-0 flex-col lg:min-h-80 lg:flex-1 ${
        natural === null ? "min-h-64" : "aspect-[var(--map-ratio)] max-h-[75dvh] w-full lg:aspect-auto lg:max-h-none lg:w-auto"
      }`}
      style={natural === null ? undefined : ({ "--map-ratio": `${natural.width} / ${natural.height}` } as CSSProperties)}
    >
      <div
        aria-label={`Carte ${map.name}`}
        className="relative min-h-0 flex-1 cursor-grab touch-none select-none overflow-hidden rounded-md border border-border bg-background active:cursor-grabbing"
        // A drag that ends on a pin is not a click on it.
        onClickCapture={(event) => {
          if (gesture.current.moved >= DRAG_THRESHOLD) event.stopPropagation();
        }}
        onClick={() => setSelected(null)}
        onPointerCancel={onPointerUp}
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={onPointerUp}
        ref={viewport}
        role="application"
      >
        <div
          className="absolute left-0 top-0 origin-top-left"
          style={{ transform: `translate(${transform.x}px, ${transform.y}px) scale(${transform.scale})` }}
        >
          {/* eslint-disable-next-line @next/next/no-img-element -- object URL of a pack file */}
          <img
            alt=""
            className="block max-w-none [image-rendering:pixelated]"
            draggable={false}
            onLoad={(event) => {
              const image = event.currentTarget;
              setNatural({ width: image.naturalWidth, height: image.naturalHeight });
              const element = viewport.current;
              if (element !== null && !touched.current) {
                setTransform(
                  fitTransform(
                    { width: image.naturalWidth, height: image.naturalHeight },
                    { width: element.clientWidth, height: element.clientHeight },
                  ),
                );
              }
            }}
            src={url}
          />
          {pins.map((pin) => (
            <MapPin
              only={only}
              key={pin.key}
              map={map}
              minHit={coarse ? COARSE_PIN_PX / transform.scale : 0}
              onSelect={() => setSelected(pin)}
              pin={pin}
              selected={selected?.key === pin.key}
            />
          ))}
        </div>
        {selected !== null ? (
          <PinPopover onClose={() => setSelected(null)} pin={selected} transform={transform} viewport={viewport} />
        ) : null}
      </div>
      <div className="absolute right-2 top-2 flex flex-col gap-1">
        <MapButton label="Zoomer" onClick={() => zoomButton(1.4)}>
          <Plus aria-hidden="true" className="size-4" />
        </MapButton>
        <MapButton label="Dézoomer" onClick={() => zoomButton(1 / 1.4)}>
          <Minus aria-hidden="true" className="size-4" />
        </MapButton>
        <MapButton
          label="Voir toute la carte"
          onClick={() => {
            touched.current = false;
            fit();
          }}
        >
          <Maximize aria-hidden="true" className="size-4" />
        </MapButton>
      </div>
    </div>
  );
}

/** The zoom's floor: the whole image in the viewport, once the image size is known. */
function boundsOf(element: HTMLElement, image: Size | null): { image: Size; viewport: Size } | undefined {
  if (image === null) return undefined;
  return { image, viewport: { width: element.clientWidth, height: element.clientHeight } };
}

function spread(points: Map<number, Point>): number {
  const [a, b] = [...points.values()];
  if (a === undefined || b === undefined) return 0;
  return Math.hypot(a.x - b.x, a.y - b.y);
}

function middle(points: Map<number, Point>): Point {
  const [a, b] = [...points.values()];
  if (a === undefined || b === undefined) return { x: 0, y: 0 };
  return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
}

function MapButton({ label, onClick, children }: { label: string; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      aria-label={label}
      className="inline-flex size-9 items-center justify-center rounded-md border border-border bg-surface/90 text-foreground hover:border-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60"
      onClick={onClick}
      title={label}
      type="button"
    >
      {children}
    </button>
  );
}

type PinProps = { pin: Pin; map: LoadedMap; minHit: number; selected: boolean; onSelect: () => void; only: ReadonlySet<PinCategory> };

export function MapPin({ pin, map, minHit, selected, onSelect, only }: PinProps) {
  const { view, settings } = useTracker();
  // Story 54.9: the states of the sections still to do, each in its part of the pin, as in PopTracker.
  const marks = locationMarks(pin.location, view, settings);
  if (marks === null) return null;
  if (settings.hideCleared && marks === 0) return null;
  if (only.size > 0 && !only.has(pinCategory(marks))) return null;
  const size = pin.spot.size ?? map.locationSize ?? DEFAULT_PIN_SIZE;
  // Enlarged with an invisible frame, so that pins do not cover each other when the map is zoomed out.
  const hit = Math.max(0, (minHit - size) / 2);
  const border = pin.spot.borderThickness ?? map.locationBorderThickness ?? DEFAULT_BORDER;
  const shape = pinShape(pin.spot.shape ?? map.locationShape);
  const diamond = shape === "diamond";
  const trapezoid = shape === "trapezoid";
  const hint = HINT_LABELS[locationHighlight(pin.location, view)];
  return (
    <button
      aria-label={`${pin.location.name} : ${pinLabel(marks)}${hint === null ? "" : `, ${hint}`}`}
      className={`absolute border-background before:absolute before:inset-[calc(-1*var(--pin-hit))] before:content-[''] ${diamond ? "rotate-45" : ""} ${
        selected ? "outline outline-2 outline-foreground" : ""
      } focus-visible:outline focus-visible:outline-2 focus-visible:outline-foreground`}
      data-marks={marks}
      onClick={(event) => {
        event.stopPropagation();
        onSelect();
      }}
      // A diamond keeps the pin's footprint: its diagonal is the size.
      style={{
        left: pin.spot.x - size / 2,
        top: pin.spot.y - size / 2,
        width: diamond ? size / Math.SQRT2 : size,
        height: diamond ? size / Math.SQRT2 : size,
        margin: diamond ? (size - size / Math.SQRT2) / 2 : 0,
        // Story 54.14: a trapezoid draws its border and its fill in the layers below, the button stays square.
        borderWidth: trapezoid ? 0 : border,
        background: trapezoid ? "transparent" : pinBackground(marks),
        ["--pin-hit" as string]: `${hit}px`,
      }}
      title={pin.location.name}
      type="button"
    >
      {trapezoid ? <TrapezoidLayers border={border} marks={marks} size={size} /> : null}
      {hint === null ? null : (
        <span
          aria-hidden="true"
          className="pointer-events-none absolute -right-1 -top-1 size-2.5 rounded-full border border-background bg-foreground"
          data-hint=""
        />
      )}
    </button>
  );
}

type TrapezoidProps = { size: number; border: number; marks: number };

/**
 * Story 54.14: a pin in PopTracker's trapezoid: the bottom across the whole width, the top from a quarter to three
 * quarters of it (`drawTrapezoid`). The border is the trapezoid in the border colour, the fill a smaller one on top,
 * both cut with `clip-path` inside the square button, so the click area, the outline and the hint stay whole.
 */
export function TrapezoidLayers({ size, border, marks }: TrapezoidProps) {
  const inner = Math.max(0, size - 2 * border);
  // As PopTracker: the fill's top starts where the border's inner edge does.
  const left = Math.min(inner / 2, size / 4);
  const right = Math.max(inner / 2, (3 * size) / 4 - 2 * border);
  return (
    <>
      <span
        aria-hidden="true"
        className="pointer-events-none absolute inset-0 bg-background"
        data-trapezoid="border"
        style={{ clipPath: "polygon(0 100%, 25% 0, 75% 0, 100% 100%)" }}
      />
      <span
        aria-hidden="true"
        className="pointer-events-none absolute"
        data-trapezoid="fill"
        style={{
          inset: border,
          background: pinBackground(marks, "trapezoid"),
          clipPath: `polygon(0 100%, ${left}px 0, ${right}px 0, 100% 100%)`,
        }}
      />
    </>
  );
}

type PopoverProps = {
  pin: Pin;
  transform: Transform;
  viewport: React.RefObject<HTMLDivElement | null>;
  onClose: () => void;
};

const POPOVER_WIDTH = 288;
const POPOVER_GAP = 12;
const SCREEN_MARGIN = 8;

export type PopoverPlace = { left: number; top: number; width: number; maxHeight: number };

/**
 * Story 54.9: where the checks of a pin open, in window coordinates. Beside the pin, kept inside the window; below it
 * when it fits, above it otherwise, and against the window's edge when neither does.
 */
export function placePopover(anchor: Point, height: number, screen: Size): PopoverPlace {
  const width = Math.min(POPOVER_WIDTH, screen.width - 2 * SCREEN_MARGIN);
  const maxHeight = screen.height - 2 * SCREEN_MARGIN;
  const shown = Math.min(height, maxHeight);
  const left = Math.min(Math.max(SCREEN_MARGIN, anchor.x + POPOVER_GAP), screen.width - width - SCREEN_MARGIN);

  const below = anchor.y + POPOVER_GAP;
  const above = anchor.y - POPOVER_GAP - shown;
  let top: number;
  if (below + shown <= screen.height - SCREEN_MARGIN) top = below;
  else if (above >= SCREEN_MARGIN) top = above;
  else top = Math.max(SCREEN_MARGIN, screen.height - SCREEN_MARGIN - shown);

  return { left: Math.max(SCREEN_MARGIN, left), top, width, maxHeight };
}

/**
 * The checks of a pin. Story 54.9: drawn over the page (a portal, fixed to the window) and not inside the map, whose
 * frame clipped it; in full screen it goes in the full-screen element, the only part of the page then shown.
 */
function PinPopover({ pin, transform, viewport, onClose }: PopoverProps) {
  const box = useRef<HTMLDivElement>(null);
  const [placed, setPlaced] = useState<{ place: PopoverPlace; host: Element } | null>(null);

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") onClose();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  useEffect(() => {
    let frame = 0;
    function update() {
      const element = viewport.current;
      if (element === null) return;
      const rect = element.getBoundingClientRect();
      const anchor = { x: rect.left + pin.spot.x * transform.scale + transform.x, y: rect.top + pin.spot.y * transform.scale + transform.y };
      const screen = { width: document.documentElement.clientWidth, height: window.innerHeight };
      const host = document.fullscreenElement ?? document.body;
      setPlaced({ place: placePopover(anchor, box.current?.scrollHeight ?? 240, screen), host });
    }
    function schedule() {
      cancelAnimationFrame(frame);
      frame = requestAnimationFrame(update);
    }
    schedule();
    // Once more after the first paint: the box then has its real height.
    const settle = window.setTimeout(schedule, 50);
    window.addEventListener("resize", schedule);
    window.addEventListener("scroll", schedule, true);
    document.addEventListener("fullscreenchange", schedule);
    return () => {
      cancelAnimationFrame(frame);
      window.clearTimeout(settle);
      window.removeEventListener("resize", schedule);
      window.removeEventListener("scroll", schedule, true);
      document.removeEventListener("fullscreenchange", schedule);
    };
  }, [pin, transform, viewport]);

  if (placed === null) return null;
  const { place, host } = placed;

  return createPortal(
    <div
      aria-label={pin.location.name}
      className="fixed z-50 cursor-auto overflow-auto rounded-lg border border-border bg-surface p-3 shadow-lg"
      onClick={(event) => event.stopPropagation()}
      onPointerDown={(event) => event.stopPropagation()}
      ref={box}
      role="dialog"
      style={{ left: place.left, top: place.top, width: place.width, maxHeight: place.maxHeight }}
    >
      <LocationDetail location={pin.location} onClose={onClose} />
    </div>,
    host,
  );
}
