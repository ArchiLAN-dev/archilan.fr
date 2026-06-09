"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Save } from "lucide-react";
import { useState } from "react";

import {
  COMPATIBILITY_VALUES,
  COUNTDOWN_MODES,
  fetchSessionConfig,
  PLANDO_OPTIONS,
  RELEASE_COLLECT_MODES,
  REMAINING_MODES,
  SESSION_CONFIG_TYPES,
  SPOILER_LEVELS,
  updateSessionConfig,
} from "./admin-session-config-api";
import type { SessionConfig, SessionConfigType } from "./admin-session-config-api";

const TYPE_LABELS: Record<SessionConfigType, string> = {
  private: "Sessions privées",
  event: "Événements",
  weekly: "Weekly runs",
};

const COMPATIBILITY_LABELS: Record<number, string> = {
  2: "Casual (2)",
  1: "Racing (1)",
  0: "Tournoi (0)",
};

const SPOILER_LABELS: Record<number, string> = {
  0: "Aucun (0)",
  1: "Sans playthrough (1)",
  2: "Avec playthrough (2)",
  3: "Playthrough + chemins (3)",
};

const ERROR_LABELS: Record<string, string> = {
  network_error: "Erreur réseau, réessaie.",
  update_failed: "Échec de l'enregistrement.",
  invalid_response: "Réponse invalide du serveur.",
};

function errorLabel(code: string): string {
  if (code in ERROR_LABELS) return ERROR_LABELS[code];
  if (code.startsWith("invalid_")) return `Valeur invalide (${code}).`;
  return ERROR_LABELS.update_failed;
}

export function AdminSessionConfigPage() {
  const [activeType, setActiveType] = useState<SessionConfigType>("weekly");

  return (
    <div className="flex flex-col gap-8 p-6 md:p-8">
      <header>
        <h2 className="font-heading text-xl font-bold text-foreground">Configuration des sessions</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Options serveur &amp; génération appliquées aux runs lancés, par type de session.
        </p>
      </header>

      <div className="flex flex-wrap gap-1 border-b border-border">
        {SESSION_CONFIG_TYPES.map((type) => (
          <button
            className={`px-4 py-2.5 text-sm font-medium transition-colors ${
              activeType === type
                ? "border-b-2 border-accent-text text-foreground"
                : "text-muted-foreground hover:text-foreground"
            }`}
            key={type}
            onClick={() => setActiveType(type)}
            type="button"
          >
            {TYPE_LABELS[type]}
          </button>
        ))}
      </div>

      <SessionConfigForm key={activeType} type={activeType} />
    </div>
  );
}

function SessionConfigForm({ type }: { type: SessionConfigType }) {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({
    queryKey: ["admin-session-config", type],
    queryFn: () => fetchSessionConfig(type),
    staleTime: 30_000,
  });

  const [draft, setDraft] = useState<SessionConfig | null>(null);
  const [syncedFrom, setSyncedFrom] = useState<SessionConfig | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  // Initialise/refresh the editable draft from the loaded config (incl. after a save
  // invalidates the query). React-sanctioned "adjust state while rendering" pattern —
  // runs only when the query returns a new config object, not on every keystroke.
  if (data && data !== syncedFrom) {
    setSyncedFrom(data);
    setDraft(structuredClone(data));
  }

  const mutation = useMutation({
    mutationFn: (config: SessionConfig) => updateSessionConfig(type, config),
    onSuccess: async (result) => {
      if (result.ok) {
        setError(null);
        setSaved(true);
        await queryClient.invalidateQueries({ queryKey: ["admin-session-config", type] });
      } else {
        setSaved(false);
        setError(errorLabel(result.error));
      }
    },
    onError: () => setError(errorLabel("update_failed")),
  });

  if (isLoading || draft === null) {
    return (
      <div className="flex items-center gap-3 text-sm text-muted-foreground">
        <Loader2 aria-hidden className="size-5 animate-spin" />
        Chargement…
      </div>
    );
  }

  const server = draft.server;
  const generation = draft.generation;

  function patchServer(patch: Partial<SessionConfig["server"]>): void {
    setSaved(false);
    setDraft((d) => (d === null ? d : { ...d, server: { ...d.server, ...patch } }));
  }

  function patchGeneration(patch: Partial<SessionConfig["generation"]>): void {
    setSaved(false);
    setDraft((d) => (d === null ? d : { ...d, generation: { ...d.generation, ...patch } }));
  }

  function togglePlando(option: string): void {
    const current = generation.plandoOptions;
    patchGeneration({
      plandoOptions: current.includes(option)
        ? current.filter((p) => p !== option)
        : [...current, option],
    });
  }

  return (
    <form
      className="grid gap-8 lg:grid-cols-2"
      onSubmit={(e) => {
        e.preventDefault();
        mutation.mutate(draft);
      }}
    >
      <fieldset className="grid gap-4 rounded-xl border border-border bg-surface p-5">
        <legend className="px-1 font-heading text-sm font-semibold text-foreground">Serveur</legend>

        <SelectField label="!release" onChange={(v) => patchServer({ releaseMode: v })} options={RELEASE_COLLECT_MODES} value={server.releaseMode} />
        <SelectField label="!collect" onChange={(v) => patchServer({ collectMode: v })} options={RELEASE_COLLECT_MODES} value={server.collectMode} />
        <SelectField label="!remaining" onChange={(v) => patchServer({ remainingMode: v })} options={REMAINING_MODES} value={server.remainingMode} />
        <SelectField label="!countdown" onChange={(v) => patchServer({ countdownMode: v })} options={COUNTDOWN_MODES} value={server.countdownMode} />

        <CheckboxField checked={server.disableItemCheat} label="Désactiver !getitem (anti-triche)" onChange={(c) => patchServer({ disableItemCheat: c })} />

        <NumberField label="Coût d'un indice (%)" max={100} min={0} onChange={(n) => patchServer({ hintCost: n })} value={server.hintCost} />
        <NumberField label="Points par check" min={0} onChange={(n) => patchServer({ locationCheckPoints: n })} value={server.locationCheckPoints} />
        <NumberField label="Auto-shutdown (s, 0 = jamais)" min={0} onChange={(n) => patchServer({ autoShutdown: n })} value={server.autoShutdown} />

        <NumberSelectField
          label="Compatibilité"
          labels={COMPATIBILITY_LABELS}
          onChange={(n) => patchServer({ compatibility: n })}
          options={COMPATIBILITY_VALUES}
          value={server.compatibility}
        />

        <label className="grid gap-1 text-sm">
          <span className="text-muted-foreground">Mot de passe de connexion (vide = aucun)</span>
          <input
            className="h-9 rounded border border-border bg-surface-2 px-2 text-foreground focus:border-accent-text focus:outline-none"
            onChange={(e) => patchServer({ joinPassword: e.target.value === "" ? null : e.target.value })}
            type="text"
            value={server.joinPassword ?? ""}
          />
        </label>
      </fieldset>

      <fieldset className="grid content-start gap-4 rounded-xl border border-border bg-surface p-5">
        <legend className="px-1 font-heading text-sm font-semibold text-foreground">Génération</legend>

        <div className="grid gap-2">
          <span className="text-sm text-muted-foreground">Plando autorisé</span>
          <div className="flex flex-wrap gap-3">
            {PLANDO_OPTIONS.map((option) => (
              <label className="flex items-center gap-1.5 text-sm text-foreground" key={option}>
                <input
                  checked={generation.plandoOptions.includes(option)}
                  onChange={() => togglePlando(option)}
                  type="checkbox"
                />
                {option}
              </label>
            ))}
          </div>
        </div>

        <CheckboxField checked={generation.race} label="Mode race (roms chiffrées)" onChange={(c) => patchGeneration({ race: c })} />

        <NumberSelectField
          label="Spoiler"
          labels={SPOILER_LABELS}
          onChange={(n) => patchGeneration({ spoiler: n })}
          options={SPOILER_LEVELS}
          value={generation.spoiler}
        />
      </fieldset>

      <div className="flex items-center gap-3 lg:col-span-2">
        <button
          className="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:opacity-50"
          disabled={mutation.isPending}
          type="submit"
        >
          {mutation.isPending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Save aria-hidden className="size-4" />}
          Enregistrer
        </button>
        {saved ? <span className="text-sm text-success">Enregistré.</span> : null}
        {error !== null ? <span className="text-sm text-danger">{error}</span> : null}
      </div>
    </form>
  );
}

// ── Field primitives ─────────────────────────────────────────────────────────

function SelectField({
  label,
  value,
  options,
  onChange,
}: {
  label: string;
  value: string;
  options: readonly string[];
  onChange: (v: string) => void;
}) {
  return (
    <label className="grid gap-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <select
        className="h-9 rounded border border-border bg-surface-2 px-2 text-foreground focus:border-accent-text focus:outline-none"
        onChange={(e) => onChange(e.target.value)}
        value={value}
      >
        {options.map((opt) => (
          <option key={opt} value={opt}>
            {opt}
          </option>
        ))}
      </select>
    </label>
  );
}

function NumberSelectField({
  label,
  value,
  options,
  labels,
  onChange,
}: {
  label: string;
  value: number;
  options: readonly number[];
  labels: Record<number, string>;
  onChange: (v: number) => void;
}) {
  return (
    <label className="grid gap-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <select
        className="h-9 rounded border border-border bg-surface-2 px-2 text-foreground focus:border-accent-text focus:outline-none"
        onChange={(e) => onChange(Number(e.target.value))}
        value={value}
      >
        {options.map((opt) => (
          <option key={opt} value={opt}>
            {labels[opt] ?? String(opt)}
          </option>
        ))}
      </select>
    </label>
  );
}

function NumberField({
  label,
  value,
  min,
  max,
  onChange,
}: {
  label: string;
  value: number;
  min?: number;
  max?: number;
  onChange: (v: number) => void;
}) {
  return (
    <label className="grid gap-1 text-sm">
      <span className="text-muted-foreground">{label}</span>
      <input
        className="h-9 rounded border border-border bg-surface-2 px-2 text-foreground focus:border-accent-text focus:outline-none"
        max={max}
        min={min}
        onChange={(e) => onChange(Number(e.target.value))}
        type="number"
        value={value}
      />
    </label>
  );
}

function CheckboxField({
  label,
  checked,
  onChange,
}: {
  label: string;
  checked: boolean;
  onChange: (c: boolean) => void;
}) {
  return (
    <label className="flex items-center gap-2 text-sm text-foreground">
      <input checked={checked} onChange={(e) => onChange(e.target.checked)} type="checkbox" />
      {label}
    </label>
  );
}
