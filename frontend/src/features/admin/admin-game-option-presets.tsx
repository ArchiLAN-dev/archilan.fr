"use client";

import { useMemo, useRef, useState } from "react";
import { Plus, Trash2, X } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { parseDefaultYaml, type ChoiceOption, type OptionPreset, type RangeOption, type ToggleOption } from "@/lib/archipelago-yaml";
import { saveOptionPresets, type AdminGame } from "./admin-games-api";

type PresettableOption = ToggleOption | ChoiceOption | RangeOption;

type Props = {
  game: AdminGame;
  onUpdate: (game: AdminGame) => void;
};

const MAX_PRESETS = 3;
const FIELD = "min-h-9 rounded border border-border bg-background px-2 text-sm text-foreground";

/** Story 56.5: the options a preset can set - toggles, choices and ranges, as the player's editor shows them. */
export function presettableOptions(game: Pick<AdminGame, "defaultYaml" | "optionTypes">): PresettableOption[] {
  const parsed = parseDefaultYaml(game.defaultYaml ?? "", game.optionTypes ?? null);
  if (!parsed) return [];
  return parsed.options.filter((o): o is PresettableOption => o.type === "toggle" || o.type === "choice" || o.type === "range");
}

/** The value a newly added option starts at: the default's most likely roll. */
export function startingValue(option: PresettableOption): number | string | boolean {
  if (option.type === "toggle") return option.weightTrue >= option.weightFalse;
  if (option.type === "choice") {
    const best = [...option.choices].sort((a, b) => b.weight - a.weight)[0];
    return best?.value ?? "";
  }
  const numeric = option.entries.map((e) => Number(e.key)).find((n) => Number.isInteger(n) && n >= option.min && n <= option.max);
  return numeric ?? option.min;
}

/**
 * Story 56.5: up to three named formats (short, medium, long) for a game. Each sets a few option values; a player
 * applies one to their YAML in one click and can change any value after.
 */
export function OptionPresetsCard({ game, onUpdate }: Props) {
  const options = useMemo(() => presettableOptions(game), [game]);
  const byKey = useMemo(() => new Map(options.map((o) => [o.key, o])), [options]);
  // A row id that survives a rename or a removal above it (never the index).
  const nextId = useRef(0);
  const [rows, setRows] = useState<{ id: number; preset: OptionPreset }[]>(() =>
    (game.optionPresets ?? []).map((preset, i) => ({ id: -1 - i, preset })),
  );
  const presets = rows.map((row) => row.preset);
  const [message, setMessage] = useState<{ kind: "ok" | "error"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  if (options.length === 0) return null;

  function update(id: number, preset: OptionPreset) {
    setRows((all) => all.map((row) => (row.id === id ? { id, preset } : row)));
    setMessage(null);
  }

  function addPreset() {
    nextId.current += 1;
    const id = nextId.current;
    setRows((all) => [...all, { id, preset: { name: ["Court", "Moyen", "Long"][all.length] ?? "Format", values: {} } }]);
  }

  async function save() {
    setBusy(true);
    const result = await saveOptionPresets(game.id, presets);
    setBusy(false);
    if (result.kind === "saved") {
      onUpdate(result.game);
      setMessage({ kind: "ok", text: "Préréglages enregistrés." });
    } else {
      setMessage({ kind: "error", text: result.message });
    }
  }

  return (
    <section aria-labelledby="option-presets-title" className="grid min-w-0 gap-4 rounded-lg border border-border bg-surface p-5 sm:p-6">
      <header className="grid gap-1">
        <h2 className="font-heading text-lg font-semibold text-foreground" id="option-presets-title">
          Formats tout prêts
        </h2>
        <p className="text-sm text-muted-foreground">
          Jusqu&apos;à trois formats (court, moyen, long) que le joueur applique à son YAML en un clic, pour venir avec un
          monde à la bonne taille.
        </p>
      </header>

      {rows.map((row) => (
        <PresetEditor
          byKey={byKey}
          key={row.id}
          onChange={(next) => update(row.id, next)}
          onRemove={() => setRows((all) => all.filter((other) => other.id !== row.id))}
          options={options}
          preset={row.preset}
        />
      ))}

      <div className="flex flex-wrap items-center gap-2">
        {presets.length < MAX_PRESETS ? (
          <button
            className={buttonVariants({ variant: "secondary" })}
            onClick={addPreset}
            type="button"
          >
            <Plus aria-hidden className="size-4" /> Ajouter un format
          </button>
        ) : null}
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

type EditorProps = {
  preset: OptionPreset;
  options: PresettableOption[];
  byKey: Map<string, PresettableOption>;
  onChange: (preset: OptionPreset) => void;
  onRemove: () => void;
};

function PresetEditor({ preset, options, byKey, onChange, onRemove }: EditorProps) {
  const unused = options.filter((o) => !(o.key in preset.values));

  function setValue(key: string, value: number | string | boolean) {
    onChange({ ...preset, values: { ...preset.values, [key]: value } });
  }

  function removeValue(key: string) {
    const values = { ...preset.values };
    delete values[key];
    onChange({ ...preset, values });
  }

  return (
    <div className="grid gap-3 rounded-md border border-border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <input
          aria-label="Nom du format"
          className={`${FIELD} min-w-0 flex-1 font-semibold`}
          maxLength={40}
          onChange={(event) => onChange({ ...preset, name: event.target.value })}
          value={preset.name}
        />
        <button aria-label={`Retirer le format ${preset.name}`} className={buttonVariants({ variant: "ghost" })} onClick={onRemove} type="button">
          <Trash2 aria-hidden className="size-4" />
        </button>
      </div>
      <ul className="grid gap-2">
        {Object.entries(preset.values).map(([key, value]) => {
          const option = byKey.get(key);
          return (
            <li className="flex flex-wrap items-center gap-2 text-sm" key={key}>
              <span className="min-w-40 text-foreground">{option?.label ?? key}</span>
              {option ? <ValueField onChange={(v) => setValue(key, v)} option={option} value={value} /> : <span className="text-danger">option disparue</span>}
              <button aria-label={`Retirer ${option?.label ?? key}`} className="text-muted-foreground hover:text-foreground" onClick={() => removeValue(key)} type="button">
                <X aria-hidden className="size-4" />
              </button>
            </li>
          );
        })}
      </ul>
      {unused.length > 0 ? (
        <select
          aria-label="Ajouter une option au format"
          className={`${FIELD} w-fit`}
          onChange={(event) => {
            const option = byKey.get(event.target.value);
            if (option) setValue(option.key, startingValue(option));
          }}
          value=""
        >
          <option value="">Ajouter une option…</option>
          {unused.map((o) => (
            <option key={o.key} value={o.key}>
              {o.label}
            </option>
          ))}
        </select>
      ) : null}
    </div>
  );
}

function ValueField({ option, value, onChange }: { option: PresettableOption; value: number | string | boolean; onChange: (value: number | string | boolean) => void }) {
  if (option.type === "toggle") {
    return (
      <select aria-label={option.label} className={FIELD} onChange={(event) => onChange(event.target.value === "true")} value={String(value === true)}>
        <option value="true">Oui</option>
        <option value="false">Non</option>
      </select>
    );
  }
  if (option.type === "choice") {
    return (
      <select aria-label={option.label} className={FIELD} onChange={(event) => onChange(event.target.value)} value={String(value)}>
        {option.choices.map((c) => (
          <option key={c.value} value={c.value}>
            {c.value}
          </option>
        ))}
      </select>
    );
  }
  return (
    <input
      aria-label={option.label}
      className={`${FIELD} w-24`}
      max={option.max}
      min={option.min}
      onChange={(event) => onChange(Number.parseInt(event.target.value, 10) || option.min)}
      type="number"
      value={typeof value === "number" ? value : option.min}
    />
  );
}
