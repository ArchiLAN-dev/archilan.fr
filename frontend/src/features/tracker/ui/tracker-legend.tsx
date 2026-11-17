"use client";

import { ChevronDown, CircleHelp } from "lucide-react";
import { useState } from "react";

import { AccessibilityLevel } from "../engine/levels";
import { LEGEND_ORDER, LEVEL_STYLES, PinMark, pinBackground, type TrackerSettings } from "./tracker-view";

/**
 * Story 54.9: the legend of the map pins, next to the map it explains. Plain words, a swatch drawn like a pin, and
 * one sentence per colour for whoever wonders what it means.
 */
const MEANINGS: Record<AccessibilityLevel, string> = {
  [AccessibilityLevel.Normal]: "Tu as ce qu'il faut pour y aller.",
  [AccessibilityLevel.SequenceBreak]: "Possible avec une astuce que la logique du jeu ne prévoit pas.",
  [AccessibilityLevel.Inspect]: "Tu peux voir ce qu'il y a, sans pouvoir le prendre pour l'instant.",
  [AccessibilityLevel.None]: "Il te manque encore quelque chose.",
  [AccessibilityLevel.Cleared]: "Tout est ramassé.",
  [AccessibilityLevel.Partial]: "",
};

const MIXED = PinMark.Reachable | PinMark.Unreachable;
const MIXED_LABEL = "Plusieurs couleurs";
const MIXED_MEANING = "Le lieu a plusieurs checks : chaque couleur en montre une partie.";

function Swatch({ background }: { background: string }) {
  return <span aria-hidden="true" className="inline-block size-3.5 shrink-0 rounded-[3px] border border-background shadow-[0_0_0_1px_var(--color-border)]" style={{ background }} />;
}

export function MapLegend({ settings }: { settings: TrackerSettings }) {
  const [open, setOpen] = useState(false);
  const levels = LEGEND_ORDER.filter((level) => settings.showSequenceBreaks || level !== AccessibilityLevel.SequenceBreak);
  const swatch = (level: AccessibilityLevel) => `var(--color-tracker-${TOKENS[level]})`;
  return (
    <div className="grid gap-2 text-xs">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
        <ul aria-label="Légende de la carte" className="flex flex-wrap items-center gap-x-3 gap-y-1.5">
          {levels.map((level) => (
            <li className="inline-flex items-center gap-1.5 text-foreground" key={level}>
              <Swatch background={swatch(level)} />
              {LEVEL_STYLES[level].label}
            </li>
          ))}
          <li className="inline-flex items-center gap-1.5 text-foreground">
            <Swatch background={pinBackground(MIXED)} />
            {MIXED_LABEL}
          </li>
        </ul>
        <button
          aria-expanded={open}
          className="inline-flex min-h-8 items-center gap-1 rounded-md px-1.5 text-muted-foreground hover:text-foreground"
          onClick={() => setOpen((current) => !current)}
          type="button"
        >
          <CircleHelp aria-hidden="true" className="size-3.5" />
          Que veulent dire les couleurs ?
          <ChevronDown aria-hidden="true" className={`size-3.5 transition-transform ${open ? "rotate-180" : ""}`} />
        </button>
      </div>
      {open ? (
        <dl className="grid gap-x-4 gap-y-1.5 rounded-md border border-border bg-surface p-3 sm:grid-cols-2">
          {levels.map((level) => (
            <div className="flex items-start gap-2" key={level}>
              <span className="mt-0.5">
                <Swatch background={swatch(level)} />
              </span>
              <div>
                <dt className="inline font-semibold text-foreground">{LEVEL_STYLES[level].label} : </dt>
                <dd className="inline text-muted-foreground">{MEANINGS[level]}</dd>
              </div>
            </div>
          ))}
          <div className="flex items-start gap-2">
            <span className="mt-0.5">
              <Swatch background={pinBackground(MIXED)} />
            </span>
            <div>
              <dt className="inline font-semibold text-foreground">{MIXED_LABEL} : </dt>
              <dd className="inline text-muted-foreground">{MIXED_MEANING}</dd>
            </div>
          </div>
        </dl>
      ) : null}
    </div>
  );
}

const TOKENS: Record<AccessibilityLevel, string> = {
  [AccessibilityLevel.None]: "none",
  [AccessibilityLevel.Partial]: "partial",
  [AccessibilityLevel.Inspect]: "inspect",
  [AccessibilityLevel.SequenceBreak]: "sequence",
  [AccessibilityLevel.Normal]: "normal",
  [AccessibilityLevel.Cleared]: "cleared",
};
