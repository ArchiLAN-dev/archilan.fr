"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Save } from "lucide-react";
import { useState } from "react";

import { Switch } from "@/components/switch";

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
  0: "Aucun",
  1: "Sans solution",
  2: "Avec solution",
  3: "Solution + chemins",
};

// French display labels for the Archipelago mode values (the stored values stay in English).
const RELEASE_COLLECT_LABELS: Record<string, string> = {
  disabled: "Désactivé",
  enabled: "Toujours autorisé",
  goal: "Après l'objectif atteint",
  auto: "Automatique à l'objectif",
  "auto-enabled": "Automatique + manuel",
};

const REMAINING_LABELS: Record<string, string> = {
  enabled: "Toujours autorisé",
  disabled: "Désactivé",
  goal: "Après l'objectif atteint",
};

const COUNTDOWN_LABELS: Record<string, string> = {
  enabled: "Toujours autorisé",
  disabled: "Désactivé",
  auto: "Auto (salles < 30 joueurs)",
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
      className="grid max-w-5xl gap-6"
      onSubmit={(e) => {
        e.preventDefault();
        mutation.mutate(draft);
      }}
    >
      <Section description="Politique d'échange des objets restants entre les mondes de la partie." title="Échanges d'objets">
        <div className="grid gap-4 sm:grid-cols-3">
          <SelectField hint="Renvoi des objets d'un monde terminé vers les autres." label="Don (!release)" labels={RELEASE_COLLECT_LABELS} onChange={(v) => patchServer({ releaseMode: v })} options={RELEASE_COLLECT_MODES} value={server.releaseMode} />
          <SelectField hint="Récupération de ses objets chez les autres mondes." label="Récupération (!collect)" labels={RELEASE_COLLECT_LABELS} onChange={(v) => patchServer({ collectMode: v })} options={RELEASE_COLLECT_MODES} value={server.collectMode} />
          <SelectField hint="Demander la liste des objets encore à recevoir." label="Objets restants (!remaining)" labels={REMAINING_LABELS} onChange={(v) => patchServer({ remainingMode: v })} options={REMAINING_MODES} value={server.remainingMode} />
        </div>
      </Section>

      <Section description="Économie d'indices et points attribués aux joueurs." title="Indices & score">
        <div className="grid gap-4 sm:grid-cols-2">
          <NumberField hint="Pourcentage de checks à compléter pour gagner un indice." label="Coût d'un indice (% des checks)" max={100} min={0} onChange={(n) => patchServer({ hintCost: n })} value={server.hintCost} />
          <NumberField hint="Points d'indice gagnés à chaque check trouvé." label="Points gagnés par check" min={0} onChange={(n) => patchServer({ locationCheckPoints: n })} value={server.locationCheckPoints} />
        </div>
      </Section>

      <Section description="Comportement de la salle et règles de la partie." title="Salle & partie">
        <div className="grid gap-4 sm:grid-cols-3">
          <SelectField hint="Compte à rebours lançable par les joueurs." label="Compte à rebours (!countdown)" labels={COUNTDOWN_LABELS} onChange={(v) => patchServer({ countdownMode: v })} options={COUNTDOWN_MODES} value={server.countdownMode} />
          <NumberSelectField label="Compatibilité clients" labels={COMPATIBILITY_LABELS} onChange={(n) => patchServer({ compatibility: n })} options={COMPATIBILITY_VALUES} value={server.compatibility} />
          <NumberField hint="Arrêt du serveur après ce délai sans check (0 = jamais)." label="Arrêt auto (s)" min={0} onChange={(n) => patchServer({ autoShutdown: n })} value={server.autoShutdown} />
        </div>
        <div className="mt-4">
          <SwitchRow
            checked={server.disableItemCheat}
            description="Empêche la commande !getitem (anti-triche)."
            label="Interdire la triche d'objets"
            onChange={(c) => patchServer({ disableItemCheat: c })}
          />
        </div>
        <p className="mt-3 text-xs text-muted-foreground">
          Le mot de passe de connexion n&apos;est pas défini ici : un mot de passe aléatoire est
          généré par run. Il peut être fixé ponctuellement via un override.
        </p>
      </Section>

      <Section description="Options appliquées lors de la génération du multimonde." title="Génération">
        <div className="grid gap-4">
          <div className="grid gap-2">
            <span className="text-sm font-medium text-foreground">Plando autorisé</span>
            <span className="text-xs text-muted-foreground">Placement manuel d&apos;objets / boss / connexions.</span>
            <div className="flex flex-wrap gap-2 pt-1">
              {PLANDO_OPTIONS.map((option) => {
                const on = generation.plandoOptions.includes(option);
                return (
                  <button
                    aria-pressed={on}
                    className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs transition-colors ${
                      on ? "border-accent bg-accent/15 text-accent-text" : "border-border text-muted-foreground hover:text-foreground"
                    }`}
                    key={option}
                    onClick={() => togglePlando(option)}
                    type="button"
                  >
                    {option}
                  </button>
                );
              })}
            </div>
          </div>
          <SwitchRow
            checked={generation.race}
            description="Génère des ROMs chiffrées (mode course), spoiler masqué."
            label="Mode course"
            onChange={(c) => patchGeneration({ race: c })}
          />
          <div className="sm:max-w-xs">
            <NumberSelectField label="Niveau de spoiler généré" labels={SPOILER_LABELS} onChange={(n) => patchGeneration({ spoiler: n })} options={SPOILER_LEVELS} value={generation.spoiler} />
          </div>
        </div>
      </Section>

      <div className="flex items-center gap-3">
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

// ── Layout primitives ───────────────────────────────────────────────────────

function Section({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return (
    <section className="rounded-xl border border-border bg-surface p-5">
      <div className="mb-4">
        <h3 className="font-heading text-sm font-semibold text-foreground">{title}</h3>
        {description !== undefined ? <p className="mt-0.5 text-xs text-muted-foreground">{description}</p> : null}
      </div>
      {children}
    </section>
  );
}

function SwitchRow({
  label,
  description,
  checked,
  onChange,
}: {
  label: string;
  description?: string;
  checked: boolean;
  onChange: (c: boolean) => void;
}) {
  return (
    <div className="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface-2/40 px-4 py-3">
      <div>
        <p className="text-sm font-medium text-foreground">{label}</p>
        {description !== undefined ? <p className="text-xs text-muted-foreground">{description}</p> : null}
      </div>
      <Switch ariaLabel={label} checked={checked} onChange={onChange} />
    </div>
  );
}

// ── Field primitives ─────────────────────────────────────────────────────────

function SelectField({
  label,
  value,
  options,
  labels,
  hint,
  onChange,
}: {
  label: string;
  value: string;
  options: readonly string[];
  labels?: Record<string, string>;
  hint?: string;
  onChange: (v: string) => void;
}) {
  return (
    <label className="flex h-full flex-col gap-1 text-sm">
      <span className="font-medium text-foreground">{label}</span>
      {hint !== undefined ? <span className="text-xs text-muted-foreground">{hint}</span> : null}
      <select
        className="mt-auto h-9 rounded border border-border bg-surface-2 px-2 text-foreground focus:border-accent-text focus:outline-none"
        onChange={(e) => onChange(e.target.value)}
        value={value}
      >
        {options.map((opt) => (
          <option key={opt} value={opt}>
            {labels?.[opt] ?? opt}
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
    <label className="flex h-full flex-col gap-1 text-sm">
      <span className="font-medium text-foreground">{label}</span>
      <select
        className="mt-auto h-9 rounded border border-border bg-surface-2 px-2 text-foreground focus:border-accent-text focus:outline-none"
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
  hint,
  onChange,
}: {
  label: string;
  value: number;
  min?: number;
  max?: number;
  hint?: string;
  onChange: (v: number) => void;
}) {
  return (
    <label className="flex h-full flex-col gap-1 text-sm">
      <span className="font-medium text-foreground">{label}</span>
      {hint !== undefined ? <span className="text-xs text-muted-foreground">{hint}</span> : null}
      <input
        className="mt-auto h-9 rounded border border-border bg-surface-2 px-2 text-foreground focus:border-accent-text focus:outline-none"
        max={max}
        min={min}
        onChange={(e) => onChange(Number(e.target.value))}
        type="number"
        value={value}
      />
    </label>
  );
}

