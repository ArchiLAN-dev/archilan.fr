"use client";

import { useEffect, useRef, useState } from "react";
import { AlertCircle, CheckCircle, ChevronDown, ChevronUp, ChevronsDownUp, ChevronsUpDown, Download, Info, Plus, X } from "lucide-react";

import {
  RANDOM_ALIASES,
  addCustomRangeEntry,
  createRangeEntry,
  labelFromAlias,
  labelFromKey,
  mergePlayerValues,
  parseDefaultYaml,
  serializeToYaml,
  type ChoiceOption,
  type FreeformDictEntry,
  type FreeformDictOption,
  type FreeformListOption,
  type GameOption,
  type ParsedYaml,
  type RangeOption,
  type ToggleOption,
} from "@/lib/archipelago-yaml";
import { env } from "@/lib/env";

// ─── Types ────────────────────────────────────────────────────────────────────

type Mode = "simple" | "advanced";

type PanelSave =
  | { kind: "idle" }
  | { kind: "saving" }
  | { kind: "saved" }
  | { kind: "error"; message: string };

// ─── Main component ───────────────────────────────────────────────────────────

export function YamlOptionEditor({
  defaultYaml,
  playerYaml,
  registrationId,
  registrationOpen,
  slotId,
  onDirty,
  onSaved,
}: {
  defaultYaml: string | null;
  playerYaml: string | null;
  registrationId: string;
  registrationOpen: boolean;
  slotId: string;
  onDirty: (slotId: string) => void;
  onSaved: (slotId: string) => void;
}) {
  const [parsed, setParsed] = useState<ParsedYaml | null>(() => {
    const base = parseDefaultYaml(defaultYaml ?? "");
    if (!base) return null;
    if (!playerYaml) return base;
    const player = parseDefaultYaml(playerYaml);
    return player ? mergePlayerValues(base, player) : base;
  });
  const [rawYaml, setRawYaml] = useState(playerYaml ?? defaultYaml ?? "");
  const [mode, setMode] = useState<Mode>("simple");
  const [panelSave, setPanelSave] = useState<PanelSave>({ kind: "idle" });
  const [nameError, setNameError] = useState(false);

  const [openCategories, setOpenCategories] = useState<Set<string>>(() => {
    const base = parseDefaultYaml(defaultYaml ?? "");
    if (!base) return new Set();
    const firstKey = groupByCategory(base.options)
      .map((s, i) => s.category ?? `__${i}`)
      .at(0);
    return firstKey ? new Set([firstKey]) : new Set();
  });

  function markDirty() {
    onDirty(slotId);
    setPanelSave({ kind: "idle" });
  }

  function updateOption(updated: GameOption) {
    setParsed((p) =>
      p ? { ...p, options: p.options.map((o) => (o.key === updated.key ? updated : o)) } : p,
    );
    markDirty();
  }

  const sections = parsed ? groupByCategory(parsed.options) : [];
  const sectionKeys = sections.map((s, i) => s.category ?? `__${i}`);
  const allOpen = sectionKeys.length > 0 && sectionKeys.every((k) => openCategories.has(k));

  function toggleAll() {
    setOpenCategories(allOpen ? new Set() : new Set(sectionKeys));
  }

  function handleExport() {
    const content = parsed ? serializeToYaml(parsed) : rawYaml;
    const filename = `${parsed?.playerName ?? "player"}.yaml`.replace(/[^\w\-. ]/g, "_");
    const blob = new Blob([content], { type: "text/yaml" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
  }

  async function handleSave() {
    if (parsed) {
      const trimmedName = parsed.playerName.trim();
      if (!trimmedName) {
        setNameError(true);
        return;
      }
      if (trimmedName !== parsed.playerName) {
        setParsed((p) => (p ? { ...p, playerName: trimmedName } : p));
      }
    }
    const yamlToSave = parsed ? serializeToYaml({ ...parsed, playerName: parsed.playerName.trim() }) : rawYaml;
    setPanelSave({ kind: "saving" });
    try {
      const res = await fetch(
        `${env.apiBaseUrl}/registrations/${registrationId}/slots/${slotId}/yaml`,
        {
          body: JSON.stringify({ playerYaml: yamlToSave }),
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          method: "PUT",
        },
      );
      if (!res.ok) {
        setPanelSave({ kind: "error", message: "Impossible de sauvegarder la configuration." });
        return;
      }
      onSaved(slotId);
      setPanelSave({ kind: "saved" });
    } catch {
      setPanelSave({ kind: "error", message: "Impossible de contacter l'API." });
    }
  }

  return (
    <div className="card-glow rounded-lg border border-border p-4 sm:p-5">
      {parsed ? (
        <>
          <div className="grid gap-2">
            <div className="grid gap-2 sm:flex sm:items-center sm:gap-3">
              <label className="flex min-w-0 flex-1 items-center gap-2 text-sm font-semibold text-foreground">
                <span className="shrink-0">Nom en jeu</span>
                {parsed.playerNameDescription ? (
                  <InfoTooltip content={parsed.playerNameDescription} />
                ) : null}
                <input
                  aria-invalid={nameError}
                  className={`min-h-9 min-w-0 flex-1 rounded border bg-background px-3 text-sm font-normal text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60 ${nameError ? "border-danger" : "border-border"}`}
                  disabled={!registrationOpen}
                  maxLength={50}
                  value={parsed.playerName}
                  onBlur={(e) => {
                    const trimmed = e.target.value.trim();
                    setParsed((p) => (p ? { ...p, playerName: trimmed } : p));
                    if (!trimmed) setNameError(true);
                  }}
                  onChange={(e) => {
                    setParsed((p) => (p ? { ...p, playerName: e.target.value } : p));
                    if (e.target.value.trim()) setNameError(false);
                    markDirty();
                  }}
                />
              </label>

              <div className="flex shrink-0 items-center justify-between gap-2 sm:justify-start">
                <div className="flex gap-0.5 rounded border border-border p-0.5">
                  <ModeButton active={mode === "simple"} onClick={() => setMode("simple")}>
                    Simple
                  </ModeButton>
                  <ModeButton active={mode === "advanced"} onClick={() => setMode("advanced")}>
                    Avancé
                  </ModeButton>
                </div>
                {sectionKeys.length > 0 ? (
                  <>
                    <div aria-hidden="true" className="hidden h-4 w-px bg-border sm:block" />
                    <button
                      className="inline-flex cursor-pointer items-center gap-1.5 rounded border border-border px-2.5 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-surface hover:text-foreground sm:border-transparent sm:px-1 sm:py-1"
                      type="button"
                      onClick={toggleAll}
                    >
                      {allOpen ? (
                        <ChevronsDownUp aria-hidden="true" className="size-4" />
                      ) : (
                        <ChevronsUpDown aria-hidden="true" className="size-4" />
                      )}
                      <span className="sm:hidden">{allOpen ? "Tout fermer" : "Tout ouvrir"}</span>
                      <span className="sr-only">{allOpen ? "Tout fermer" : "Tout ouvrir"}</span>
                    </button>
                  </>
                ) : null}
              </div>
            </div>
            {nameError ? (
              <p className="text-xs text-danger" role="alert">Le nom en jeu ne peut pas être vide.</p>
            ) : (
              <p className="text-xs text-muted-foreground">Ce nom sera validé par Archipelago au moment de la génération.</p>
            )}
          </div>

          <div className="mt-4 grid gap-5">
            {sections.map((section, i) => {
            const key = sectionKeys[i];
            return (
              <CategoryAccordion
                key={key}
                label={section.category ?? "Général"}
                open={openCategories.has(key)}
                onToggle={() =>
                  setOpenCategories((prev) => {
                    const next = new Set(prev);
                    if (next.has(key)) next.delete(key);
                    else next.add(key);
                    return next;
                  })
                }
              >
                {section.options.map((opt) => (
                  <OptionField
                    key={opt.key}
                    mode={mode}
                    option={opt}
                    readOnly={!registrationOpen}
                    onChange={updateOption}
                  />
                ))}
              </CategoryAccordion>
            );
          })}
          </div>
        </>
      ) : (
        <div className="mt-4 grid gap-2">
          <p className="text-xs text-muted-foreground">
            Le YAML de ce jeu n&apos;a pas pu être analysé automatiquement. Modifiez-le directement.
          </p>
          <textarea
            className="min-h-48 w-full rounded border border-border bg-background px-3 py-2 font-mono text-xs text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={!registrationOpen}
            value={rawYaml}
            onChange={(e) => {
              setRawYaml(e.target.value);
              markDirty();
            }}
          />
        </div>
      )}

      <div className="mt-5 grid gap-2">
        {panelSave.kind === "saved" ? (
          <span className="flex items-center gap-1.5 text-sm text-success">
            <CheckCircle aria-hidden="true" className="size-4 shrink-0" />
            Sauvegardé
          </span>
        ) : null}
        {panelSave.kind === "error" ? (
          <span className="flex items-center gap-1.5 text-sm text-danger">
            <AlertCircle aria-hidden="true" className="size-4 shrink-0" />
            {panelSave.message}
          </span>
        ) : null}

        <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:gap-3">
          {registrationOpen ? (
            <button
              className="inline-flex min-h-11 w-full items-center justify-center rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto"
              disabled={panelSave.kind === "saving"}
              type="button"
              onClick={() => { void handleSave(); }}
            >
              {panelSave.kind === "saving" ? "Sauvegarde…" : "Sauvegarder"}
            </button>
          ) : null}
          <button
            className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent sm:w-auto"
            type="button"
            onClick={handleExport}
          >
            <Download aria-hidden="true" className="size-4" />
            Exporter en YAML
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Mode toggle button ───────────────────────────────────────────────────────

function ModeButton({
  active,
  children,
  onClick,
}: {
  active: boolean;
  children: React.ReactNode;
  onClick: () => void;
}) {
  return (
    <button
      className={`rounded px-3 py-1 text-xs font-semibold transition-colors ${
        active ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"
      }`}
      type="button"
      onClick={onClick}
    >
      {children}
    </button>
  );
}

// ─── Category grouping ────────────────────────────────────────────────────────

type OptionSection = { category: string | null; options: GameOption[] };

function groupByCategory(options: GameOption[]): OptionSection[] {
  const sections: OptionSection[] = [];
  for (const opt of options) {
    const cat = opt.category ?? null;
    const last = sections[sections.length - 1];
    if (!last || last.category !== cat) {
      sections.push({ category: cat, options: [opt] });
    } else {
      last.options.push(opt);
    }
  }
  return sections;
}

function CategoryAccordion({
  label,
  open,
  onToggle,
  children,
}: {
  label: string;
  open: boolean;
  onToggle: () => void;
  children: React.ReactNode;
}) {
  return (
    <div className="rounded-lg border border-border">
      <button
        className={`flex w-full cursor-pointer items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-surface rounded-t-lg ${open ? "" : "rounded-b-lg"}`}
        type="button"
        onClick={onToggle}
      >
        <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
          {label}
        </span>
        <ChevronDown
          aria-hidden="true"
          className={`size-4 shrink-0 text-muted-foreground transition-transform duration-200 ${open ? "" : "-rotate-90"}`}
        />
      </button>
      {open ? (
        <div className="divide-y divide-border rounded-b-lg border-t border-border px-4">
          {children}
        </div>
      ) : null}
    </div>
  );
}

// ─── Mini markdown renderer ───────────────────────────────────────────────────

function renderInline(text: string): React.ReactNode[] {
  const parts = text.split(/(\*\*[^*]+\*\*|\*[^*]+\*|`[^`]+`)/);
  return parts.map((part, i) => {
    if (part.startsWith("**") && part.endsWith("**"))
      return <strong key={i}>{part.slice(2, -2)}</strong>;
    if (part.startsWith("*") && part.endsWith("*"))
      return <em key={i}>{part.slice(1, -1)}</em>;
    if (part.startsWith("`") && part.endsWith("`"))
      return (
        <code key={i} className="rounded bg-surface px-1 font-mono text-[10px] text-accent-text">
          {part.slice(1, -1)}
        </code>
      );
    return part;
  });
}

function MiniMarkdown({ content }: { content: string }) {
  const lines = content.split("\n").filter((l) => l.trim() !== "");
  return (
    <div className="grid gap-1">
      {lines.map((line, i) => {
        const trimmed = line.trim();
        if (trimmed.startsWith("- ") || trimmed.startsWith("• ")) {
          return (
            <div key={i} className="flex gap-1.5">
              <span className="mt-px shrink-0 text-accent-text">•</span>
              <span>{renderInline(trimmed.slice(2))}</span>
            </div>
          );
        }
        return <p key={i}>{renderInline(trimmed)}</p>;
      })}
    </div>
  );
}

// ─── Info tooltip ─────────────────────────────────────────────────────────────

function InfoTooltip({ content }: { content: string }) {
  const [open, setOpen] = useState(false);
  const justFocused = useRef(false);
  return (
    <span className="relative inline-flex shrink-0">
      <button
        aria-label="Description de l'option"
        className="inline-flex cursor-help rounded focus-visible:outline-2 focus-visible:outline-accent"
        type="button"
        onBlur={() => setOpen(false)}
        onFocus={() => { setOpen(true); justFocused.current = true; }}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => setOpen(false)}
        onClick={() => {
          if (justFocused.current) { justFocused.current = false; return; }
          setOpen((v) => !v);
        }}
      >
        <Info aria-hidden="true" className="size-3.5 text-muted-foreground transition-colors hover:text-accent-text" />
      </button>
      {open ? (
        <span
          role="tooltip"
          className="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-72 -translate-x-1/2 rounded-lg border border-border bg-surface-2 px-3.5 py-3 text-xs leading-relaxed text-foreground shadow-[0_8px_32px_rgba(0,0,0,0.5)]"
        >
          <MiniMarkdown content={content} />
          <span
            aria-hidden="true"
            className="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-[var(--color-border)]"
          />
        </span>
      ) : null}
    </span>
  );
}

// ─── Option field dispatcher ──────────────────────────────────────────────────

function OptionField({
  mode,
  option,
  readOnly,
  onChange,
}: {
  mode: Mode;
  option: GameOption;
  readOnly: boolean;
  onChange: (updated: GameOption) => void;
}) {
  return (
    <div className="grid gap-2 py-5">
      <div className="flex items-center gap-1.5">
        <p className="break-words text-base font-semibold text-foreground">{option.label}</p>
        {option.description ? <InfoTooltip content={option.description} /> : null}
      </div>
      {option.type === "freeform" && option.kind === "list" && (
        <ListField option={option} readOnly={readOnly} onChange={onChange} />
      )}
      {option.type === "freeform" && option.kind === "dict" && (
        <DictField option={option} readOnly={readOnly} onChange={onChange} />
      )}
      {option.type === "text" && (
        <input
          className="min-h-9 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
          disabled={readOnly}
          value={option.value}
          onChange={(e) => onChange({ ...option, value: e.target.value })}
        />
      )}
      {option.type === "toggle" &&
        (mode === "simple" ? (
          <SimpleToggle option={option} readOnly={readOnly} onChange={onChange} />
        ) : (
          <AdvancedToggle option={option} readOnly={readOnly} onChange={onChange} />
        ))}
      {option.type === "choice" &&
        (mode === "simple" ? (
          <SimpleChoice option={option} readOnly={readOnly} onChange={onChange} />
        ) : (
          <AdvancedChoice option={option} readOnly={readOnly} onChange={onChange} />
        ))}
      {option.type === "range" && (
        <AdvancedRange option={option} readOnly={readOnly} onChange={onChange} />
      )}
    </div>
  );
}

// ─── Toggle fields ────────────────────────────────────────────────────────────

function SimpleToggle({
  onChange,
  option,
  readOnly,
}: {
  onChange: (o: ToggleOption) => void;
  option: ToggleOption;
  readOnly: boolean;
}) {
  const isTrue = option.weightTrue > option.weightFalse;
  return (
    <div className="flex w-fit gap-0.5 rounded border border-border p-0.5">
      {([false, true] as const).map((val) => {
        const active = val ? isTrue : !isTrue;
        return (
          <button
            key={String(val)}
            className={`cursor-pointer rounded px-4 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
              active ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"
            }`}
            disabled={readOnly}
            type="button"
            onClick={() =>
              onChange({
                ...option,
                weightFalse: val ? 0 : 50,
                weightTrue: val ? 50 : 0,
              })
            }
          >
            {val ? "Oui" : "Non"}
          </button>
        );
      })}
    </div>
  );
}

function AdvancedToggle({
  onChange,
  option,
  readOnly,
}: {
  onChange: (o: ToggleOption) => void;
  option: ToggleOption;
  readOnly: boolean;
}) {
  const total = option.weightFalse + option.weightTrue;
  return (
    <div className="grid gap-2">
      <WeightRow
        label="Non"
        readOnly={readOnly}
        total={total}
        weight={option.weightFalse}
        onChange={(w) => onChange({ ...option, weightFalse: w })}
      />
      <WeightRow
        label="Oui"
        readOnly={readOnly}
        total={total}
        weight={option.weightTrue}
        onChange={(w) => onChange({ ...option, weightTrue: w })}
      />
    </div>
  );
}

// ─── Choice fields ────────────────────────────────────────────────────────────

function SimpleChoice({
  onChange,
  option,
  readOnly,
}: {
  onChange: (o: ChoiceOption) => void;
  option: ChoiceOption;
  readOnly: boolean;
}) {
  if (option.choices.length === 0) return null;
  const best = option.choices.reduce(
    (b, c) => (c.weight > b.weight ? c : b),
    option.choices[0],
  );
  return (
    <select
      className="min-h-9 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
      disabled={readOnly}
      value={best.value}
      onChange={(e) => {
        const v = e.target.value;
        onChange({
          ...option,
          choices: option.choices.map((c) => ({ ...c, weight: c.value === v ? 50 : 0 })),
        });
      }}
    >
      {option.choices.map((c) => (
        <option key={c.value} value={c.value}>
          {labelFromKey(c.value)}
        </option>
      ))}
    </select>
  );
}

function AdvancedChoice({
  onChange,
  option,
  readOnly,
}: {
  onChange: (o: ChoiceOption) => void;
  option: ChoiceOption;
  readOnly: boolean;
}) {
  const total = option.choices.reduce((s, c) => s + c.weight, 0);
  return (
    <div className="grid gap-2">
      {option.choices.map((c) => (
        <WeightRow
          key={c.value}
          description={c.description}
          label={labelFromAlias(c.value) ?? labelFromKey(c.value)}
          readOnly={readOnly}
          total={total}
          weight={c.weight}
          onChange={(w) =>
            onChange({
              ...option,
              choices: option.choices.map((oc) =>
                oc.value === c.value ? { ...oc, weight: w } : oc,
              ),
            })
          }
        />
      ))}
    </div>
  );
}

// ─── Range fields ─────────────────────────────────────────────────────────────

function AdvancedRange({
  onChange,
  option,
  readOnly,
}: {
  onChange: (o: RangeOption) => void;
  option: RangeOption;
  readOnly: boolean;
}) {
  const [newKey, setNewKey] = useState("");
  const total = option.entries.reduce((s, e) => s + e.weight, 0);

  const entryByKey = new Map(option.entries.map((e) => [e.key, e]));

  const aliasRows = RANDOM_ALIASES.map((r) => ({
    key: r.key,
    label: r.label,
    weight: entryByKey.get(r.key)?.weight ?? 0,
  }));

  const numericRows = option.entries.filter((e) => !isNaN(Number(e.key)));

  function setAliasWeight(key: string, weight: number) {
    const existing = option.entries.findIndex((e) => e.key === key);
    if (existing >= 0) {
      onChange({ ...option, entries: option.entries.map((e) => (e.key === key ? { ...e, weight } : e)) });
    } else {
      onChange({ ...option, entries: [...option.entries, createRangeEntry(key, weight, false)] });
    }
  }

  function addNumeric() {
    const n = parseInt(newKey, 10);
    if (isNaN(n)) return;
    const clamped = Math.max(option.min, Math.min(option.max, n));
    onChange(addCustomRangeEntry(option, String(clamped), 50));
    setNewKey("");
  }

  return (
    <div className="grid gap-4">
      <div className="grid gap-1">
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Valeurs aléatoires
        </p>
        <div className="grid gap-2">
          {aliasRows.map((row) => (
            <WeightRow
              key={row.key}
              description={entryByKey.get(row.key)?.description}
              label={row.label}
              readOnly={readOnly}
              total={total}
              weight={row.weight}
              onChange={(w) => setAliasWeight(row.key, w)}
            />
          ))}
        </div>
      </div>

      <div className="grid gap-1">
        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Valeurs numériques spécifiques
        </p>
        <div className="grid gap-2">
          {numericRows.map((entry) => (
            <div key={entry.id} className="flex items-center gap-2">
              <div className="min-w-0 flex-1">
              <WeightRow
                description={entry.description}
                label={`Valeur ${entry.key}`}
                readOnly={readOnly}
                total={total}
                weight={entry.weight}
                onChange={(w) =>
                  onChange({
                    ...option,
                    entries: option.entries.map((e) =>
                      e.id === entry.id ? { ...e, weight: w } : e,
                    ),
                  })
                }
              />
              </div>
              {!readOnly && (
                <button
                  aria-label={`Supprimer la valeur ${entry.key}`}
                  className="inline-flex size-6 shrink-0 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-danger hover:text-danger"
                  type="button"
                  onClick={() =>
                    onChange({ ...option, entries: option.entries.filter((e) => e.id !== entry.id) })
                  }
                >
                  <X aria-hidden="true" className="size-3.5" />
                </button>
              )}
            </div>
          ))}
        </div>

        {!readOnly && (
          <div className="mt-1 flex items-center gap-2">
            <NumberStepper
              disabled={false}
              max={option.max}
              min={option.min}
              value={newKey === "" ? option.min : Number(newKey)}
              onChange={(v) => setNewKey(String(v))}
            />
            <button
              className="inline-flex min-h-8 items-center justify-center rounded border border-border px-3 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
              disabled={newKey === ""}
              type="button"
              onClick={addNumeric}
            >
              Ajouter
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// ─── Freeform: list ──────────────────────────────────────────────────────────

const INPUT_CLS =
  "min-h-9 flex-1 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60";

const REMOVE_BTN_CLS =
  "inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-danger hover:text-danger";

const ADD_BTN_CLS =
  "inline-flex h-8 cursor-pointer items-center gap-1.5 rounded border border-dashed border-border px-3 text-xs text-muted-foreground transition-colors hover:border-accent hover:text-foreground";

function ListField({
  option,
  readOnly,
  onChange,
}: {
  option: FreeformListOption;
  readOnly: boolean;
  onChange: (o: FreeformListOption) => void;
}) {
  return (
    <div className="grid gap-2">
      {option.items.map((item, i) => (
        <div key={i} className="flex items-center gap-2">
          <input
            className={INPUT_CLS}
            disabled={readOnly}
            placeholder="élément"
            value={item}
            onChange={(e) => {
              const items = option.items.map((it, idx) => (idx === i ? e.target.value : it));
              onChange({ ...option, items });
            }}
          />
          {!readOnly && (
            <button
              aria-label="Supprimer"
              className={REMOVE_BTN_CLS}
              type="button"
              onClick={() => onChange({ ...option, items: option.items.filter((_, idx) => idx !== i) })}
            >
              <X aria-hidden="true" className="size-3.5" />
            </button>
          )}
        </div>
      ))}
      {!readOnly && (
        <button
          className={ADD_BTN_CLS}
          type="button"
          onClick={() => onChange({ ...option, items: [...option.items, ""] })}
        >
          <Plus aria-hidden="true" className="size-3.5" />
          Ajouter
        </button>
      )}
      {option.items.length === 0 && readOnly && (
        <p className="text-xs italic text-muted-foreground">Aucun élément</p>
      )}
    </div>
  );
}

// ─── Freeform: dict ───────────────────────────────────────────────────────────

function DictField({
  option,
  readOnly,
  onChange,
}: {
  option: FreeformDictOption;
  readOnly: boolean;
  onChange: (o: FreeformDictOption) => void;
}) {
  function update(id: string, field: keyof Omit<FreeformDictEntry, "id">, val: string) {
    onChange({
      ...option,
      entries: option.entries.map((e) => (e.id === id ? { ...e, [field]: val } : e)),
    });
  }

  return (
    <div className="grid gap-2">
      {option.entries.length > 0 && (
        <div className="flex items-center gap-2 text-xs text-muted-foreground">
          <span className="flex-1">Clé</span>
          <span className="w-24">Valeur</span>
          {!readOnly && <span className="size-7" />}
        </div>
      )}
      {option.entries.map((entry) => (
        <div key={entry.id} className="flex items-center gap-2">
          <input
            className={INPUT_CLS}
            disabled={readOnly}
            placeholder="élément"
            value={entry.k}
            onChange={(e) => update(entry.id, "k", e.target.value)}
          />
          <input
            className="min-h-9 w-24 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={readOnly}
            placeholder="0"
            value={entry.v}
            onChange={(e) => update(entry.id, "v", e.target.value)}
          />
          {!readOnly && (
            <button
              aria-label="Supprimer"
              className={REMOVE_BTN_CLS}
              type="button"
              onClick={() =>
                onChange({ ...option, entries: option.entries.filter((e) => e.id !== entry.id) })
              }
            >
              <X aria-hidden="true" className="size-3.5" />
            </button>
          )}
        </div>
      ))}
      {!readOnly && (
        <button
          className={ADD_BTN_CLS}
          type="button"
          onClick={() =>
            onChange({
              ...option,
              entries: [...option.entries, { id: crypto.randomUUID(), k: "", v: "" }],
            })
          }
        >
          <Plus aria-hidden="true" className="size-3.5" />
          Ajouter
        </button>
      )}
      {option.entries.length === 0 && readOnly && (
        <p className="text-xs italic text-muted-foreground">Aucune entrée</p>
      )}
    </div>
  );
}

// ─── Number stepper ──────────────────────────────────────────────────────────

function NumberStepper({
  disabled,
  max,
  min,
  onChange,
  value,
}: {
  disabled: boolean;
  max: number;
  min: number;
  onChange: (v: number) => void;
  value: number;
}) {
  const valueRef = useRef(value);
  useEffect(() => { valueRef.current = value; }, [value]);

  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  function stopPress() {
    if (timerRef.current) { clearTimeout(timerRef.current); timerRef.current = null; }
    if (intervalRef.current) { clearInterval(intervalRef.current); intervalRef.current = null; }
  }

  function startPress(delta: number) {
    const step = () => {
      const next = Math.max(min, Math.min(max, valueRef.current + delta));
      onChange(next);
    };
    step();
    timerRef.current = setTimeout(() => {
      intervalRef.current = setInterval(step, 50);
    }, 300);
  }

  useEffect(() => stopPress, []);

  return (
    <div className="flex overflow-hidden rounded border border-border bg-background focus-within:border-accent">
      <input
        className="w-10 bg-transparent py-1 text-center text-xs tabular-nums text-foreground outline-none disabled:cursor-not-allowed disabled:opacity-60 [appearance:textfield] [&::-webkit-inner-spin-button]:hidden [&::-webkit-outer-spin-button]:hidden"
        disabled={disabled}
        max={max}
        min={min}
        type="number"
        value={value}
        onChange={(e) => {
          const v = Math.max(min, Math.min(max, parseInt(e.target.value, 10) || 0));
          onChange(v);
        }}
      />
      <div className="flex flex-col border-l border-border">
        <button
          className="flex flex-1 items-center justify-center px-1.5 text-muted-foreground transition-colors hover:bg-surface hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
          disabled={disabled || value >= max}
          tabIndex={-1}
          type="button"
          onMouseDown={() => startPress(1)}
          onMouseLeave={stopPress}
          onMouseUp={stopPress}
        >
          <ChevronUp aria-hidden="true" className="size-2.5" />
        </button>
        <button
          className="flex flex-1 items-center justify-center border-t border-border px-1.5 text-muted-foreground transition-colors hover:bg-surface hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
          disabled={disabled || value <= min}
          tabIndex={-1}
          type="button"
          onMouseDown={() => startPress(-1)}
          onMouseLeave={stopPress}
          onMouseUp={stopPress}
        >
          <ChevronDown aria-hidden="true" className="size-2.5" />
        </button>
      </div>
    </div>
  );
}

// ─── Shared: weight row ───────────────────────────────────────────────────────

function WeightRow({
  description,
  label,
  onChange,
  readOnly,
  total,
  weight,
}: {
  description?: string;
  label: string;
  onChange: (w: number) => void;
  readOnly: boolean;
  total: number;
  weight: number;
}) {
  const pct = total > 0 ? Math.round((weight / total) * 100) : 0;
  return (
    <div className="grid gap-1.5">
      <div className="flex items-center gap-3">
        <div className="flex min-w-0 flex-1 items-center gap-1.5">
          <span className="line-clamp-1 text-sm text-foreground">{label}</span>
          {(description || label.length > 28) ? (
            <InfoTooltip
              content={label.length > 28 && description ? `**${label}**\n\n${description}` : description ?? label}
            />
          ) : null}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <span className="w-9 text-right text-xs font-medium tabular-nums text-muted-foreground">
            {pct}%
          </span>
          <NumberStepper disabled={readOnly} max={100} min={0} value={weight} onChange={onChange} />
        </div>
      </div>
      <div className="h-1 overflow-hidden rounded-full bg-surface">
        <div
          className="h-full rounded-full bg-accent transition-[width] duration-150"
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}
