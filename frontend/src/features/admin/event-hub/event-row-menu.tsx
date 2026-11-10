"use client";

import { MoreHorizontal } from "lucide-react";
import type { KeyboardEvent } from "react";
import { useEffect, useId, useRef, useState } from "react";
import { useRouter } from "next/navigation";

import type { AdminEvent } from "../admin-events-api";
import { eventHubHref, menuActions, type EventStatus, type MenuAction } from "./event-status";

type Props = {
  event: AdminEvent;
  onTransition: (event: AdminEvent, status: EventStatus) => void;
  onFeature: (event: AdminEvent) => void;
  onConfigurePrivateAccess: (event: AdminEvent) => void;
};

/**
 * Story 3.17: the commands of an event row that are not its next step, behind one button. Menu button pattern:
 * Enter, Space or the down arrow open it on the first item, the arrows move, Home and End jump, Escape closes it and
 * gives the focus back to the button; a click outside closes it too.
 */
export function EventRowMenu({ event, onTransition, onFeature, onConfigurePrivateAccess }: Props) {
  const router = useRouter();
  const menuId = useId();
  const [open, setOpen] = useState(false);
  const rootRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const itemRefs = useRef<(HTMLButtonElement | null)[]>([]);
  const actions = menuActions(event);

  useEffect(() => {
    if (!open) return;
    itemRefs.current[0]?.focus();

    function onPointerDown(pointer: PointerEvent) {
      if (pointer.target instanceof Node && rootRef.current?.contains(pointer.target)) return;
      setOpen(false);
    }
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [open]);

  function close(focusButton: boolean) {
    setOpen(false);
    if (focusButton) buttonRef.current?.focus();
  }

  function run(action: MenuAction) {
    close(true);
    if (action.kind === "hub") router.push(eventHubHref(event.id));
    else if (action.kind === "transition") onTransition(event, action.status);
    else if (action.kind === "feature") onFeature(event);
    else onConfigurePrivateAccess(event);
  }

  function onMenuKeyDown(keyboard: KeyboardEvent<HTMLDivElement>) {
    const items = itemRefs.current.filter((item): item is HTMLButtonElement => item !== null);
    const index = items.findIndex((item) => item === document.activeElement);
    const moves: Record<string, number> = {
      ArrowDown: (index + 1) % items.length,
      ArrowUp: (index - 1 + items.length) % items.length,
      Home: 0,
      End: items.length - 1,
    };
    if (keyboard.key === "Escape") {
      keyboard.preventDefault();
      close(true);
    } else if (keyboard.key === "Tab") {
      setOpen(false);
    } else if (keyboard.key in moves) {
      keyboard.preventDefault();
      items[moves[keyboard.key]]?.focus();
    }
  }

  return (
    <div className="relative" ref={rootRef}>
      <button
        aria-controls={open ? menuId : undefined}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={`Autres actions pour ${event.title}`}
        className="inline-flex min-h-8 min-w-8 items-center justify-center rounded-md border border-border text-foreground transition-colors hover:border-accent hover:text-accent-text"
        onClick={() => setOpen((value) => !value)}
        onKeyDown={(keyboard) => {
          if (keyboard.key === "ArrowDown") {
            keyboard.preventDefault();
            setOpen(true);
          }
        }}
        ref={buttonRef}
        type="button"
      >
        <MoreHorizontal aria-hidden="true" className="size-4" />
      </button>
      {open ? (
        <div
          aria-label={`Actions pour ${event.title}`}
          className="absolute right-0 z-30 mt-1 grid min-w-56 gap-0.5 rounded-md border border-border bg-surface p-1 shadow-xl"
          id={menuId}
          onKeyDown={onMenuKeyDown}
          role="menu"
        >
          {actions.map((action, index) => (
            <button
              className="rounded px-3 py-2 text-left text-sm text-foreground hover:bg-surface-2 focus:bg-surface-2 focus:outline-none"
              key={`${action.kind}-${action.label}`}
              onClick={() => run(action)}
              ref={(element) => {
                itemRefs.current[index] = element;
              }}
              role="menuitem"
              tabIndex={-1}
              type="button"
            >
              {action.label}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}
