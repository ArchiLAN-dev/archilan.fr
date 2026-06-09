"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Save } from "lucide-react";
import { useState } from "react";

import {
  COMPATIBILITY_VALUES,
  COUNTDOWN_MODES,
  PLANDO_OPTIONS,
  RELEASE_COLLECT_MODES,
  REMAINING_MODES,
  SPOILER_LEVELS,
} from "./admin-session-config-api";

// Adapter so the same editor serves the admin endpoints (weekly template / event session)
// and the owner endpoint (private run). Each returns the partial override object.
export type OverrideAdapter = {
  queryKey: readonly unknown[];
  load: () => Promise<Record<string, unknown>>;
  save: (override: Record<string, unknown>) => Promise<{ ok: true } | { ok: false; error: string }>;
  clear: () => Promise<boolean>;
};

type OverrideValue = string | number | boolean | string[];

type FieldDef =
  | { key: string; label: string; kind: "select"; options: readonly string[]; labels: Record<string, string>; fallback: string }
  | { key: string; label: string; kind: "int"; min?: number; max?: number; fallback: number }
  | { key: string; label: string; kind: "intselect"; options: readonly number[]; labels: Record<number, string>; fallback: number }
  | { key: string; label: string; kind: "bool"; fallback: boolean }
  | { key: string; label: string; kind: "plando"; fallback: string[] };

const RC: Record<string, string> = {
  disabled: "Désactivé",
  enabled: "Toujours autorisé",
  goal: "Après l'objectif atteint",
  auto: "Automatique à l'objectif",
  "auto-enabled": "Automatique + manuel",
};
const REM: Record<string, string> = { enabled: "Toujours autorisé", disabled: "Désactivé", goal: "Après l'objectif atteint" };
const CD: Record<string, string> = { enabled: "Toujours autorisé", disabled: "Désactivé", auto: "Auto (< 30 joueurs)" };
const COMPAT: Record<number, string> = { 2: "Casual (2)", 1: "Racing (1)", 0: "Tournoi (0)" };
const SPOIL: Record<number, string> = { 0: "Aucun", 1: "Sans solution", 2: "Avec solution", 3: "Solution + chemins" };

const FIELDS: FieldDef[] = [
  { key: "releaseMode", label: "Don des objets restants (!release)", kind: "select", options: RELEASE_COLLECT_MODES, labels: RC, fallback: "disabled" },
  { key: "collectMode", label: "Récupération des objets (!collect)", kind: "select", options: RELEASE_COLLECT_MODES, labels: RC, fallback: "disabled" },
  { key: "remainingMode", label: "Voir les objets restants (!remaining)", kind: "select", options: REMAINING_MODES, labels: REM, fallback: "goal" },
  { key: "countdownMode", label: "Compte à rebours (!countdown)", kind: "select", options: COUNTDOWN_MODES, labels: CD, fallback: "auto" },
  { key: "disableItemCheat", label: "Interdire la triche d'objets (!getitem)", kind: "bool", fallback: true },
  { key: "hintCost", label: "Coût d'un indice (%)", kind: "int", min: 0, max: 100, fallback: 10 },
  { key: "locationCheckPoints", label: "Points gagnés par check", kind: "int", min: 0, fallback: 1 },
  { key: "autoShutdown", label: "Arrêt auto après inactivité (s)", kind: "int", min: 0, fallback: 0 },
  { key: "compatibility", label: "Compatibilité", kind: "intselect", options: COMPATIBILITY_VALUES, labels: COMPAT, fallback: 2 },
  { key: "plandoOptions", label: "Plando autorisé", kind: "plando", fallback: [] },
  { key: "race", label: "Mode course (ROMs chiffrées)", kind: "bool", fallback: false },
  { key: "spoiler", label: "Niveau de spoiler", kind: "intselect", options: SPOILER_LEVELS, labels: SPOIL, fallback: 3 },
];

const ERROR_LABELS: Record<string, string> = {
  network_error: "Erreur réseau, réessaie.",
  update_failed: "Échec de l'enregistrement.",
  forbidden: "Accès refusé.",
  not_found: "Introuvable.",
};

function errorLabel(code: string): string {
  if (code in ERROR_LABELS) return ERROR_LABELS[code];
  if (code.startsWith("invalid_")) return `Valeur invalide (${code}).`;
  return ERROR_LABELS.update_failed;
}

export function SessionConfigOverrideForm({ adapter, scopeLabel }: { adapter: OverrideAdapter; scopeLabel: string }) {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: adapter.queryKey,
    queryFn: () => adapter.load(),
    staleTime: 30_000,
  });

  const [draft, setDraft] = useState<Record<string, OverrideValue> | null>(null);
  const [syncedFrom, setSyncedFrom] = useState<Record<string, unknown> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  if (data && data !== syncedFrom) {
    setSyncedFrom(data);
    setDraft(coerce(data));
  }

  const saveMutation = useMutation({
    mutationFn: (override: Record<string, OverrideValue>) => adapter.save(override),
    onSuccess: async (result) => {
      if (result.ok) {
        setError(null);
        setSaved(true);
        await queryClient.invalidateQueries({ queryKey: adapter.queryKey });
      } else {
        setSaved(false);
        setError(errorLabel(result.error));
      }
    },
    onError: () => setError(errorLabel("update_failed")),
  });

  const clearMutation = useMutation({
    mutationFn: () => adapter.clear(),
    onSuccess: async () => {
      setError(null);
      setSaved(true);
      setDraft({});
      await queryClient.invalidateQueries({ queryKey: adapter.queryKey });
    },
  });

  if (isLoading || draft === null) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
      </div>
    );
  }

  const current = draft;

  function setField(key: string, value: OverrideValue): void {
    setSaved(false);
    setDraft((d) => ({ ...(d ?? {}), [key]: value }));
  }

  function toggleField(field: FieldDef, on: boolean): void {
    setSaved(false);
    setDraft((d) => {
      const next = { ...(d ?? {}) };
      if (on) {
        next[field.key] = field.fallback;
      } else {
        delete next[field.key];
      }
      return next;
    });
  }

  return (
    <div className="grid gap-3">
      <p className="text-xs text-muted-foreground">
        Surcharge le profil pour {scopeLabel}. Les champs non surchargés héritent du profil.
      </p>

      <div className="grid gap-2">
        {FIELDS.map((field) => {
          const overridden = field.key in current;
          return (
            <div className="flex flex-wrap items-center gap-3 rounded border border-border bg-surface-2/40 px-3 py-2" key={field.key}>
              <label className="flex min-w-56 items-center gap-2 text-sm">
                <input checked={overridden} onChange={(e) => toggleField(field, e.target.checked)} type="checkbox" />
                <span className={overridden ? "font-medium text-foreground" : "text-muted-foreground"}>{field.label}</span>
              </label>
              {overridden ? (
                <OverrideControl field={field} onChange={(v) => setField(field.key, v)} value={current[field.key]} />
              ) : (
                <span className="text-xs italic text-muted-foreground">hérité du profil</span>
              )}
            </div>
          );
        })}
      </div>

      <div className="flex items-center gap-3">
        <button
          className="inline-flex items-center gap-2 rounded-lg bg-accent px-3 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={saveMutation.isPending}
          onClick={() => saveMutation.mutate(current)}
          type="button"
        >
          {saveMutation.isPending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Save aria-hidden className="size-4" />}
          Enregistrer l&apos;override
        </button>
        <button
          className="rounded-lg border border-border px-3 py-2 text-sm text-muted-foreground transition-colors hover:text-foreground disabled:opacity-50"
          disabled={clearMutation.isPending}
          onClick={() => clearMutation.mutate()}
          type="button"
        >
          Tout réinitialiser
        </button>
        {saved ? <span className="text-sm text-success">Enregistré.</span> : null}
        {error !== null ? <span className="text-sm text-danger">{error}</span> : null}
      </div>
    </div>
  );
}

function OverrideControl({ field, value, onChange }: { field: FieldDef; value: OverrideValue; onChange: (v: OverrideValue) => void }) {
  if (field.kind === "select") {
    return (
      <select className="h-8 rounded border border-border bg-surface px-2 text-sm text-foreground" onChange={(e) => onChange(e.target.value)} value={typeof value === "string" ? value : field.fallback}>
        {field.options.map((o) => (
          <option key={o} value={o}>{field.labels[o] ?? o}</option>
        ))}
      </select>
    );
  }
  if (field.kind === "intselect") {
    return (
      <select className="h-8 rounded border border-border bg-surface px-2 text-sm text-foreground" onChange={(e) => onChange(Number(e.target.value))} value={typeof value === "number" ? value : field.fallback}>
        {field.options.map((o) => (
          <option key={o} value={o}>{field.labels[o] ?? String(o)}</option>
        ))}
      </select>
    );
  }
  if (field.kind === "int") {
    return (
      <input className="h-8 w-24 rounded border border-border bg-surface px-2 text-sm text-foreground" max={field.max} min={field.min} onChange={(e) => onChange(Number(e.target.value))} type="number" value={typeof value === "number" ? value : field.fallback} />
    );
  }
  if (field.kind === "bool") {
    return (
      <label className="flex items-center gap-1.5 text-sm text-foreground">
        <input checked={value === true} onChange={(e) => onChange(e.target.checked)} type="checkbox" /> activé
      </label>
    );
  }
  // plando
  const selected = Array.isArray(value) ? value : [];
  return (
    <div className="flex flex-wrap gap-2">
      {PLANDO_OPTIONS.map((opt) => (
        <label className="flex items-center gap-1 text-sm text-foreground" key={opt}>
          <input
            checked={selected.includes(opt)}
            onChange={() => onChange(selected.includes(opt) ? selected.filter((p) => p !== opt) : [...selected, opt])}
            type="checkbox"
          />
          {opt}
        </label>
      ))}
    </div>
  );
}

// Coerce the loaded override (unknown JSON) into a typed draft, keeping only known fields.
function coerce(raw: Record<string, unknown>): Record<string, OverrideValue> {
  const out: Record<string, OverrideValue> = {};
  for (const field of FIELDS) {
    if (!(field.key in raw)) continue;
    const v = raw[field.key];
    if (field.kind === "select" && typeof v === "string") out[field.key] = v;
    else if ((field.kind === "int" || field.kind === "intselect") && typeof v === "number") out[field.key] = v;
    else if (field.kind === "bool" && typeof v === "boolean") out[field.key] = v;
    else if (field.kind === "plando" && Array.isArray(v)) out[field.key] = v.filter((p): p is string => typeof p === "string");
  }
  return out;
}
