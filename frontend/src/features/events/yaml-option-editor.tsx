"use client";

import { forwardRef, useCallback, useEffect, useImperativeHandle, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { AlertCircle, CheckCircle, ChevronDown, ChevronUp, ChevronsDownUp, ChevronsUpDown, Download, Info, Plus, X } from "lucide-react";

import {
  RANDOM_ALIASES,
  addCustomRangeEntry,
  createRangeEntry,
  findOutOfBoundsRangeOptions,
  findZeroWeightOptions,
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
  type ItemLinkEntry,
  type ItemLinksOption,
  type OptionTypesMap,
  type OutOfBoundsRange,
  type PlandoItem,
  type PlandoItemRow,
  type PlandoItemsOption,
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

type YamlOptionEditorProps = {
  defaultYaml: string | null;
  playerYaml: string | null;
  optionTypes?: OptionTypesMap | null;
  registrationId?: string;
  registrationOpen?: boolean;
  slotId?: string;
  onDirty?: (slotId: string) => void;
  onSaved?: (slotId: string) => void;
  saveUrl?: string;
  onChange?: (yaml: string) => void;
};

/**
 * Imperative handle for consumers that drive their own save button (template mode,
 * `onChange`). `validate()` runs the same save-time guards as the internal Save button
 * (zero-weight + range bounds), updates the inline banners / red labels, and returns
 * `true` only when the config is clean. See story 4.16 AC2 (block the `onChange` path).
 */
export type YamlEditorHandle = { validate: () => boolean };

export const YamlOptionEditor = forwardRef<YamlEditorHandle, YamlOptionEditorProps>(
  function YamlOptionEditor(
    {
      defaultYaml,
      playerYaml,
      optionTypes,
      registrationId,
      registrationOpen = true,
      slotId,
      onDirty,
      onSaved,
      saveUrl,
      onChange,
    },
    ref,
  ) {
  const [parsed, setParsed] = useState<ParsedYaml | null>(() => {
    const base = parseDefaultYaml(defaultYaml ?? "", optionTypes);
    if (!base) return null;
    if (!playerYaml) return base;
    const player = parseDefaultYaml(playerYaml, optionTypes);
    return player ? mergePlayerValues(base, player) : base;
  });
  const [rawYaml, setRawYaml] = useState(playerYaml ?? defaultYaml ?? "");
  const [mode, setMode] = useState<Mode>("simple");
  const [panelSave, setPanelSave] = useState<PanelSave>({ kind: "idle" });
  const [nameError, setNameError] = useState(false);
  const [zeroWeightLabels, setZeroWeightLabels] = useState<string[]>([]);
  const [boundsErrors, setBoundsErrors] = useState<OutOfBoundsRange[]>([]);
  // Keys of options flagged invalid on the last save attempt (highlighted in red).
  const [invalidKeys, setInvalidKeys] = useState<Set<string>>(new Set());

  const [openCategories, setOpenCategories] = useState<Set<string>>(() => {
    const base = parseDefaultYaml(defaultYaml ?? "", optionTypes);
    if (!base) return new Set();
    const firstKey = groupByCategory(base.options)
      .map((s, i) => s.category ?? `__${i}`)
      .at(0);
    return firstKey ? new Set([firstKey]) : new Set();
  });

  // When used in template mode (onChange provided), always editable.
  const effectivelyOpen = onChange ? true : (registrationOpen ?? false);

  // Notify parent in template mode whenever parsed/rawYaml change.
  const isFirstRender = useRef(true);
  useEffect(() => {
    if (isFirstRender.current) {
      isFirstRender.current = false;
      return;
    }
    if (!onChange) return;
    onChange(parsed ? serializeToYaml(parsed) : rawYaml);
  }, [parsed, rawYaml, onChange]);

  function markDirty() {
    if (slotId) onDirty?.(slotId);
    setPanelSave({ kind: "idle" });
  }

  function updateOption(updated: GameOption) {
    setParsed((p) =>
      p ? { ...p, options: p.options.map((o) => (o.key === updated.key ? updated : o)) } : p,
    );
    if (parsed) {
      onChange?.(serializeToYaml({ ...parsed, options: parsed.options.map((o) => (o.key === updated.key ? updated : o)) }));
    }
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

  // Save-time guards (story 4.15 zero-weight + 4.16 range bounds). Updates the inline
  // banners / red labels and returns false when the config must not be saved. Shared by
  // the internal Save button and the imperative `validate()` handle (template mode).
  const runValidation = useCallback((): boolean => {
    // Parse the raw/advanced edits with optionTypes so range options carry their real
    // [min,max]; in the field-based model `parsed` is already the authoritative target.
    const validationTarget = parsed ?? parseDefaultYaml(rawYaml, optionTypes);
    const validationOptions = validationTarget?.options ?? [];

    // A weighted option (toggle/choice/range) whose weights all sum to 0 can never be
    // rolled and fails generation - block the save and point at the offending options.
    const zeroWeight = findZeroWeightOptions(validationOptions);
    if (zeroWeight.length > 0) {
      setZeroWeightLabels(zeroWeight.map((o) => o.label));
      setBoundsErrors([]);
      setInvalidKeys(new Set(zeroWeight.map((o) => o.key)));
      setPanelSave({ kind: "idle" });
      return false;
    }
    setZeroWeightLabels([]);

    // A range value outside its [min, max] bounds is rejected by Archipelago at generation
    // (e.g. progression_balancing 100 when the max is 99) - block it here instead.
    const outOfBounds = findOutOfBoundsRangeOptions(validationOptions);
    if (outOfBounds.length > 0) {
      setBoundsErrors(outOfBounds);
      setInvalidKeys(new Set(outOfBounds.map((o) => o.key)));
      setPanelSave({ kind: "idle" });
      return false;
    }
    setBoundsErrors([]);
    setInvalidKeys(new Set());
    return true;
  }, [parsed, rawYaml, optionTypes]);

  // Template consumers (onChange) drive their own save button: let them gate it on the
  // same validation the internal Save button enforces. (Story 4.16 AC2: onChange path.)
  useImperativeHandle(ref, () => ({ validate: runValidation }), [runValidation]);

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
    if (!runValidation()) return;
    const yamlToSave = parsed ? serializeToYaml({ ...parsed, playerName: parsed.playerName.trim() }) : rawYaml;

    if (onChange) {
      onChange(yamlToSave);
      return;
    }
    setPanelSave({ kind: "saving" });
    try {
      const res = await fetch(
        saveUrl ?? `${env.apiBaseUrl}/registrations/${registrationId ?? ""}/slots/${slotId ?? ""}/yaml`,
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
      if (slotId) onSaved?.(slotId);
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
                  <InfoTooltip content={parsed.playerNameDescription} title="Nom en jeu" />
                ) : null}
                <input
                  aria-invalid={nameError}
                  className={`min-h-9 min-w-0 flex-1 rounded border bg-background px-3 text-sm font-normal text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60 ${nameError ? "border-danger" : "border-border"}`}
                  disabled={!effectivelyOpen}
                  maxLength={50}
                  value={parsed.playerName}
                  onBlur={(e) => {
                    const trimmed = e.target.value.trim();
                    setParsed((p) => (p ? { ...p, playerName: trimmed } : p));
                    if (!trimmed) setNameError(true);
                    if (parsed) onChange?.(serializeToYaml({ ...parsed, playerName: trimmed }));
                  }}
                  onChange={(e) => {
                    setParsed((p) => (p ? { ...p, playerName: e.target.value } : p));
                    if (e.target.value.trim()) setNameError(false);
                    if (parsed) onChange?.(serializeToYaml({ ...parsed, playerName: e.target.value }));
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
                    invalid={invalidKeys.has(opt.key)}
                    mode={mode}
                    option={opt}
                    readOnly={!effectivelyOpen}
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
            disabled={!effectivelyOpen}
            value={rawYaml}
            onChange={(e) => {
              setRawYaml(e.target.value);
              onChange?.(e.target.value);
              markDirty();
            }}
          />
        </div>
      )}

      <div className="mt-5 grid gap-2">
        {!onChange && panelSave.kind === "saved" ? (
          <span className="flex items-center gap-1.5 text-sm text-success">
            <CheckCircle aria-hidden="true" className="size-4 shrink-0" />
            Sauvegardé
          </span>
        ) : null}
        {!onChange && panelSave.kind === "error" ? (
          <span className="flex items-center gap-1.5 text-sm text-danger">
            <AlertCircle aria-hidden="true" className="size-4 shrink-0" />
            {panelSave.message}
          </span>
        ) : null}
        {zeroWeightLabels.length > 0 ? (
          <span className="flex items-start gap-1.5 text-sm text-danger">
            <AlertCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <span>
              Ces options n&apos;ont aucune valeur active (tous les poids sont à 0) :{" "}
              <strong>{zeroWeightLabels.join(", ")}</strong>. Mets au moins une valeur à un poids
              supérieur à 0 - sinon la génération échoue.
            </span>
          </span>
        ) : null}
        {boundsErrors.length > 0 ? (
          <span className="flex items-start gap-1.5 text-sm text-danger">
            <AlertCircle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
            <span>
              Valeurs hors limites :{" "}
              {boundsErrors.map((b, i) => (
                <span key={b.key}>
                  {i > 0 ? "; " : ""}
                  <strong>{b.label}</strong> = {b.values.join(", ")} (autorisé&nbsp;: {b.min}–{b.max})
                </span>
              ))}
              . Corrige ces valeurs avant de sauvegarder.
            </span>
          </span>
        ) : null}

        <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap sm:gap-3">
          {!onChange && effectivelyOpen ? (
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
});

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

function InfoTooltip({ content, title }: { content: string; title?: string }) {
  const [open, setOpen] = useState(false);
  const [modalOpen, setModalOpen] = useState(false);
  // Long descriptions (e.g. game_options) overflow the hover tooltip: cap the preview and let a
  // click open a scrollable modal with the full content.
  const isLong = content.length > 200;

  useEffect(() => {
    if (!modalOpen) return;
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") setModalOpen(false);
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [modalOpen]);

  return (
    <span className="relative inline-flex shrink-0">
      <button
        aria-label="Description de l'option"
        className="inline-flex cursor-pointer rounded focus-visible:outline-2 focus-visible:outline-accent"
        type="button"
        onBlur={() => setOpen(false)}
        onFocus={() => setOpen(true)}
        onMouseEnter={() => setOpen(true)}
        onMouseLeave={() => setOpen(false)}
        onClick={() => { setOpen(false); setModalOpen(true); }}
      >
        <Info aria-hidden="true" className="size-3.5 text-muted-foreground transition-colors hover:text-accent-text" />
      </button>
      {open && !modalOpen ? (
        <span
          role="tooltip"
          className="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 w-72 -translate-x-1/2 rounded-lg border border-border bg-surface-2 px-3.5 py-3 text-xs leading-relaxed text-foreground shadow-[0_8px_32px_rgba(0,0,0,0.5)]"
        >
          <span className={`block ${isLong ? "max-h-44 overflow-hidden" : ""}`}>
            <MiniMarkdown content={content} />
          </span>
          {isLong ? (
            <span className="mt-1.5 block text-[10px] font-medium uppercase tracking-wide text-accent-text">
              Cliquer pour tout afficher
            </span>
          ) : null}
          <span
            aria-hidden="true"
            className="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-[var(--color-border)]"
          />
        </span>
      ) : null}
      {modalOpen && typeof document !== "undefined"
        ? createPortal(
            <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
              <button
                aria-label="Fermer"
                className="absolute inset-0 cursor-default bg-black/60"
                onClick={() => setModalOpen(false)}
                type="button"
              />
              <div
                aria-modal="true"
                role="dialog"
                className="relative flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-xl"
              >
                <div className="flex items-center justify-between gap-3 border-b border-border p-4">
                  <h3 className="min-w-0 truncate font-heading text-base font-semibold text-foreground">
                    {title ?? "Description de l'option"}
                  </h3>
                  <button
                    aria-label="Fermer"
                    className="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-background hover:text-foreground"
                    onClick={() => setModalOpen(false)}
                    type="button"
                  >
                    <X aria-hidden className="size-4" />
                  </button>
                </div>
                <div className="overflow-y-auto whitespace-pre-wrap break-words p-4 text-sm leading-relaxed text-foreground">
                  <MiniMarkdown content={content} />
                </div>
              </div>
            </div>,
            document.body,
          )
        : null}
    </span>
  );
}

// ─── Option field dispatcher ──────────────────────────────────────────────────

function OptionField({
  invalid = false,
  mode,
  option,
  readOnly,
  onChange,
}: {
  invalid?: boolean;
  mode: Mode;
  option: GameOption;
  readOnly: boolean;
  onChange: (updated: GameOption) => void;
}) {
  return (
    <div className="grid gap-2 py-5">
      <div className="flex items-center gap-1.5">
        <p className={`break-words text-base font-semibold ${invalid ? "text-danger" : "text-foreground"}`}>
          {option.label}
        </p>
        {option.description ? <InfoTooltip content={option.description} title={option.label} /> : null}
      </div>
      {option.type === "freeform" && option.kind === "list" && (
        <ListField option={option} readOnly={readOnly} onChange={onChange} />
      )}
      {option.type === "freeform" && option.kind === "dict" && (
        <DictField option={option} readOnly={readOnly} onChange={onChange} />
      )}
      {option.type === "plando_items" && (
        <PlandoItemsField mode={mode} option={option} readOnly={readOnly} onChange={onChange} />
      )}
      {option.type === "item_links" && (
        <ItemLinksField mode={mode} option={option} readOnly={readOnly} onChange={onChange} />
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

// ─── Plando items ─────────────────────────────────────────────────────────────

function PlandoItemsField({
  mode,
  option,
  readOnly,
  onChange,
}: {
  mode: Mode;
  option: PlandoItemsOption;
  readOnly: boolean;
  onChange: (o: GameOption) => void;
}) {
  if (mode === "simple") {
    return (
      <p className="text-xs text-muted-foreground">
        Configurez les plando items en mode <strong>Avancé</strong>.
      </p>
    );
  }

  function updateEntry(updated: PlandoItem) {
    onChange({ ...option, entries: option.entries.map((e) => (e.id === updated.id ? updated : e)) });
  }

  function removeEntry(id: string) {
    onChange({ ...option, entries: option.entries.filter((e) => e.id !== id) });
  }

  function addEntry() {
    const newEntry: PlandoItem = {
      id: crypto.randomUUID(),
      items: [],
      locations: [],
      world: "own",
      fromPool: true,
      force: "silent",
      percentage: 100,
    };
    onChange({ ...option, entries: [...option.entries, newEntry] });
  }

  return (
    <div className="grid gap-3">
      {option.entries.map((entry, i) => (
        <PlandoEntryCard
          key={entry.id}
          entry={entry}
          index={i}
          readOnly={readOnly}
          onChange={updateEntry}
          onRemove={removeEntry}
        />
      ))}
      {!readOnly && (
        <button className={ADD_BTN_CLS} type="button" onClick={addEntry}>
          <Plus aria-hidden="true" className="size-3.5" />
          Ajouter une règle
        </button>
      )}
      {option.entries.length === 0 && readOnly && (
        <p className="text-xs italic text-muted-foreground">Aucune règle plando</p>
      )}
    </div>
  );
}

function PlandoEntryCard({
  entry,
  index,
  readOnly,
  onChange,
  onRemove,
}: {
  entry: PlandoItem;
  index: number;
  readOnly: boolean;
  onChange: (e: PlandoItem) => void;
  onRemove: (id: string) => void;
}) {
  const isNamedWorld = entry.world !== "own" && entry.world !== "any" && entry.world !== "random";
  const selectWorldValue = isNamedWorld ? "named" : entry.world;

  function updateItem(itemId: string, field: keyof Omit<PlandoItemRow, "id">, val: string | number) {
    onChange({
      ...entry,
      items: entry.items.map((i) => (i.id === itemId ? { ...i, [field]: val } : i)),
    });
  }

  function addItem() {
    onChange({ ...entry, items: [...entry.items, { id: crypto.randomUUID(), name: "", quantity: 1 }] });
  }

  function removeItem(itemId: string) {
    onChange({ ...entry, items: entry.items.filter((i) => i.id !== itemId) });
  }

  function updateLocation(locId: string, val: string) {
    onChange({
      ...entry,
      locations: entry.locations.map((l) => (l.id === locId ? { ...l, value: val } : l)),
    });
  }

  function addLocation() {
    onChange({ ...entry, locations: [...entry.locations, { id: crypto.randomUUID(), value: "" }] });
  }

  function removeLocation(locId: string) {
    onChange({ ...entry, locations: entry.locations.filter((l) => l.id !== locId) });
  }

  function handleWorldSelect(val: string) {
    if (val === "named") {
      onChange({ ...entry, world: "" });
    } else {
      onChange({ ...entry, world: val });
    }
  }

  const SUB_LABEL_CLS = "text-[11px] font-semibold uppercase tracking-widest text-muted-foreground";
  const FIELD_LABEL_CLS = "text-sm text-foreground";

  return (
    <div className="rounded-lg border border-border p-3 grid gap-4">
      <div className="flex items-center justify-between">
        <span className={SUB_LABEL_CLS}>Règle plando #{index + 1}</span>
        {!readOnly && (
          <button
            aria-label={`Supprimer la règle plando ${index + 1}`}
            className={REMOVE_BTN_CLS}
            type="button"
            onClick={() => onRemove(entry.id)}
          >
            <X aria-hidden="true" className="size-3.5" />
          </button>
        )}
      </div>

      {/* Items */}
      <div className="grid gap-2">
        <p className={SUB_LABEL_CLS}>Items à placer</p>
        {entry.items.map((item) => (
          <div key={item.id} className="flex items-center gap-2">
            <input
              className={INPUT_CLS}
              disabled={readOnly}
              placeholder="Nom de l'item"
              value={item.name}
              onChange={(e) => updateItem(item.id, "name", e.target.value)}
            />
            <div className="shrink-0">
              <NumberStepper
                disabled={readOnly}
                max={999}
                min={1}
                value={item.quantity}
                onChange={(v) => updateItem(item.id, "quantity", v)}
              />
            </div>
            {!readOnly && (
              <button
                aria-label="Supprimer l'item"
                className={REMOVE_BTN_CLS}
                type="button"
                onClick={() => removeItem(item.id)}
              >
                <X aria-hidden="true" className="size-3.5" />
              </button>
            )}
          </div>
        ))}
        {!readOnly && (
          <button className={ADD_BTN_CLS} type="button" onClick={addItem}>
            <Plus aria-hidden="true" className="size-3.5" />
            Ajouter un item
          </button>
        )}
        {entry.items.length === 0 && readOnly && (
          <p className="text-xs italic text-muted-foreground">Aucun item</p>
        )}
      </div>

      {/* Locations */}
      <div className="grid gap-2">
        <p className={SUB_LABEL_CLS}>Locations cibles</p>
        {entry.locations.map((loc) => (
          <div key={loc.id} className="flex items-center gap-2">
            <input
              className={INPUT_CLS}
              disabled={readOnly}
              placeholder="Nom de la location"
              value={loc.value}
              onChange={(e) => updateLocation(loc.id, e.target.value)}
            />
            {!readOnly && (
              <button
                aria-label="Supprimer la location"
                className={REMOVE_BTN_CLS}
                type="button"
                onClick={() => removeLocation(loc.id)}
              >
                <X aria-hidden="true" className="size-3.5" />
              </button>
            )}
          </div>
        ))}
        {!readOnly && (
          <button className={ADD_BTN_CLS} type="button" onClick={addLocation}>
            <Plus aria-hidden="true" className="size-3.5" />
            Ajouter une location
          </button>
        )}
        {entry.locations.length === 0 && readOnly && (
          <p className="text-xs italic text-muted-foreground">Aucune location</p>
        )}
      </div>

      {/* World */}
      <div className="grid gap-1.5">
        <p className={FIELD_LABEL_CLS}>Monde cible</p>
        <div className="flex flex-wrap items-center gap-2">
          <select
            className="min-h-9 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={readOnly}
            value={selectWorldValue}
            onChange={(e) => handleWorldSelect(e.target.value)}
          >
            <option value="own">Mon monde (défaut)</option>
            <option value="any">N&apos;importe quel autre monde</option>
            <option value="random">Monde aléatoire</option>
            <option value="named">Nom du joueur…</option>
          </select>
          {(selectWorldValue === "named" || isNamedWorld) && (
            <input
              className="min-h-9 flex-1 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
              disabled={readOnly}
              placeholder="Nom du joueur"
              value={isNamedWorld ? entry.world : ""}
              onChange={(e) => onChange({ ...entry, world: e.target.value })}
            />
          )}
        </div>
      </div>

      {/* From pool */}
      <div className="grid gap-1.5">
        <p className={FIELD_LABEL_CLS}>From pool</p>
        <div className="flex w-fit gap-0.5 rounded border border-border p-0.5">
          {([true, false] as const).map((val) => (
            <button
              key={String(val)}
              className={`cursor-pointer rounded px-4 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
                entry.fromPool === val ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"
              }`}
              disabled={readOnly}
              type="button"
              onClick={() => onChange({ ...entry, fromPool: val })}
            >
              {val ? "Oui" : "Non"}
            </button>
          ))}
        </div>
      </div>

      {/* Force */}
      <div className="grid gap-1.5">
        <p className={FIELD_LABEL_CLS}>Force</p>
        <select
          className="min-h-9 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
          disabled={readOnly}
          value={entry.force}
          onChange={(e) => onChange({ ...entry, force: e.target.value as PlandoItem["force"] })}
        >
          <option value="silent">Silencieux (défaut) - ignorer si impossible</option>
          <option value="true">Strict - erreur si impossible</option>
          <option value="false">Souple - warning si impossible</option>
        </select>
      </div>

      {/* Percentage */}
      <div className="grid gap-1.5">
        <p className={FIELD_LABEL_CLS}>Probabilité de déclenchement</p>
        <div className="flex items-center gap-2">
          <NumberStepper
            disabled={readOnly}
            max={100}
            min={0}
            value={entry.percentage}
            onChange={(v) => onChange({ ...entry, percentage: v })}
          />
          <span className="text-sm text-muted-foreground">%</span>
        </div>
      </div>
    </div>
  );
}

// ─── Item links ───────────────────────────────────────────────────────────────

function ItemLinksField({
  mode,
  option,
  readOnly,
  onChange,
}: {
  mode: Mode;
  option: ItemLinksOption;
  readOnly: boolean;
  onChange: (o: GameOption) => void;
}) {
  if (mode === "simple") {
    return (
      <p className="text-xs text-muted-foreground">
        Configurez les item links en mode <strong>Avancé</strong>.
      </p>
    );
  }

  function updateEntry(updated: ItemLinkEntry) {
    onChange({ ...option, entries: option.entries.map((e) => (e.id === updated.id ? updated : e)) });
  }

  function removeEntry(id: string) {
    onChange({ ...option, entries: option.entries.filter((e) => e.id !== id) });
  }

  function addEntry() {
    const newEntry: ItemLinkEntry = {
      id: crypto.randomUUID(),
      name: "",
      itemPool: [],
      replacementItem: null,
      linkReplacement: false,
      localItems: [],
      nonLocalItems: [],
    };
    onChange({ ...option, entries: [...option.entries, newEntry] });
  }

  return (
    <div className="grid gap-3">
      {option.entries.map((entry, i) => (
        <ItemLinkCard
          key={entry.id}
          entry={entry}
          index={i}
          readOnly={readOnly}
          onChange={updateEntry}
          onRemove={removeEntry}
        />
      ))}
      {!readOnly && (
        <button className={ADD_BTN_CLS} type="button" onClick={addEntry}>
          <Plus aria-hidden="true" className="size-3.5" />
          Ajouter un lien
        </button>
      )}
      {option.entries.length === 0 && readOnly && (
        <p className="text-xs italic text-muted-foreground">Aucun item link</p>
      )}
    </div>
  );
}

function ItemLinkCard({
  entry,
  index,
  readOnly,
  onChange,
  onRemove,
}: {
  entry: ItemLinkEntry;
  index: number;
  readOnly: boolean;
  onChange: (e: ItemLinkEntry) => void;
  onRemove: (id: string) => void;
}) {
  const SUB_LABEL_CLS = "text-[11px] font-semibold uppercase tracking-widest text-muted-foreground";
  const FIELD_LABEL_CLS = "text-sm text-foreground";

  const hasEverything = entry.itemPool.includes("Everything");

  function addPoolItem() {
    onChange({ ...entry, itemPool: [...entry.itemPool, ""] });
  }

  function updatePoolItem(idx: number, val: string) {
    onChange({ ...entry, itemPool: entry.itemPool.map((v, i) => (i === idx ? val : v)) });
  }

  function removePoolItem(idx: number) {
    onChange({ ...entry, itemPool: entry.itemPool.filter((_, i) => i !== idx) });
  }

  function toggleEverything() {
    if (hasEverything) {
      onChange({ ...entry, itemPool: entry.itemPool.filter((i) => i !== "Everything") });
    } else {
      onChange({ ...entry, itemPool: ["Everything", ...entry.itemPool.filter((i) => i !== "Everything")] });
    }
  }

  function addLocalItem() {
    onChange({ ...entry, localItems: [...entry.localItems, ""] });
  }

  function updateLocalItem(idx: number, val: string) {
    onChange({ ...entry, localItems: entry.localItems.map((v, i) => (i === idx ? val : v)) });
  }

  function removeLocalItem(idx: number) {
    onChange({ ...entry, localItems: entry.localItems.filter((_, i) => i !== idx) });
  }

  function addNonLocalItem() {
    onChange({ ...entry, nonLocalItems: [...entry.nonLocalItems, ""] });
  }

  function updateNonLocalItem(idx: number, val: string) {
    onChange({ ...entry, nonLocalItems: entry.nonLocalItems.map((v, i) => (i === idx ? val : v)) });
  }

  function removeNonLocalItem(idx: number) {
    onChange({ ...entry, nonLocalItems: entry.nonLocalItems.filter((_, i) => i !== idx) });
  }

  return (
    <div className="rounded-lg border border-border p-3 grid gap-4">
      <div className="flex items-center justify-between">
        <span className={SUB_LABEL_CLS}>Lien #{index + 1}</span>
        {!readOnly && (
          <button
            aria-label={`Supprimer le lien ${index + 1}`}
            className={REMOVE_BTN_CLS}
            type="button"
            onClick={() => onRemove(entry.id)}
          >
            <X aria-hidden="true" className="size-3.5" />
          </button>
        )}
      </div>

      {/* Name */}
      <div className="grid gap-1.5">
        <p className={FIELD_LABEL_CLS}>Nom du groupe</p>
        <input
          className={INPUT_CLS}
          disabled={readOnly}
          placeholder="ex: rods"
          value={entry.name}
          onChange={(e) => onChange({ ...entry, name: e.target.value })}
        />
        <p className="text-xs text-muted-foreground">Les joueurs avec le même nom forment un groupe.</p>
      </div>

      {/* Item pool */}
      <div className="grid gap-2">
        <p className={SUB_LABEL_CLS}>Item pool</p>
        <label className="flex items-center gap-2 text-sm text-foreground cursor-pointer">
          <input
            checked={hasEverything}
            className="rounded"
            disabled={readOnly}
            type="checkbox"
            onChange={toggleEverything}
          />
          Everything (partager tous les items)
        </label>
        {!hasEverything && (
          <>
            {entry.itemPool.map((item, idx) => (
              <div key={`pool-${entry.id}-${idx}`} className="flex items-center gap-2">
                <input
                  className={INPUT_CLS}
                  disabled={readOnly}
                  placeholder="Nom de l'item"
                  value={item}
                  onChange={(e) => updatePoolItem(idx, e.target.value)}
                />
                {!readOnly && (
                  <button
                    aria-label="Supprimer l'item"
                    className={REMOVE_BTN_CLS}
                    type="button"
                    onClick={() => removePoolItem(idx)}
                  >
                    <X aria-hidden="true" className="size-3.5" />
                  </button>
                )}
              </div>
            ))}
            {!readOnly && (
              <button className={ADD_BTN_CLS} type="button" onClick={addPoolItem}>
                <Plus aria-hidden="true" className="size-3.5" />
                Ajouter un item
              </button>
            )}
          </>
        )}
      </div>

      {/* Replacement item */}
      <div className="grid gap-1.5">
        <p className={FIELD_LABEL_CLS}>Item de remplacement</p>
        <div className="flex flex-wrap items-center gap-2">
          <div className="flex w-fit gap-0.5 rounded border border-border p-0.5">
            {([true, false] as const).map((isNull) => {
              const active = isNull ? entry.replacementItem === null : entry.replacementItem !== null;
              return (
                <button
                  key={String(isNull)}
                  className={`cursor-pointer rounded px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
                    active ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"
                  }`}
                  disabled={readOnly}
                  type="button"
                  onClick={() => onChange({ ...entry, replacementItem: isNull ? null : "" })}
                >
                  {isNull ? "Filler auto" : "Spécifier"}
                </button>
              );
            })}
          </div>
          {entry.replacementItem !== null && (
            <input
              className="min-h-9 flex-1 rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent disabled:cursor-not-allowed disabled:opacity-60"
              disabled={readOnly}
              placeholder="Nom de l'item de remplacement"
              value={entry.replacementItem}
              onChange={(e) => onChange({ ...entry, replacementItem: e.target.value })}
            />
          )}
        </div>
      </div>

      {/* Link replacement */}
      <div className="grid gap-1.5">
        <p className={FIELD_LABEL_CLS}>Link replacement</p>
        <div className="flex w-fit gap-0.5 rounded border border-border p-0.5">
          {([true, false] as const).map((val) => (
            <button
              key={String(val)}
              className={`cursor-pointer rounded px-4 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${
                entry.linkReplacement === val ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"
              }`}
              disabled={readOnly}
              type="button"
              onClick={() => onChange({ ...entry, linkReplacement: val })}
            >
              {val ? "Oui" : "Non"}
            </button>
          ))}
        </div>
      </div>

      {/* Local items */}
      <div className="grid gap-2">
        <p className={SUB_LABEL_CLS}>Local items</p>
        {entry.localItems.map((item, idx) => (
          <div key={`local-${entry.id}-${idx}`} className="flex items-center gap-2">
            <input
              className={INPUT_CLS}
              disabled={readOnly}
              placeholder="Nom de l'item"
              value={item}
              onChange={(e) => updateLocalItem(idx, e.target.value)}
            />
            {!readOnly && (
              <button
                aria-label="Supprimer"
                className={REMOVE_BTN_CLS}
                type="button"
                onClick={() => removeLocalItem(idx)}
              >
                <X aria-hidden="true" className="size-3.5" />
              </button>
            )}
          </div>
        ))}
        {!readOnly && (
          <button className={ADD_BTN_CLS} type="button" onClick={addLocalItem}>
            <Plus aria-hidden="true" className="size-3.5" />
            Ajouter
          </button>
        )}
        {entry.localItems.length === 0 && readOnly && (
          <p className="text-xs italic text-muted-foreground">Aucun</p>
        )}
      </div>

      {/* Non-local items */}
      <div className="grid gap-2">
        <p className={SUB_LABEL_CLS}>Non-local items</p>
        {entry.nonLocalItems.map((item, idx) => (
          <div key={`nonlocal-${entry.id}-${idx}`} className="flex items-center gap-2">
            <input
              className={INPUT_CLS}
              disabled={readOnly}
              placeholder="Nom de l'item"
              value={item}
              onChange={(e) => updateNonLocalItem(idx, e.target.value)}
            />
            {!readOnly && (
              <button
                aria-label="Supprimer"
                className={REMOVE_BTN_CLS}
                type="button"
                onClick={() => removeNonLocalItem(idx)}
              >
                <X aria-hidden="true" className="size-3.5" />
              </button>
            )}
          </div>
        ))}
        {!readOnly && (
          <button className={ADD_BTN_CLS} type="button" onClick={addNonLocalItem}>
            <Plus aria-hidden="true" className="size-3.5" />
            Ajouter
          </button>
        )}
        {entry.nonLocalItems.length === 0 && readOnly && (
          <p className="text-xs italic text-muted-foreground">Aucun</p>
        )}
      </div>
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
              title={label}
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
