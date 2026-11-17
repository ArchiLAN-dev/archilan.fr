"use client";

import { Check, Package, X } from "lucide-react";

import { AccessibilityLevel } from "../engine/levels";
import type { LoadedLocation, LoadedSection } from "../engine/protocol";
import { DEFAULT_ITEM_SIZE } from "./layout-attributes";
import { PackImage, useTracker } from "./tracker-context";
import { TrackerItem, usePress } from "./tracker-item";
import { displayLevel, HINT_LABELS, LEVEL_STYLES, sectionStateOf } from "./tracker-view";

type Props = { location: LoadedLocation; onClose: () => void };

/**
 * The sections of a location (story 54.5, decision 6): a click does a section, a right click (or a long press)
 * undoes it. A section's hosted item is drawn next to it.
 */
export function LocationDetail({ location, onClose }: Props) {
  const { view, settings } = useTracker();
  const sections = location.sections.filter((section) => {
    const state = sectionStateOf(section, view);
    if (state === undefined || !state.visible) return false;
    return !(settings.hideCleared && state.level === AccessibilityLevel.Cleared);
  });

  return (
    <div className="grid gap-2">
      <div className="flex items-start justify-between gap-2">
        <h3 className="font-heading text-base font-semibold text-foreground">{location.name}</h3>
        <button
          aria-label="Fermer"
          className="inline-flex size-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-surface-2 hover:text-foreground"
          onClick={onClose}
          type="button"
        >
          <X aria-hidden="true" className="size-4" />
        </button>
      </div>
      {sections.length === 0 ? (
        <p className="text-sm text-muted-foreground">Rien à afficher ici.</p>
      ) : (
        <ul className="grid gap-1">
          {sections.map((section) => (
            <li key={section.id}>
              <SectionRow section={section} />
            </li>
          ))}
        </ul>
      )}
      <p className="text-xs text-muted-foreground">Clic : fait. Clic droit ou appui long : défaire.</p>
    </div>
  );
}

function SectionRow({ section }: { section: LoadedSection }) {
  const { view, settings, act } = useTracker();
  const state = sectionStateOf(section, view);
  const target = section.refId ?? section.id;
  const press = usePress((button) =>
    act(button === "left" ? { type: "sectionClear", sectionId: target } : { type: "sectionUnclear", sectionId: target }),
  );
  if (state === undefined) return null;
  const level = displayLevel(state.level, settings);
  const done = state.level === AccessibilityLevel.Cleared;
  const style = LEVEL_STYLES[level];
  const opened = section.itemCount > 0 ? state.available === 0 : done;
  const chest = opened ? section.images.opened : section.images.unopened;
  const hint = done ? null : HINT_LABELS[state.highlight];

  return (
    <div className="flex items-center gap-2">
      <button
        aria-label={`${section.name} : ${done ? "fait" : style.label}${hint === null ? "" : `, ${hint}`}`}
        className={`flex min-h-11 flex-1 touch-manipulation select-none items-center gap-2 rounded-md border border-border px-2 py-1 text-left text-sm hover:border-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 ${
          done ? "text-muted-foreground" : "text-foreground"
        }`}
        data-level={level}
        type="button"
        {...press}
      >
        <span aria-hidden="true" className={`size-2.5 shrink-0 rounded-full ${style.className}`} />
        {chest !== null ? (
          <PackImage alt="" className="size-6 shrink-0" path={chest} />
        ) : opened ? (
          <Check aria-hidden="true" className="size-5 shrink-0 text-muted-foreground" />
        ) : (
          <Package aria-hidden="true" className="size-5 shrink-0" />
        )}
        <span className={`min-w-0 flex-1 ${done ? "line-through" : ""}`}>{section.name}</span>
        {hint === null ? null : (
          <span aria-hidden="true" className="shrink-0 rounded-full border border-border px-1.5 text-xs text-muted-foreground">
            {hint}
          </span>
        )}
        {section.itemCount > 1 ? (
          <span className="shrink-0 font-mono text-xs text-muted-foreground">
            {state.available}/{section.itemCount}
          </span>
        ) : null}
      </button>
      {section.hostedItems.map((code) => (
        <TrackerItem code={code} key={code} size={DEFAULT_ITEM_SIZE} />
      ))}
    </div>
  );
}
