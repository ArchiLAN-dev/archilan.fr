"use client";

import { useMemo, useState } from "react";

import { buttonVariants } from "@/components/ui/button";
import { parseDefaultYaml, type RangeOption } from "@/lib/archipelago-yaml";
import { saveSizeOptions, type AdminGame } from "./admin-games-api";

type Props = {
  game: AdminGame;
  onUpdate: (game: AdminGame) => void;
};

/** Story 56.7: the numeric options of a game, the only ones a size search can change. */
export function rangeOptions(game: Pick<AdminGame, "defaultYaml" | "optionTypes">): RangeOption[] {
  const parsed = parseDefaultYaml(game.defaultYaml ?? "", game.optionTypes ?? null);
  if (!parsed) return [];
  return parsed.options.filter((o): o is RangeOption => o.type === "range");
}

/** The ticked options in the order the search tries them: the order of the list. */
export function orderedSelection(options: RangeOption[], selected: ReadonlySet<string>): string[] {
  return options.filter((o) => selected.has(o.key)).map((o) => o.key);
}

/**
 * Story 56.7: which options change the size of the world (a number of tokens, of goals...). A player whose world goes
 * past the target can ask for a value of one of them that fits it.
 */
export function SizeOptionsCard({ game, onUpdate }: Props) {
  const options = useMemo(() => rangeOptions(game), [game]);
  const [selected, setSelected] = useState<Set<string>>(() => new Set(game.sizeOptions ?? []));
  const [message, setMessage] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  if (options.length === 0) return null;

  function toggle(key: string) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
    setMessage(null);
  }

  async function save() {
    setBusy(true);
    const result = await saveSizeOptions(game.id, orderedSelection(options, selected));
    setBusy(false);
    if (result.kind === "saved") {
      onUpdate(result.game);
      setMessage({ kind: "ok", text: "Options de taille enregistrées." });
    } else {
      setMessage({ kind: "error", text: result.message });
    }
  }

  return (
    <section aria-labelledby="size-options-title" className="grid min-w-0 gap-4 rounded-lg border border-border bg-surface p-5 sm:p-6">
      <header className="grid gap-1">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="size-options-title">
          Options de taille
        </h2>
        <p className="text-sm text-muted-foreground">
          Les options numériques qui changent la taille du monde. Un joueur dont le monde dépasse la durée cible peut
          demander une valeur de l&apos;une d&apos;elles qui la respecte (cinq au plus, essayées dans l&apos;ordre de la liste).
        </p>
      </header>
      <ul className="grid gap-1.5">
        {options.map((option) => (
          <li key={option.key}>
            <label className="flex items-center gap-2 text-sm text-foreground">
              <input checked={selected.has(option.key)} className="size-4" onChange={() => toggle(option.key)} type="checkbox" />
              {option.label}
              <span className="text-xs text-muted-foreground">
                ({option.min} à {option.max})
              </span>
            </label>
          </li>
        ))}
      </ul>
      <div className="flex flex-wrap items-center gap-2">
        <button className={buttonVariants({ variant: "primary" })} disabled={busy} onClick={() => void save()} type="button">
          Enregistrer
        </button>
        {message !== null ? (
          <span className={message.kind === "ok" ? "text-sm text-success" : "text-sm text-danger"} role="status">
            {message.text}
          </span>
        ) : null}
      </div>
    </section>
  );
}
