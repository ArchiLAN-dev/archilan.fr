"use client";

import { CircleHelp, X } from "lucide-react";
import { useState } from "react";

import { PIN_CATEGORIES, PinMark, pinBackground, type PinCategory } from "./tracker-view";

/**
 * Story 54.9: the legend of the map, as counters of the map shown (« 18 faisables », « 73 pas encore »). A counter is
 * also a filter: pressed, the map shows only those pins. What a colour means shows on hover (or a long press), and the
 * « ? » unfolds every meaning at once.
 */
type Category = { label: (count: number) => string; name: string; meaning: string; swatch: string };

const plural = (count: number, one: string, many: string) => `${count} ${count > 1 ? many : one}`;

export const CATEGORIES: Record<PinCategory, Category> = {
  reachable: {
    name: "Faisable",
    label: (count) => plural(count, "faisable", "faisables"),
    meaning: "Tu as ce qu'il faut pour y aller.",
    swatch: pinBackground(PinMark.Reachable),
  },
  sequence: {
    name: "Hors logique",
    label: (count) => `${count} hors logique`,
    meaning: "Possible avec une astuce que la logique du jeu ne prévoit pas.",
    swatch: pinBackground(PinMark.SequenceBreak),
  },
  inspect: {
    name: "À regarder",
    label: (count) => `${count} à regarder`,
    meaning: "Tu peux voir ce qu'il y a, sans pouvoir le prendre pour l'instant.",
    swatch: pinBackground(PinMark.Inspect),
  },
  mixed: {
    name: "En partie",
    label: (count) => `${count} en partie`,
    meaning: "Le lieu a plusieurs checks dans des états différents : chaque couleur de la case en montre une partie.",
    swatch: pinBackground(PinMark.Reachable | PinMark.Unreachable),
  },
  unreachable: {
    name: "Pas encore",
    label: (count) => `${count} pas encore`,
    meaning: "Il te manque encore quelque chose.",
    swatch: pinBackground(PinMark.Unreachable),
  },
  done: {
    name: "Fait",
    label: (count) => plural(count, "fait", "faits"),
    meaning: "Tout est ramassé.",
    swatch: pinBackground(0),
  },
};

function Swatch({ background }: { background: string }) {
  return (
    <span
      aria-hidden="true"
      className="inline-block size-3.5 shrink-0 rounded-[3px] border border-background shadow-[0_0_0_1px_var(--color-border)]"
      style={{ background }}
    />
  );
}

type Props = {
  counts: Record<PinCategory, number>;
  /** The categories the map is narrowed to; empty: every pin shows. */
  only: ReadonlySet<PinCategory>;
  onOnly: (only: ReadonlySet<PinCategory>) => void;
};

export function MapLegend({ counts, only, onOnly }: Props) {
  const [explained, setExplained] = useState(false);
  const shown = PIN_CATEGORIES.filter((category) => counts[category] > 0 || only.has(category));

  function toggle(category: PinCategory) {
    const next = new Set(only);
    if (next.has(category)) next.delete(category);
    else next.add(category);
    onOnly(next);
  }

  return (
    <div className="grid gap-2 text-xs">
      <ul aria-label="Légende de la carte" className="flex flex-wrap items-center gap-1.5">
          {shown.map((category) => {
            const { label, meaning, name, swatch } = CATEGORIES[category];
            const pressed = only.has(category);
            return (
              <li key={category}>
                <button
                  aria-label={`${label(counts[category])} : ${meaning}`}
                  aria-pressed={pressed}
                  className={`inline-flex min-h-8 items-center gap-1.5 rounded-full border px-2.5 font-semibold transition-colors ${
                    pressed
                      ? "border-accent-text/70 bg-accent/20 text-foreground"
                      : only.size > 0
                        ? "border-border text-muted-foreground hover:text-foreground"
                        : "border-border text-foreground hover:border-accent/60"
                  }`}
                  onClick={() => toggle(category)}
                  title={`${name} : ${meaning} Clique pour ne montrer que ces lieux.`}
                  type="button"
                >
                  <Swatch background={swatch} />
                  {label(counts[category])}
                </button>
              </li>
            );
          })}
        {only.size > 0 ? (
          <li>
          <button
            className="inline-flex min-h-8 items-center gap-1 rounded-full px-2 text-muted-foreground hover:text-foreground"
            onClick={() => onOnly(new Set())}
            type="button"
          >
            <X aria-hidden="true" className="size-3.5" />
            Tout afficher
          </button>
          </li>
        ) : null}
        <li>
        <button
          aria-expanded={explained}
          aria-label="Que veulent dire les couleurs ?"
          className="inline-flex size-8 items-center justify-center rounded-full text-muted-foreground hover:text-foreground"
          onClick={() => setExplained((current) => !current)}
          title="Que veulent dire les couleurs ?"
          type="button"
        >
          <CircleHelp aria-hidden="true" className="size-4" />
        </button>
        </li>
      </ul>
      {explained ? (
        <dl className="grid gap-x-4 gap-y-1.5 rounded-md border border-border bg-surface p-3 sm:grid-cols-2">
          {PIN_CATEGORIES.map((category) => (
            <div className="flex items-start gap-2" key={category}>
              <span className="mt-0.5">
                <Swatch background={CATEGORIES[category].swatch} />
              </span>
              <div>
                <dt className="inline font-semibold text-foreground">{CATEGORIES[category].name} : </dt>
                <dd className="inline text-muted-foreground">{CATEGORIES[category].meaning}</dd>
              </div>
            </div>
          ))}
          <p className="text-muted-foreground sm:col-span-2">Clique sur un compteur pour ne montrer que ces lieux sur la carte.</p>
        </dl>
      ) : null}
    </div>
  );
}
