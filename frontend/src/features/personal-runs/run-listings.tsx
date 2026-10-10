"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarClock, Flag, LogIn, Megaphone } from "lucide-react";

import { Dialog } from "@/components/ui/dialog";
import { useAuth } from "@/features/auth/auth-context";
import { REPORT_PROBLEMS } from "@/features/community/community-report-api";
import { FriendIdentity } from "@/features/community/friend-identity";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { seatsLabel } from "./friends-open-runs";
import { FRIENDS_OPEN_RUNS_KEY, joinOpenRun } from "./friends-open-runs-api";
import { fetchRunListings, reportRunListing, RUN_LISTINGS_KEY, type ListingReportResult, type RunListing } from "./run-listings-api";

const DATE = new Intl.DateTimeFormat("fr-FR", { weekday: "long", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" });

/** « Prévue le samedi 2 novembre à 20:00 », or null without a date. */
export function plannedLabel(plannedFor: string | null): string | null {
  if (plannedFor === null) return null;
  const date = new Date(plannedFor);
  return Number.isNaN(date.getTime()) ? null : `Prévue le ${DATE.format(date)}`;
}

/** « Alice et Bob y sont déjà », the viewer's friends already in the run. */
export function friendsInLabel(friends: RunListing["friendsIn"]): string | null {
  const names = friends.map((friend) => friend.displayName ?? friend.slug);
  if (names.length === 0) return null;
  if (names.length === 1) return `${names[0]} y est déjà`;
  return `${names.slice(0, -1).join(", ")} et ${names[names.length - 1]} y sont déjà`;
}

/**
 * « Parties qui cherchent des joueurs » (story 43.17): the runs listed for every member, the latest first, the
 * viewer's friends already in them put forward. Signed-in members only.
 */
export function RunListingsBoard() {
  const { user, loading } = useAuth();
  const { data, isLoading } = useQuery({
    queryKey: RUN_LISTINGS_KEY,
    queryFn: fetchRunListings,
    enabled: user !== null,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (loading) return null;
  if (user === null) {
    return (
      <p className="text-sm text-muted-foreground">
        <Link className="font-medium text-accent-text hover:underline" href="/connexion">
          Connecte-toi
        </Link>{" "}
        pour voir les parties qui cherchent des joueurs.
      </p>
    );
  }
  if (isLoading) return <p className="text-sm text-muted-foreground">Chargement des annonces…</p>;
  if (!data) return <p className="text-sm text-muted-foreground">Impossible de charger les annonces.</p>;
  if (data.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        Aucune partie ne cherche de joueurs pour l&apos;instant. Publie la tienne depuis{" "}
        <Link className="font-medium text-accent-text hover:underline" href="/compte/parties">
          Mes parties
        </Link>{" "}
        : réglage « Ouverture », « Tous les membres ».
      </p>
    );
  }

  return (
    <ul className="grid gap-3" role="list">
      {data.map((listing) => (
        <ListingCard key={listing.runId} listing={listing} />
      ))}
    </ul>
  );
}

function ListingCard({ listing }: { listing: RunListing }) {
  const router = useRouter();
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [reporting, setReporting] = useState(false);
  const planned = plannedLabel(listing.plannedFor);
  const friendsIn = friendsInLabel(listing.friendsIn);

  async function join() {
    setBusy(true);
    setMessage(null);
    const result = await joinOpenRun(listing.runId);
    setBusy(false);
    // Story 43.19: a listed run is in « Parties de tes amis » as well.
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: RUN_LISTINGS_KEY }),
      queryClient.invalidateQueries({ queryKey: FRIENDS_OPEN_RUNS_KEY }),
    ]);
    if (!result.ok) {
      setMessage(result.message);
      return;
    }
    router.push(`/runs/${listing.runId}`);
  }

  return (
    <li className="grid gap-2 rounded-lg border border-border bg-surface px-4 py-3">
      <div className="flex flex-wrap items-center gap-3">
        <FriendIdentity card={listing.owner} link />
        {listing.isOwnerFriend ? <span className="rounded-full bg-accent/15 px-2 py-0.5 text-xs text-accent-text">Ami</span> : null}
        <span className="ml-auto text-xs text-muted-foreground">{seatsLabel(listing)}</span>
      </div>
      <p className="font-semibold text-foreground">« {listing.title} »</p>
      <p className="whitespace-pre-line text-sm text-foreground">{listing.pitch}</p>
      {listing.games.length > 0 ? <p className="text-xs text-muted-foreground">Jeux : {listing.games.join(", ")}</p> : null}
      {planned !== null ? (
        <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
          <CalendarClock aria-hidden className="size-3.5" />
          {planned}
        </p>
      ) : null}
      {friendsIn !== null ? <p className="text-xs font-medium text-accent-text">{friendsIn}</p> : null}
      <div className="flex flex-wrap items-center gap-2">
        <button
          className="inline-flex items-center gap-1.5 rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
          disabled={busy}
          onClick={() => void join()}
          type="button"
        >
          <LogIn aria-hidden className="size-3.5" />
          Rejoindre
        </button>
        <button
          className="ml-auto inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
          onClick={() => setReporting(true)}
          type="button"
        >
          <Flag aria-hidden className="size-3.5" />
          Signaler
        </button>
      </div>
      {message !== null ? (
        <p className="text-sm text-danger" role="alert">
          {message}
        </p>
      ) : null}
      <Dialog description="La modération reçoit l'annonce telle qu'elle est." onOpenChange={setReporting} open={reporting} title="Signaler l'annonce">
        <ListingReportForm onDone={() => setReporting(false)} runId={listing.runId} />
      </Dialog>
    </li>
  );
}

const REPORT_ERRORS: Record<Exclude<ListingReportResult, "ok">, string> = {
  forbidden: "Tu ne peux pas signaler ta propre annonce.",
  invalid: "Choisis un contenu problématique.",
  not_found: "Cette annonce n'est plus en ligne.",
  error: "Le signalement n'a pas pu être envoyé. Réessaie.",
};

function ListingReportForm({ runId, onDone }: { runId: string; onDone: () => void }) {
  const [problem, setProblem] = useState<string>(REPORT_PROBLEMS[0].value);
  const [comment, setComment] = useState("");
  const [state, setState] = useState<"form" | "sending" | "sent">("form");
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setState("sending");
    const result = await reportRunListing(runId, problem, comment);
    if (result === "ok") {
      setState("sent");
      return;
    }
    setState("form");
    setError(REPORT_ERRORS[result]);
  }

  if (state === "sent") {
    return (
      <div className="grid gap-3 px-5 py-4">
        <p className="text-sm text-foreground">Merci, le signalement a bien été transmis à la modération.</p>
        <button className="ml-auto rounded bg-accent px-3 py-1.5 text-sm font-semibold text-white hover:bg-accent-hover" onClick={onDone} type="button">
          Fermer
        </button>
      </div>
    );
  }

  return (
    <div className="grid gap-3 px-5 py-4">
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Contenu problématique</span>
        <select className="min-h-9 rounded border border-border bg-background px-2 text-sm text-foreground" onChange={(e) => setProblem(e.target.value)} value={problem}>
          {REPORT_PROBLEMS.map((p) => (
            <option key={p.value} value={p.value}>
              {p.label}
            </option>
          ))}
        </select>
      </label>
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Précisions (facultatif)</span>
        <textarea className="min-h-16 rounded border border-border bg-background px-2 py-1.5 text-sm text-foreground" maxLength={500} onChange={(e) => setComment(e.target.value)} value={comment} />
      </label>
      {error !== null ? (
        <p className="text-sm text-danger" role="alert">
          {error}
        </p>
      ) : null}
      <button
        className="inline-flex items-center justify-center gap-2 rounded bg-accent px-3 py-2 text-sm font-semibold text-white hover:bg-accent-hover disabled:opacity-50"
        disabled={state === "sending"}
        onClick={() => void submit()}
        type="button"
      >
        <Flag aria-hidden className="size-4" />
        Envoyer le signalement
      </button>
    </div>
  );
}

/** The way in from « Communauté » (story 43.17). */
export function RunListingsLink() {
  return (
    <Link
      className="inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-sm font-semibold text-foreground transition-colors hover:border-accent"
      href="/communaute/parties"
    >
      <Megaphone aria-hidden className="size-4 text-accent-text" />
      Parties qui cherchent des joueurs
    </Link>
  );
}
