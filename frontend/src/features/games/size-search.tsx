"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Loader2, Wand2 } from "lucide-react";

import type { YamlTestContext } from "./game-yaml-test-api";
import { getSizeSearch, startSizeSearch, type SizeSearchState } from "./size-search-api";

/** Story 56.7: polled while the search generates, quiet once it is over. */
const RUNNING_REFRESH_MS = 4_000;

function formatHours(hours: number): string {
  return `${new Intl.NumberFormat("fr-FR", { maximumFractionDigits: hours < 10 ? 1 : 0 }).format(hours)} h`;
}

type Props = {
  gameId: string;
  context: YamlTestContext;
  /** The YAML shown in the editor, read when the player asks. */
  currentYaml: () => string;
  /** The label of an option, as the editor shows it. */
  labelOf: (key: string) => string;
  /** Sets the option in the editor; the player still saves it themselves. */
  onApply: (option: string, value: number) => void;
};

/**
 * Story 56.7: past the target, the player asks for a size option value that fits it. The search runs on the server,
 * a generation at a time; its proposal is applied to the editor only on the player's click.
 */
export function SizeSearchPanel({ gameId, context, currentYaml, labelOf, onApply }: Props) {
  const [searchId, setSearchId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [dismissed, setDismissed] = useState(false);
  const { data } = useQuery({
    queryKey: ["size-search", gameId, searchId],
    queryFn: () => (searchId === null ? null : getSizeSearch(gameId, searchId)),
    enabled: searchId !== null,
    staleTime: 0,
    refetchInterval: (query) => (query.state.data?.status === "running" ? RUNNING_REFRESH_MS : false),
    retry: false,
  });

  async function start() {
    setBusy(true);
    setError(null);
    setDismissed(false);
    const result = await startSizeSearch(gameId, currentYaml(), context);
    setBusy(false);
    if (result.ok) {
      setSearchId(result.searchId);
    } else {
      setError(result.message);
    }
  }

  if (dismissed) return null;

  return (
    <div className="grid gap-2 rounded-md border border-border bg-surface p-3 text-sm">
      {searchId === null || data === null ? (
        <div className="flex flex-wrap items-center gap-2">
          <button
            className="inline-flex min-h-9 items-center gap-1.5 rounded-md border border-border bg-background px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-50"
            disabled={busy}
            onClick={() => void start()}
            type="button"
          >
            <Wand2 aria-hidden className="size-4" />
            Trouver un réglage plus court
          </button>
          <span className="text-xs text-muted-foreground">Quelques générations de test, comptées dans ta limite de tests.</span>
        </div>
      ) : data === undefined ? (
        <p className="text-muted-foreground">Recherche lancée…</p>
      ) : (
        <SizeSearchView
          labelOf={labelOf}
          onApply={(option, value) => {
            onApply(option, value);
            setDismissed(true);
          }}
          onDismiss={() => setDismissed(true)}
          search={data}
        />
      )}
      {error !== null && <p className="text-danger">{error}</p>}
    </div>
  );
}

type ViewProps = {
  search: SizeSearchState;
  labelOf: (key: string) => string;
  onApply: (option: string, value: number) => void;
  onDismiss: () => void;
};

/** Story 56.7: where a search stands, and its proposal. Pure. */
export function SizeSearchView({ search, labelOf, onApply, onDismiss }: ViewProps) {
  if (search.status === "running") {
    return (
      <p className="inline-flex items-center gap-1.5 text-muted-foreground" role="status">
        <Loader2 aria-hidden className="size-4 animate-spin" />
        Recherche d&apos;un réglage : essai {Math.min(search.tries.length + 1, search.maxTries)} sur {search.maxTries} au plus.
      </p>
    );
  }
  if (search.status === "found" && search.proposal !== null) {
    const { option, from, value, hours } = search.proposal;
    return (
      <div className="grid gap-2" role="status">
        <p className="text-foreground">
          Avec <span className="font-semibold">{labelOf(option)}</span> à {value} au lieu de {from} : environ {formatHours(hours)} (cible{" "}
          {formatHours(search.targetHours)}).
        </p>
        <div className="flex flex-wrap gap-2">
          <button
            className="inline-flex min-h-9 items-center rounded-md bg-accent px-3 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            onClick={() => onApply(option, value)}
            type="button"
          >
            Appliquer à mon YAML
          </button>
          <button
            className="inline-flex min-h-9 items-center rounded-md border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            onClick={onDismiss}
            type="button"
          >
            Ignorer
          </button>
        </div>
      </div>
    );
  }
  if (search.status === "failed") {
    return <p className="text-danger">{search.error ?? "La recherche s'est arrêtée."}</p>;
  }
  return (
    <p className="text-muted-foreground" role="status">
      Pas de réglage trouvé en {search.tries.length} génération{search.tries.length > 1 ? "s" : ""} : essaie un format plus court ou réduis
      les objectifs à la main.
    </p>
  );
}
