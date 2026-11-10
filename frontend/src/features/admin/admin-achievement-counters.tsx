"use client";

import { useEffect, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ChevronDown, Hash, Loader2, Pencil, Plus, Trash2, X } from "lucide-react";

import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";

import {
  COUNTER_KIND_LABELS,
  COUNTER_MODE_LABELS,
  createAchievementCounter,
  deleteAchievementCounter,
  fetchAchievementCounters,
  fetchCounterNames,
  updateAchievementCounter,
  type AchievementCounter,
  type CounterGame,
  type CounterKind,
  type CounterMode,
} from "./admin-achievement-counters-api";

// Story 30.57: the counters of checks and items that achievements read with a quantity.

const QUERY_KEY = ["admin-achievement-counters"] as const;
const STALE_TIME = 15_000;
const NAMES_STALE_TIME = 60_000;
/** The names are asked for from two characters, once the admin paused typing. */
export const MIN_QUERY = 2;
const DEBOUNCE_MS = 250;
const MODES: readonly CounterMode[] = ["total", "max_session", "distinct"];

type EditorState = { mode: "closed" } | { mode: "create" } | { mode: "edit"; counter: AchievementCounter };

type Props = {
  /** After a counter changed: the rule builder's list of counters is the achievements dashboard's. */
  onChanged: () => Promise<void>;
};

export function AdminAchievementCounters({ onChanged }: Props) {
  const queryClient = useQueryClient();
  const { data, isLoading, isError } = useQuery({ queryKey: QUERY_KEY, queryFn: fetchAchievementCounters, staleTime: STALE_TIME });
  const [open, setOpen] = useState(false);
  const [editor, setEditor] = useState<EditorState>({ mode: "closed" });
  const [deleting, setDeleting] = useState<AchievementCounter | null>(null);

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: QUERY_KEY });
    await onChanged();
  }

  const counters = data?.counters ?? [];

  return (
    <section aria-label="Compteurs" className="grid gap-3 rounded-2xl border border-border/60 p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <button aria-expanded={open} className="inline-flex items-center gap-2 text-left" onClick={() => setOpen((v) => !v)} type="button">
          <ChevronDown aria-hidden className={`size-4 text-muted-foreground transition-transform ${open ? "" : "-rotate-90"}`} />
          <span className="font-heading text-sm font-semibold text-foreground">Compteurs de checks et d&apos;items</span>
          <span className="text-xs text-muted-foreground">{data ? `${counters.length}` : ""}</span>
        </button>
        <button
          className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-border bg-surface px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
          disabled={!data}
          onClick={() => setEditor({ mode: "create" })}
          type="button"
        >
          <Plus aria-hidden className="size-4" /> Nouveau compteur
        </button>
      </div>

      {open ? (
        isLoading ? (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 aria-hidden className="size-4 animate-spin" /> Chargement…
          </p>
        ) : isError || !data ? (
          <p className="text-sm text-muted-foreground">Impossible de charger les compteurs.</p>
        ) : counters.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            Aucun compteur. Un compteur compte des checks ou des items précis d&apos;un jeu ; un succès le lit avec une quantité (« Compteur… » dans ses conditions).
          </p>
        ) : (
          <ul className="grid gap-2">
            {counters.map((counter) => (
              <CounterRow counter={counter} key={counter.id} onDelete={() => setDeleting(counter)} onEdit={() => setEditor({ mode: "edit", counter })} />
            ))}
          </ul>
        )
      ) : null}

      {editor.mode !== "closed" && data ? (
        <CounterDialog
          games={data.games}
          initial={editor.mode === "edit" ? editor.counter : null}
          onClose={() => setEditor({ mode: "closed" })}
          onSaved={async () => {
            await refresh();
            setEditor({ mode: "closed" });
            setOpen(true);
          }}
        />
      ) : null}

      {deleting ? (
        <DeleteCounterDialog
          counter={deleting}
          onClose={() => setDeleting(null)}
          onDeleted={async () => {
            await refresh();
            setDeleting(null);
          }}
        />
      ) : null}
    </section>
  );
}

function CounterRow({ counter, onEdit, onDelete }: { counter: AchievementCounter; onEdit: () => void; onDelete: () => void }) {
  return (
    <li className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-background px-3 py-2">
      <Hash aria-hidden className="size-4 text-teal-300" />
      <div className="grid min-w-0 flex-1 gap-0.5">
        <span className="truncate text-sm font-semibold text-foreground">{counter.label}</span>
        <span className="truncate text-xs text-muted-foreground">
          {counter.gameName ?? "jeu introuvable"} · {COUNTER_KIND_LABELS[counter.kind]} · {COUNTER_MODE_LABELS[counter.mode]}
          {counter.withOthersOnly ? " · avec un autre membre" : ""} · {counter.targets.length} {counter.targets.length > 1 ? "noms" : "nom"}
        </span>
      </div>
      <span className="text-xs text-muted-foreground">
        {counter.usedBy} succès
        {(counter.usedByQuests ?? 0) > 0 ? ` · ${counter.usedByQuests} ${counter.usedByQuests === 1 ? "quête" : "quêtes"}` : ""}
      </span>
      <button aria-label={`Modifier ${counter.label}`} className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground hover:text-foreground" onClick={onEdit} type="button">
        <Pencil aria-hidden className="size-4" />
      </button>
      <button aria-label={`Supprimer ${counter.label}`} className="inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground hover:text-red-400" onClick={onDelete} type="button">
        <Trash2 aria-hidden className="size-4" />
      </button>
    </li>
  );
}

type DialogProps = {
  games: CounterGame[];
  initial: AchievementCounter | null;
  onClose: () => void;
  onSaved: () => Promise<void>;
};

export function CounterDialog({ games, initial, onClose, onSaved }: DialogProps) {
  const [label, setLabel] = useState(initial?.label ?? "");
  const [gameId, setGameId] = useState(initial?.gameId ?? "");
  const [kind, setKind] = useState<CounterKind | "">(initial?.kind ?? "");
  const [mode, setMode] = useState<CounterMode>(initial?.mode ?? "total");
  const [withOthersOnly, setWithOthersOnly] = useState(initial?.withOthersOnly ?? false);
  const [targets, setTargets] = useState<string[]>(initial?.targets ?? []);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const field = "min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent";

  const valid = label.trim() !== "" && gameId !== "" && kind !== "" && targets.length > 0;

  /** Another game or kind: the names picked belong to the old one. */
  function reset(next: { gameId?: string; kind?: CounterKind | "" }): void {
    if (next.gameId !== undefined) setGameId(next.gameId);
    if (next.kind !== undefined) setKind(next.kind);
    setTargets([]);
  }

  async function submit(): Promise<void> {
    if (!valid) return;
    setSaving(true);
    setError(null);
    const payload = { label: label.trim(), gameId, kind, targets, mode, withOthersOnly };
    const result = initial ? await updateAchievementCounter(initial.id, payload) : await createAchievementCounter(payload);
    setSaving(false);
    if (!result.ok) {
      setError(result.error);
      return;
    }
    await onSaved();
  }

  return (
    <Dialog onOpenChange={(open) => (open ? null : onClose())} open title={initial ? `Modifier « ${initial.label} »` : "Nouveau compteur"}>
      <DialogBody>
        <div className="grid gap-4">
          <Field label="Libellé">
            <input className={field} maxLength={80} onChange={(e) => setLabel(e.target.value)} placeholder="Morpha vaincu" value={label} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Jeu">
              <select className={field} onChange={(e) => reset({ gameId: e.target.value })} value={gameId}>
                <option value="">Choisir un jeu</option>
                {games.map((game) => (
                  <option key={game.id} value={game.id}>
                    {game.name}
                    {game.introspected ? "" : " (liste partielle)"}
                  </option>
                ))}
              </select>
            </Field>
            <Field label="Ce qu'on compte">
              <select className={field} onChange={(e) => reset({ kind: e.target.value === "location" || e.target.value === "item" ? e.target.value : "" })} value={kind}>
                <option value="">Choisir</option>
                <option value="location">{COUNTER_KIND_LABELS.location}</option>
                <option value="item">{COUNTER_KIND_LABELS.item}</option>
              </select>
            </Field>
          </div>
          <NamePicker gameId={gameId} kind={kind} onChange={setTargets} targets={targets} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Calcul">
              <select className={field} onChange={(e) => setMode(asMode(e.target.value))} value={mode}>
                {MODES.map((m) => (
                  <option key={m} value={m}>
                    {COUNTER_MODE_LABELS[m]}
                  </option>
                ))}
              </select>
            </Field>
            <label className="flex items-center gap-2 self-end pb-2 text-sm text-foreground">
              <input checked={withOthersOnly} onChange={(e) => setWithOthersOnly(e.target.checked)} type="checkbox" />
              {kind === "item" ? "Seulement les items venus d'un autre membre" : "Seulement si l'item part chez un autre membre"}
            </label>
          </div>
          <p className="text-xs text-muted-foreground">
            Une location ne compte qu&apos;une fois par slot ; un item compte à chaque copie reçue. Les release et collect ne comptent pas. Une location n&apos;existe
            que si les options du slot l&apos;activent (Skulltulas, pêche…).
          </p>
          {error ? <p className="text-sm text-red-400">{error}</p> : null}
        </div>
      </DialogBody>
      <DialogFooter>
        <button className="min-h-10 rounded-lg border border-border px-4 text-sm text-muted-foreground hover:text-foreground" onClick={onClose} type="button">
          Annuler
        </button>
        <button
          className="min-h-10 rounded-lg border border-accent bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
          disabled={!valid || saving}
          onClick={() => void submit()}
          type="button"
        >
          Enregistrer
        </button>
      </DialogFooter>
    </Dialog>
  );
}

type PickerProps = {
  gameId: string;
  kind: CounterKind | "";
  targets: string[];
  onChange: (targets: string[]) => void;
};

/** The names of the chosen game only, searched on what the admin typed (server side), the picked ones left out. */
export function NamePicker({ gameId, kind, targets, onChange }: PickerProps) {
  const [text, setText] = useState("");
  const [query, setQuery] = useState("");
  const ready = gameId !== "" && kind !== "";

  useEffect(() => {
    const timer = setTimeout(() => setQuery(text.trim()), DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [text]);

  // Another game or kind empties the field and the search (derived state, reset while rendering).
  const scope = `${gameId}|${kind}`;
  const [lastScope, setLastScope] = useState(scope);
  if (scope !== lastScope) {
    setLastScope(scope);
    setText("");
    setQuery("");
  }

  const searching = query.length >= MIN_QUERY;
  const { data, isFetching } = useQuery({
    queryKey: ["admin-achievement-counter-names", gameId, kind, query, targets],
    queryFn: ({ signal }) => (kind === "" ? Promise.resolve(null) : fetchCounterNames(gameId, kind, query, targets, signal)),
    enabled: ready,
    staleTime: NAMES_STALE_TIME,
  });

  function add(names: string[]): void {
    onChange([...targets, ...names.filter((name) => !targets.includes(name))]);
  }

  const unknown = new Set(data?.unknown ?? []);

  return (
    <div className="grid gap-2">
      <span className="text-sm font-medium text-foreground">{kind === "item" ? "Items visés" : "Checks visés"}</span>
      {targets.length > 0 ? (
        <ul aria-label="Noms choisis" className="flex flex-wrap gap-1.5">
          {targets.map((name) => (
            <li className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs ${unknown.has(name) ? "border-amber-400/60 text-amber-300" : "border-border text-foreground"}`} key={name}>
              {name}
              {unknown.has(name) ? <span className="text-[10px]">(inconnu de l&apos;apworld)</span> : null}
              <button aria-label={`Retirer ${name}`} className="text-muted-foreground hover:text-red-400" onClick={() => onChange(targets.filter((t) => t !== name))} type="button">
                <X aria-hidden className="size-3" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      <input
        aria-label="Rechercher un nom"
        className="min-h-10 rounded-lg border border-border bg-surface px-3 text-sm text-foreground outline-none focus:border-accent disabled:opacity-50"
        disabled={!ready}
        onChange={(e) => setText(e.target.value)}
        placeholder={ready ? "Commence à écrire : water morpha, skulltula…" : "Choisis d'abord le jeu et ce qu'on compte"}
        value={text}
      />
      {ready && data?.source === "feed" ? (
        <p className="text-xs text-amber-300">Liste incomplète : apworld non introspecté, les noms viennent des parties déjà jouées.</p>
      ) : null}
      {ready && data && data.groups.length > 0 ? (
        <div className="flex flex-wrap gap-1.5">
          {data.groups.map((group) => (
            <button
              className="rounded-full border border-teal-400/45 bg-teal-400/12 px-2 py-0.5 text-xs text-teal-300 hover:border-teal-300"
              key={group.name}
              onClick={() => add(group.items)}
              type="button"
            >
              + {group.name} ({group.items.length})
            </button>
          ))}
        </div>
      ) : null}
      {ready && searching ? (
        isFetching && !data ? (
          <p className="flex items-center gap-2 text-xs text-muted-foreground">
            <Loader2 aria-hidden className="size-3 animate-spin" /> Recherche…
          </p>
        ) : data && data.names.length > 0 ? (
          <ul aria-label="Suggestions" className="grid max-h-56 gap-0.5 overflow-y-auto rounded-lg border border-border bg-background p-1">
            {data.names.map((name) => (
              <li key={name}>
                <button className="w-full rounded px-2 py-1 text-left text-sm text-foreground hover:bg-surface-2" onClick={() => add([name])} type="button">
                  {name}
                </button>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-xs text-muted-foreground">Aucun nom de ce jeu ne correspond.</p>
        )
      ) : null}
    </div>
  );
}

/** Story 41.72: what still reads a counter, achievements and weekly quests, before it can go. */
export function counterUsage(counter: AchievementCounter): string {
  const quests = counter.usedByQuests ?? 0;
  if (counter.usedBy === 0 && quests === 0) return "Aucun succès ni aucune quête ne le lit.";
  const readers = [counter.usedBy > 0 ? `${counter.usedBy} succès` : null, quests > 0 ? `${quests} ${quests === 1 ? "quête" : "quêtes"}` : null].filter((part) => part !== null);
  return `${readers.join(" et ")} ${counter.usedBy + quests === 1 ? "le lit" : "le lisent"} : retire-le de leurs conditions avant de le supprimer.`;
}

function DeleteCounterDialog({ counter, onClose, onDeleted }: { counter: AchievementCounter; onClose: () => void; onDeleted: () => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  return (
    <Dialog onOpenChange={(open) => (open ? null : onClose())} open title={`Supprimer « ${counter.label} » ?`}>
      <DialogBody>
        <p className="text-sm text-muted-foreground">
          {counterUsage(counter)}
        </p>
        {failure ? <p className="text-sm text-red-400">{failure}</p> : null}
      </DialogBody>
      <DialogFooter>
        <button className="min-h-10 rounded-lg border border-border px-4 text-sm text-muted-foreground hover:text-foreground" onClick={onClose} type="button">
          Annuler
        </button>
        <button
          className="min-h-10 rounded-lg bg-red-500 px-4 text-sm font-semibold text-white hover:bg-red-600 disabled:opacity-50"
          disabled={busy || counter.usedBy > 0 || (counter.usedByQuests ?? 0) > 0}
          onClick={async () => {
            setBusy(true);
            const outcome = await deleteAchievementCounter(counter.id);
            setBusy(false);
            if (outcome === "deleted") await onDeleted();
            else setFailure(outcome === "in_use" ? "Ce compteur est utilisé par un succès." : "La suppression a échoué.");
          }}
          type="button"
        >
          Supprimer
        </button>
      </DialogFooter>
    </Dialog>
  );
}

function asMode(value: string): CounterMode {
  return value === "max_session" || value === "distinct" ? value : "total";
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="grid gap-1.5">
      <span className="text-sm font-medium text-foreground">{label}</span>
      {children}
    </label>
  );
}
