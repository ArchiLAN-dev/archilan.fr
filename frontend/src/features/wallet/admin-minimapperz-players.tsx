"use client";

import { useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Loader2, RefreshCw, Users } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { fetchMinimapperzPlayers, type MinimapperzPlayers } from "./minimapperz-api";

const STATUS_MESSAGES: Record<Exclude<MinimapperzPlayers["status"], "ok">, string> = {
  not_configured: "Enregistre d'abord le slug et la clé.",
  unauthorized: "Minimapperz refuse la clé : vérifie la clé et le slug de l'event.",
  unreachable: "Minimapperz ne répond pas. Réessaie dans un moment.",
};

/**
 * Story 49.3: everyone who joined the LAN on Minimapperz, and the member their code belongs to. The ones linked to no
 * active registrant come first: they earn nothing automatically, the admin can still credit them by hand. Loaded on
 * demand, each load asks Minimapperz.
 */
export function AdminMinimapperzPlayers({ eventId }: { eventId: string }) {
  const [shown, setShown] = useState(false);
  const { data, isFetching, refetch } = useQuery({
    enabled: shown,
    queryKey: ["admin-minimapperz-players", eventId],
    queryFn: () => fetchMinimapperzPlayers(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return (
    <section aria-labelledby="minimapperz-players-title" className="grid gap-3 rounded-xl border border-border p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="flex items-center gap-2 font-heading text-base font-semibold text-foreground" id="minimapperz-players-title">
          <Users aria-hidden className="size-4 text-accent-text" />
          Joueurs sur Minimapperz
        </h2>
        <button
          className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-40"
          disabled={isFetching}
          onClick={() => (shown ? void refetch() : setShown(true))}
          type="button"
        >
          {isFetching ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <RefreshCw aria-hidden className="size-4" />}
          {shown ? "Actualiser" : "Voir les joueurs"}
        </button>
      </div>
      {shown ? <PlayersBody data={data} isFetching={isFetching} /> : null}
    </section>
  );
}

export function PlayersBody({ data, isFetching }: { data: MinimapperzPlayers | null | undefined; isFetching: boolean }) {
  if (data === undefined) return isFetching ? <p className="text-sm text-muted-foreground">Chargement…</p> : null;
  if (data === null) return <p className="text-sm text-danger">Liste indisponible. Réessaie.</p>;
  if (data.status !== "ok") return <p className="text-sm text-danger">{STATUS_MESSAGES[data.status]}</p>;
  if (data.players.length === 0) return <p className="text-sm text-muted-foreground">Personne n&apos;a encore rejoint la LAN.</p>;

  const unlinked = data.players.filter((player) => !player.linked).length;

  return (
    <div className="grid gap-2">
      <p className={`text-sm ${unlinked > 0 ? "text-danger" : "text-muted-foreground"}`}>
        {unlinked > 0
          ? `${unlinked} joueur${unlinked > 1 ? "s" : ""} sans code d'inscrit : aucune pelle automatique.`
          : "Tous les joueurs sont reliés à un inscrit."}
      </p>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="text-xs uppercase tracking-wide text-muted-foreground">
            <tr>
              <th className="py-2 pr-3 font-medium">Pseudo Minimapperz</th>
              <th className="py-2 pr-3 font-medium">Code</th>
              <th className="py-2 pr-3 font-medium">Membre</th>
              <th className="py-2 text-right font-medium">Parties / victoires</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {data.players.map((player) => (
              <tr key={`${player.username}-${player.code ?? ""}`}>
                <td className="py-2 pr-3 text-foreground">{player.username}</td>
                <td className="py-2 pr-3 font-mono text-muted-foreground">{player.code ?? "-"}</td>
                <td className="py-2 pr-3">
                  {player.linked ? (
                    <span className="text-foreground">{player.member}</span>
                  ) : (
                    <span className="text-danger">{player.member !== null ? `${player.member} (inscription annulée)` : "Non relié"}</span>
                  )}
                </td>
                <td className="py-2 text-right text-foreground">
                  {player.games} / {player.wins}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
