"use client";

import { ChevronDown, Maximize2, Minimize2, RotateCcw, SlidersHorizontal } from "lucide-react";
import { useEffect, useRef, useState, type RefObject } from "react";

import { buttonVariants } from "@/components/ui/button";

import type { TrackerSettings } from "./tracker-view";

/**
 * Story 54.9: the tracker's compact toolbar pieces. The settings and the reset sit in one « Options » menu, so the
 * bar holds on one line; a full-screen button shows the tracker alone, for a second screen next to the game.
 */

type OptionsProps = {
  settings: TrackerSettings;
  onSettings: (settings: TrackerSettings) => void;
  /** The reset waits for the pack to be loaded. */
  canReset: boolean;
  onReset: () => void;
};

export function OptionsMenu({ settings, onSettings, canReset, onReset }: OptionsProps) {
  const [open, setOpen] = useState(false);
  const menu = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    function onPointerDown(event: PointerEvent) {
      if (event.target instanceof Node && menu.current?.contains(event.target) !== true) setOpen(false);
    }
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") setOpen(false);
    }
    document.addEventListener("pointerdown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.removeEventListener("pointerdown", onPointerDown);
      document.removeEventListener("keydown", onKeyDown);
    };
  }, [open]);

  return (
    <div className="relative" ref={menu}>
      <button
        aria-expanded={open}
        className={buttonVariants({ variant: "secondary" })}
        onClick={() => setOpen((current) => !current)}
        type="button"
      >
        <SlidersHorizontal aria-hidden="true" className="size-4" />
        Options
        <ChevronDown aria-hidden="true" className="size-4" />
      </button>
      {open ? (
        <div
          aria-label="Options du tracker"
          className="absolute right-0 top-full z-30 mt-1 grid w-72 max-w-[calc(100vw-2rem)] gap-1 rounded-lg border border-border bg-surface p-3 text-sm shadow-lg"
          role="group"
        >
          <label className="inline-flex min-h-9 cursor-pointer items-center gap-2 text-foreground">
            <input
              checked={settings.showSequenceBreaks}
              className="size-4 accent-accent-text"
              onChange={(event) => onSettings({ ...settings, showSequenceBreaks: event.target.checked })}
              type="checkbox"
            />
            Afficher les séquences breaks
          </label>
          <label className="inline-flex min-h-9 cursor-pointer items-center gap-2 text-foreground">
            <input
              checked={settings.hideCleared}
              className="size-4 accent-accent-text"
              onChange={(event) => onSettings({ ...settings, hideCleared: event.target.checked })}
              type="checkbox"
            />
            Masquer ce qui est fait
          </label>
          <div className="mt-1 border-t border-border pt-2">
            <button
              className={`${buttonVariants({ variant: "ghost" })} w-full justify-start text-danger`}
              disabled={!canReset}
              onClick={() => {
                setOpen(false);
                onReset();
              }}
              type="button"
            >
              <RotateCcw aria-hidden="true" className="size-4" />
              Remettre à zéro
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

/** Whether `target` is the element the browser shows full screen, followed as it enters and leaves. */
function useFullscreen(target: RefObject<HTMLElement | null>): boolean {
  const [active, setActive] = useState(false);
  useEffect(() => {
    function onChange() {
      setActive(target.current !== null && document.fullscreenElement === target.current);
    }
    document.addEventListener("fullscreenchange", onChange);
    return () => document.removeEventListener("fullscreenchange", onChange);
  }, [target]);
  return active;
}

export function FullscreenButton({ target }: { target: RefObject<HTMLElement | null> }) {
  const active = useFullscreen(target);
  function toggle() {
    if (active) {
      void document.exitFullscreen().catch(() => undefined);
      return;
    }
    // Some browsers (an iPhone's) have no full screen for a page element: the button then does nothing.
    const element = target.current;
    if (element === null || typeof element.requestFullscreen !== "function") return;
    void element.requestFullscreen().catch(() => undefined);
  }
  const label = active ? "Quitter le plein écran" : "Plein écran";
  return (
    <button aria-label={label} className={buttonVariants({ variant: "secondary" })} onClick={toggle} title={label} type="button">
      {active ? <Minimize2 aria-hidden="true" className="size-4" /> : <Maximize2 aria-hidden="true" className="size-4" />}
    </button>
  );
}
