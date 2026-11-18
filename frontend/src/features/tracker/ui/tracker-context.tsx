"use client";

import { createContext, useContext, useEffect, useSyncExternalStore, type CSSProperties } from "react";

import type { ItemDefinition } from "../engine/items";
import type { EngineAction, LoadedPack } from "../engine/protocol";
import { GREY_FILTER, parseImageMods, type ImageModStep, type PackImages } from "./pack-images";
import type { TrackerSettings, TrackerView } from "./tracker-view";

/** What every widget of the tracker screen reads (story 54.5). */
export type TrackerScreenContext = {
  pack: LoadedPack;
  view: TrackerView;
  itemsByCode: Map<string, ItemDefinition>;
  itemsById: Map<number, ItemDefinition>;
  settings: TrackerSettings;
  images: PackImages;
  act: (action: EngineAction) => void;
  /** A widget type the screen does not draw (`canvas`, `recentpins`...): logged once. */
  reportUnsupported: (type: string) => void;
};

const Context = createContext<TrackerScreenContext | null>(null);

export const TrackerContextProvider = Context.Provider;

export function useTracker(): TrackerScreenContext {
  const context = useContext(Context);
  if (context === null) throw new Error("useTracker outside of the tracker screen");
  return context;
}

/** The object URL of a pack image, `undefined` while it loads, `null` when the pack lacks it. */
export function usePackImage(images: PackImages, path: string | null): string | null | undefined {
  useEffect(() => {
    if (path !== null) images.request(path);
  }, [images, path]);
  return useSyncExternalStore(
    images.subscribe,
    () => (path === null ? null : images.get(path)),
    () => undefined,
  );
}

type PackImageProps = {
  path: string | null;
  mods?: string | null;
  /** Greys the image on top of its mods: an inactive item without a dedicated image. */
  disabled?: boolean;
  alt: string;
  className?: string;
};

/**
 * A pack image with its PopTracker modifiers, applied left to right as PopTracker does (story 54.11): the filters
 * before the first overlay go on the image, each overlay is drawn over what precedes it, and a filter after an
 * overlay applies to the whole.
 */
export function PackImage({ path, mods = null, disabled = false, alt, className = "" }: PackImageProps) {
  const { images } = useTracker();
  const url = usePackImage(images, path);
  if (url === null || url === undefined) {
    return <span aria-label={alt} className={`block ${className}`} role="img" />;
  }
  const steps: ImageModStep[] = [...parseImageMods(mods), ...(disabled ? [{ kind: "filter" as const, css: GREY_FILTER }] : [])];
  const firstOverlay = steps.findIndex((step) => step.kind === "overlay");
  const own = firstOverlay === -1 ? steps : steps.slice(0, firstOverlay);
  const rest = firstOverlay === -1 ? [] : steps.slice(firstOverlay);

  let drawn = (
    // eslint-disable-next-line @next/next/no-img-element -- object URL of a pack file, nothing to optimise
    <img
      alt={alt}
      className="size-full object-contain [image-rendering:pixelated]"
      draggable={false}
      src={url}
      style={filterStyle(own.map((step) => step.css))}
    />
  );
  let filters: string[] = [];
  for (const step of rest) {
    if (step.kind === "filter") {
      filters.push(step.css);
      continue;
    }
    drawn = (
      <span className="relative block size-full" style={filterStyle(filters)}>
        {drawn}
        <Overlay css={step.css} path={step.path} />
      </span>
    );
    filters = [];
  }
  return (
    <span className={`relative block ${className}`}>
      {filters.length > 0 ? (
        <span className="relative block size-full" style={filterStyle(filters)}>
          {drawn}
        </span>
      ) : (
        drawn
      )}
    </span>
  );
}

function filterStyle(filters: string[]): CSSProperties | undefined {
  const css = filters.filter((filter) => filter !== "").join(" ");
  return css === "" ? undefined : { filter: css };
}

function Overlay({ path, css }: { path: string; css: string }) {
  const { images } = useTracker();
  const url = usePackImage(images, path);
  if (url === null || url === undefined) return null;
  // eslint-disable-next-line @next/next/no-img-element -- see above
  return <img alt="" className="absolute inset-0 size-full object-contain [image-rendering:pixelated]" draggable={false} src={url} style={filterStyle([css])} />;
}
