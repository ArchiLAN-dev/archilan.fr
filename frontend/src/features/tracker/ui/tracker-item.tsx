"use client";

import { useEffect, useRef, type CSSProperties, type MouseEvent, type PointerEvent } from "react";

import type { ItemDefinition, ItemImage, ItemState, MouseButton } from "../engine/items";
import type { Size } from "./layout-attributes";
import { PackImage, useTracker } from "./tracker-context";
import type { TrackerView } from "./tracker-view";

const LONG_PRESS_MS = 500;

/**
 * Left and right "clicks" of an item or a section (story 54.5, decision 4): a click advances, a right click goes
 * back without the context menu, and on a touch screen a long press goes back.
 */
export function usePress(onPress: (button: MouseButton) => void) {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const longPressed = useRef(false);

  useEffect(
    () => () => {
      if (timer.current !== null) clearTimeout(timer.current);
    },
    [],
  );

  function cancel() {
    if (timer.current !== null) clearTimeout(timer.current);
    timer.current = null;
  }

  return {
    onClick(event: MouseEvent) {
      event.stopPropagation();
      if (longPressed.current) {
        longPressed.current = false;
        return;
      }
      onPress("left");
    },
    onContextMenu(event: MouseEvent) {
      event.preventDefault();
      event.stopPropagation();
      // A long press already went back; Android follows it with a context menu event.
      if (longPressed.current) return;
      onPress("right");
    },
    onPointerDown(event: PointerEvent) {
      longPressed.current = false;
      if (event.pointerType !== "touch") return;
      cancel();
      timer.current = setTimeout(() => {
        timer.current = null;
        longPressed.current = true;
        onPress("right");
      }, LONG_PRESS_MS);
    },
    onPointerUp: cancel,
    onPointerLeave: cancel,
    onPointerCancel: cancel,
  };
}

type Look = { image: ItemImage; active: boolean; name: string };

function imageOf(definition: ItemDefinition, state: ItemState | undefined, view: TrackerView): Look {
  const stage = definition.stages[state?.stage ?? 0] ?? definition.stages[0];
  const name = stage?.name ?? definition.name;
  const empty: ItemImage = { img: null, imgMods: "", disabledImg: null, disabledImgMods: null };
  if (definition.type === "composite_toggle") {
    const left = definition.leftItemId === null ? false : view.items[definition.leftItemId]?.active === true;
    const right = definition.rightItemId === null ? false : view.items[definition.rightItemId]?.active === true;
    const image = definition.compositeImages.find((candidate) => candidate.left === left && candidate.right === right);
    return { image: image ?? stage ?? empty, active: true, name };
  }
  return { image: stage ?? empty, active: definition.type === "static" || state?.active === true, name };
}

/** PopTracker greys an inactive item unless the pack gives its own image or mods for that. */
function drawn(look: Look): { path: string | null; mods: string } {
  const { image, active } = look;
  if (active) return { path: image.img, mods: image.imgMods };
  return {
    path: image.disabledImg ?? image.img,
    mods: image.disabledImgMods ?? [image.imgMods, "@disabled"].filter((mod) => mod !== "").join(","),
  };
}

type Props = { code: string; size: Size };

export function TrackerItem({ code, size }: Props) {
  const { itemsByCode } = useTracker();
  const definition = itemsByCode.get(code);
  if (definition === undefined) {
    return <span aria-hidden="true" className="block shrink-0" style={sizeStyle(size)} />;
  }
  return <TrackerItemButton definition={definition} size={size} />;
}

function sizeStyle(size: Size): CSSProperties {
  return { "--item-w": `${size.width}px`, "--item-h": `${size.height}px` } as CSSProperties;
}

/** On a finger, an item is at least 40 px whatever the pack asks (story 54.5, AC 6). */
const SIZE_CLASS =
  "h-[var(--item-h)] w-[var(--item-w)] pointer-coarse:h-[max(var(--item-h),40px)] pointer-coarse:w-[max(var(--item-w),40px)]";

export function TrackerItemButton({ definition, size }: { definition: ItemDefinition; size: Size }) {
  const { view, act, itemsById } = useTracker();
  const state = view.items[definition.id];
  const ref = useRef<HTMLButtonElement>(null);
  const press = usePress((button) => act({ type: "itemClick", itemId: definition.id, button }));
  const consumable = definition.type === "consumable";

  // The wheel changes a consumable: a native listener, React's is passive and cannot keep the page from scrolling.
  useEffect(() => {
    const element = ref.current;
    if (element === null || !consumable) return;
    function onWheel(event: WheelEvent) {
      if (event.deltaY === 0) return;
      event.preventDefault();
      act({ type: "itemClick", itemId: definition.id, button: event.deltaY < 0 ? "left" : "right" });
    }
    element.addEventListener("wheel", onWheel, { passive: false });
    return () => element.removeEventListener("wheel", onWheel);
  }, [act, consumable, definition.id]);

  const base = definition.type === "toggle_badged" && definition.baseItemId !== null
    ? itemsById.get(definition.baseItemId)
    : undefined;
  const look = imageOf(base ?? definition, base === undefined ? state : view.items[base.id], view);
  const own = imageOf(definition, state, view);
  const picture = drawn(look);
  const overlay = state?.overlay !== undefined && state.overlay !== ""
    ? state.overlay
    : consumable && (state?.count ?? 0) > 0
      ? String(state?.count ?? 0)
      : null;
  const label = consumable ? `${own.name} : ${state?.count ?? 0}` : own.name;
  const pressed = definition.type === "static" ? undefined : own.active;

  return (
    <button
      aria-label={label}
      aria-pressed={pressed}
      className={`relative block shrink-0 touch-manipulation select-none rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 ${SIZE_CLASS}`}
      ref={ref}
      style={sizeStyle(size)}
      title={label}
      type="button"
      {...press}
    >
      <PackImage alt="" className="size-full" mods={picture.mods} path={picture.path} />
      {definition.type === "toggle_badged" && own.active ? (
        <PackImage
          alt=""
          className="absolute bottom-0 right-0 size-1/2"
          mods={own.image.imgMods}
          path={own.image.img}
        />
      ) : null}
      {overlay !== null ? (
        <span className="absolute bottom-0 right-0 rounded-sm bg-background/80 px-0.5 font-mono text-[10px] font-bold leading-tight text-foreground">
          {overlay}
        </span>
      ) : null}
    </button>
  );
}
